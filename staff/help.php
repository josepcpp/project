<?php
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id = intval($_SESSION['user_id'] ?? 0);
$_role   = strtolower($_SESSION['role'] ?? 'staff');
$role    = $_role;
$_uname  = $_SESSION['username'] ?? 'Unknown';

if (!$user_id) { header("Location: /project/auth/login.php"); exit(); }

// ── POST ACTIONS ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // B1: staff-only gate
    if ($action === 'create_ticket') {
        if ($_role !== ROLE_STAFF) { header("Location: help.php"); exit(); }
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        // B4: length guard
        if (mb_strlen($subject) > 255 || mb_strlen($message) > 5000) {
            header("Location: help.php?error=" . urlencode("Input too long (subject ≤255 chars, message ≤5000 chars)."));
            exit();
        }
        if ($subject !== '' && $message !== '') {
            $st = $conn->prepare("INSERT INTO support_tickets (user_id, username, subject) VALUES (?, ?, ?)");
            $st->bind_param("iss", $user_id, $_uname, $subject);
            $st->execute();
            $tid = $conn->insert_id;
            $sm = $conn->prepare("INSERT INTO support_messages (ticket_id, sender_id, sender_username, sender_role, message) VALUES (?, ?, ?, ?, ?)");
            $sm->bind_param("iisss", $tid, $user_id, $_uname, $_role, $message);
            $sm->execute();
            // G3: stamp activity time
            $ua = $conn->prepare("UPDATE support_tickets SET updated_at = NOW() WHERE id = ?");
            $ua->bind_param("i", $tid); $ua->execute();
            header("Location: help.php?ticket_id={$tid}&success=" . urlencode("Your support query has been submitted."));
        } else {
            header("Location: help.php?error=" . urlencode("Subject and message are required."));
        }
        exit();
    }

    if ($action === 'send_reply') {
        $tid  = intval($_POST['ticket_id'] ?? 0);
        $msg  = trim($_POST['message'] ?? '');
        $ajax = !empty($_SERVER['HTTP_X_SUPPORT_AJAX']);
        // B4: length guard
        if (mb_strlen($msg) > 5000) {
            if ($ajax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Message too long (max 5000 characters).']); exit(); }
            $ep = $tid > 0 ? "help.php?ticket_id={$tid}" : "help.php";
            header("Location: {$ep}&error=" . urlencode("Message too long (max 5000 characters)."));
            exit();
        }
        if ($tid > 0 && $msg !== '') {
            if ($_role === ROLE_STAFF) {
                $chk = $conn->prepare("SELECT id, status FROM support_tickets WHERE id = ? AND user_id = ?");
                $chk->bind_param("ii", $tid, $user_id);
            } else {
                $chk = $conn->prepare("SELECT id, status FROM support_tickets WHERE id = ?");
                $chk->bind_param("i", $tid);
            }
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            if ($row && $row['status'] !== TICKET_RESOLVED) {
                $sm = $conn->prepare("INSERT INTO support_messages (ticket_id, sender_id, sender_username, sender_role, message) VALUES (?, ?, ?, ?, ?)");
                $sm->bind_param("iisss", $tid, $user_id, $_uname, $_role, $msg);
                $sm->execute();
                $msg_id     = $conn->insert_id;
                $new_status = $row['status'];
                // S1: prepared statement for status update
                if (in_array($_role, ROLES_ADMIN_AND_UP) && $row['status'] === TICKET_OPEN) {
                    $upd = $conn->prepare("UPDATE support_tickets SET status='" . TICKET_IN_PROGRESS . "' WHERE id=?");
                    $upd->bind_param("i", $tid); $upd->execute();
                    $new_status = TICKET_IN_PROGRESS;
                }
                // G3: stamp activity time
                $ua = $conn->prepare("UPDATE support_tickets SET updated_at = NOW() WHERE id = ?");
                $ua->bind_param("i", $tid); $ua->execute();
                if ($ajax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success'    => true,
                        'new_status' => $new_status,
                        'message'    => [
                            'message'  => $msg,
                            'time'     => date("M d, Y · g:i A"),
                            'role'     => $_role,
                            'username' => $_uname,
                        ],
                    ]);
                    exit();
                }
                header("Location: help.php?ticket_id={$tid}");
                exit();
            }
        }
        if ($ajax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Could not send reply.']); exit(); }
        // B2: correct redirect when tid may be 0
        $ep = $tid > 0 ? "help.php?ticket_id={$tid}" : "help.php";
        header("Location: {$ep}&error=" . urlencode("Could not send reply."));
        exit();
    }

    if ($action === 'resolve_ticket' && in_array($_role, ROLES_ADMIN_AND_UP)) {
        $tid = intval($_POST['ticket_id'] ?? 0);
        if ($tid > 0) {
            // S1: prepared statement + G3: stamp activity
            $upd = $conn->prepare("UPDATE support_tickets SET status='" . TICKET_RESOLVED . "', resolved_at=NOW(), updated_at=NOW() WHERE id=?");
            $upd->bind_param("i", $tid); $upd->execute();
            header("Location: help.php?ticket_id={$tid}&success=" . urlencode("Ticket marked as resolved."));
            exit();
        }
    }

    if ($action === 'reopen_ticket' && in_array($_role, ROLES_ADMIN_AND_UP)) {
        $tid = intval($_POST['ticket_id'] ?? 0);
        if ($tid > 0) {
            // S1: prepared statement + G3: stamp activity
            $upd = $conn->prepare("UPDATE support_tickets SET status='" . TICKET_OPEN . "', resolved_at=NULL, updated_at=NOW() WHERE id=?");
            $upd->bind_param("i", $tid); $upd->execute();
            header("Location: help.php?ticket_id={$tid}&success=" . urlencode("Ticket reopened."));
            exit();
        }
    }

    // U2: staff closes their own ticket
    if ($action === 'close_own_ticket' && $_role === ROLE_STAFF) {
        $tid = intval($_POST['ticket_id'] ?? 0);
        if ($tid > 0) {
            $upd = $conn->prepare("UPDATE support_tickets SET status='" . TICKET_RESOLVED . "', resolved_at=NOW(), updated_at=NOW() WHERE id=? AND user_id=?");
            $upd->bind_param("ii", $tid, $user_id); $upd->execute();
            header("Location: help.php?success=" . urlencode("Ticket closed."));
            exit();
        }
    }

    header("Location: help.php");
    exit();
}

include 'layout_top.php';

// ── DATA ──────────────────────────────────────────────────────────────────────
$sel_id        = intval($_GET['ticket_id'] ?? 0);
$filter_status = $_GET['filter'] ?? 'all';

// Build safe filter WHERE clause (match prevents injection)
$filter_where = match($filter_status) {
    TICKET_OPEN        => "AND st.status = '" . TICKET_OPEN . "'",
    TICKET_IN_PROGRESS => "AND st.status = '" . TICKET_IN_PROGRESS . "'",
    TICKET_RESOLVED    => "AND st.status = '" . TICKET_RESOLVED . "'",
    default            => '',
};

// U3: include message count; G3/G4: sort by last activity
if ($role === ROLE_STAFF) {
    $tq = $conn->prepare("
        SELECT st.*,
            (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id = st.id) AS msg_count
        FROM support_tickets st
        WHERE st.user_id = ? {$filter_where}
        ORDER BY FIELD(st.status,'open','in_progress','resolved'),
                 COALESCE(st.updated_at, st.created_at) DESC
    ");
    $tq->bind_param("i", $user_id);
    $tq->execute();
    $tres = $tq->get_result();
} else {
    $tres = $conn->query("
        SELECT st.*,
            (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id = st.id) AS msg_count
        FROM support_tickets st
        WHERE 1=1 {$filter_where}
        ORDER BY FIELD(st.status,'open','in_progress','resolved'),
                 COALESCE(st.updated_at, st.created_at) DESC
    ");
}
$tickets = [];
while ($t = $tres->fetch_assoc()) $tickets[] = $t;

// Status counts for filter tab labels
$status_counts = ['all' => 0, TICKET_OPEN => 0, TICKET_IN_PROGRESS => 0, TICKET_RESOLVED => 0];
if ($role === ROLE_STAFF) {
    $cq = $conn->prepare("SELECT status, COUNT(*) AS c FROM support_tickets WHERE user_id = ? GROUP BY status");
    $cq->bind_param("i", $user_id); $cq->execute();
    $cres = $cq->get_result();
} else {
    $cres = $conn->query("SELECT status, COUNT(*) AS c FROM support_tickets GROUP BY status");
}
while ($cr = $cres->fetch_assoc()) {
    $status_counts[$cr['status']] = intval($cr['c']);
    $status_counts['all'] += intval($cr['c']);
}

// Selected ticket + messages
$sel  = null;
$msgs = [];
if ($sel_id > 0) {
    if ($role === ROLE_STAFF) {
        $sq = $conn->prepare("SELECT * FROM support_tickets WHERE id = ? AND user_id = ?");
        $sq->bind_param("ii", $sel_id, $user_id);
    } else {
        $sq = $conn->prepare("SELECT * FROM support_tickets WHERE id = ?");
        $sq->bind_param("i", $sel_id);
    }
    $sq->execute();
    $sel = $sq->get_result()->fetch_assoc();
    if ($sel) {
        $mq = $conn->prepare("SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
        $mq->bind_param("i", $sel_id); $mq->execute();
        $mr = $mq->get_result();
        while ($m = $mr->fetch_assoc()) $msgs[] = $m;
    }
}

function support_badge(string $s): string {
    return match($s) {
        TICKET_OPEN        => 'bg-emerald-50 text-emerald-600 border-emerald-100',
        TICKET_IN_PROGRESS => 'bg-blue-50 text-blue-600 border-blue-100',
        default            => 'bg-slate-100 text-slate-400 border-slate-100',
    };
}
function support_label(string $s): string {
    return match($s) {
        TICKET_OPEN        => 'Open',
        TICKET_IN_PROGRESS => 'In Progress',
        TICKET_RESOLVED    => 'Resolved',
        default            => ucfirst($s),
    };
}
?>

<div class="max-w-7xl mx-auto animate-in pb-20">
    <div class="flex gap-5" style="min-height: 72vh;">

        <!-- ── LEFT: Ticket list ───────────────────────────────────────────── -->
        <div class="w-[310px] flex-shrink-0 flex flex-col gap-3">

            <!-- Panel header -->
            <div class="bg-white rounded-[1.5rem] border border-slate-100 shadow-sm p-5 flex items-center justify-between">
                <div>
                    <h3 class="font-black text-slate-800 text-sm">
                        <?= $role === ROLE_STAFF ? 'My Queries' : 'Support Inbox' ?>
                    </h3>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">
                        <?= $status_counts['all'] ?> ticket<?= $status_counts['all'] !== 1 ? 's' : '' ?>
                    </p>
                </div>
                <?php if ($role === ROLE_STAFF): ?>
                <button onclick="openNewTicket()"
                        class="bg-slate-900 text-white px-4 py-2 rounded-xl text-[11px] font-black hover:bg-slate-700 transition-all flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                    </svg>
                    New Query
                </button>
                <?php endif; ?>
            </div>

            <!-- U4: Status filter tabs -->
            <?php $tab_defs = [
                'all'              => 'All',
                TICKET_OPEN        => 'Open',
                TICKET_IN_PROGRESS => 'Active',
                TICKET_RESOLVED    => 'Resolved',
            ]; ?>
            <div class="bg-white rounded-[1.25rem] border border-slate-100 shadow-sm p-1.5 flex gap-1">
                <?php foreach ($tab_defs as $key => $label):
                    $is_tab = $filter_status === $key;
                    $cnt    = $status_counts[$key] ?? 0;
                ?>
                <a href="help.php?filter=<?= $key ?>"
                   class="flex-1 text-center py-1.5 px-1 rounded-lg text-[9px] font-black uppercase tracking-wide transition-all
                          <?= $is_tab ? 'bg-slate-900 text-white' : 'text-slate-400 hover:bg-slate-50' ?>">
                    <?= $label ?>
                    <?php if ($key !== 'all' && $cnt > 0): ?>
                    <span class="<?= $is_tab ? 'opacity-60' : 'opacity-50' ?>">(<?= $cnt ?>)</span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Ticket cards -->
            <div class="flex flex-col gap-2 overflow-y-auto flex-1" style="max-height: 55vh;">
                <?php foreach ($tickets as $t):
                    $active = $sel_id === intval($t['id']);
                    $bc     = support_badge($t['status']);
                    $bl     = support_label($t['status']);
                    $mc     = intval($t['msg_count'] ?? 0);
                    $ts     = $t['updated_at'] ?? $t['created_at'];
                ?>
                <a href="help.php?filter=<?= urlencode($filter_status) ?>&ticket_id=<?= $t['id'] ?>"
                   class="bg-white rounded-[1.25rem] border p-4 transition-all hover:shadow-sm flex flex-col gap-1.5
                          <?= $active ? 'border-emerald-200 bg-emerald-50/20 shadow-sm' : 'border-slate-100 hover:border-slate-200' ?>">
                    <div class="flex items-start justify-between gap-2">
                        <p class="font-black text-slate-800 text-[12px] leading-snug flex-1 line-clamp-2"><?= htmlspecialchars($t['subject']) ?></p>
                        <span class="px-2.5 py-0.5 rounded-full text-[8px] font-black uppercase tracking-wide border flex-shrink-0 <?= $bc ?>"><?= $bl ?></span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <?php if ($role !== ROLE_STAFF && !empty($t['username'])): ?>
                        <p class="text-[10px] text-slate-500 font-bold truncate">@<?= htmlspecialchars($t['username']) ?></p>
                        <?php else: ?>
                        <span></span>
                        <?php endif; ?>
                        <?php if ($mc > 0): ?>
                        <span class="text-[9px] text-slate-300 font-bold flex-shrink-0"><?= $mc ?> msg<?= $mc !== 1 ? 's' : '' ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="text-[9px] text-slate-300 font-bold"><?= date("M d, Y · g:i A", strtotime($ts)) ?></p>
                </a>
                <?php endforeach; ?>

                <?php if (empty($tickets)): ?>
                <div class="bg-white rounded-[1.25rem] border border-slate-50 p-10 text-center">
                    <svg class="w-8 h-8 text-slate-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0H4"/>
                    </svg>
                    <p class="text-slate-300 font-black text-xs italic">
                        <?= $filter_status !== 'all'
                            ? 'No ' . support_label($filter_status) . ' tickets.'
                            : 'No tickets yet.' ?>
                    </p>
                    <?php if ($role === ROLE_STAFF && $filter_status === 'all'): ?>
                    <p class="text-slate-300 text-[10px] mt-1 font-bold">Click "New Query" to get started.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── RIGHT: Thread / Empty state ────────────────────────────────── -->
        <div class="flex-1 flex flex-col gap-3 min-w-0">
            <?php if ($sel):
                $sel_badge   = support_badge($sel['status']);
                $sel_label   = support_label($sel['status']);
                $is_resolved = $sel['status'] === TICKET_RESOLVED;
                $can_manage  = in_array($role, ROLES_ADMIN_AND_UP);
                $owns_ticket = $role === ROLE_STAFF && intval($sel['user_id']) === $user_id;
            ?>

            <!-- Ticket Header -->
            <div class="bg-white rounded-[1.5rem] border border-slate-100 shadow-sm p-5 flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="font-black text-slate-900 text-base truncate"><?= htmlspecialchars($sel['subject']) ?></h3>
                    <div class="flex items-center flex-wrap gap-2 mt-1.5">
                        <span id="sel-status-badge" class="px-2.5 py-0.5 rounded-full text-[8px] font-black uppercase tracking-wide border <?= $sel_badge ?>"><?= $sel_label ?></span>
                        <span class="text-[9px] text-slate-300 font-bold">Ticket #<?= $sel['id'] ?></span>
                        <?php if ($can_manage && !empty($sel['username'])): ?>
                        <span class="text-[9px] text-slate-400 font-bold">· @<?= htmlspecialchars($sel['username']) ?></span>
                        <?php endif; ?>
                        <?php if ($sel['resolved_at']): ?>
                        <span class="text-[9px] text-slate-300 font-bold">· Resolved <?= date("M d, Y", strtotime($sel['resolved_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex gap-2 flex-shrink-0">
                    <?php if ($can_manage): ?>
                        <?php if (!$is_resolved): ?>
                        <form method="POST" action="help.php">
                            <input type="hidden" name="action" value="resolve_ticket">
                            <input type="hidden" name="ticket_id" value="<?= $sel['id'] ?>">
                            <button type="submit" class="bg-emerald-500 text-white px-4 py-2 rounded-xl text-[11px] font-black hover:bg-emerald-600 transition-all">
                                Mark Resolved
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="POST" action="help.php">
                            <input type="hidden" name="action" value="reopen_ticket">
                            <input type="hidden" name="ticket_id" value="<?= $sel['id'] ?>">
                            <button type="submit" class="bg-amber-500 text-white px-4 py-2 rounded-xl text-[11px] font-black hover:bg-amber-600 transition-all">
                                Reopen Ticket
                            </button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    <!-- U2: staff can close their own ticket -->
                    <?php if ($owns_ticket && !$is_resolved): ?>
                    <form method="POST" action="help.php"
                          onsubmit="confirmForm(event, this, 'Mark this ticket as resolved? You won\'t be able to reply after closing.', 'Close Ticket')">
                        <input type="hidden" name="action" value="close_own_ticket">
                        <input type="hidden" name="ticket_id" value="<?= $sel['id'] ?>">
                        <button type="submit"
                                class="border border-slate-200 text-slate-500 px-4 py-2 rounded-xl text-[11px] font-black hover:bg-slate-50 transition-all">
                            Close
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Message Thread -->
            <div id="message-thread"
                 class="flex-1 bg-white rounded-[1.5rem] border border-slate-100 shadow-sm p-6 overflow-y-auto space-y-5"
                 style="max-height: 48vh;">
                <?php if (empty($msgs)): ?>
                <div class="flex items-center justify-center h-full py-10">
                    <p class="text-slate-300 font-bold text-sm italic">No messages yet.</p>
                </div>
                <?php endif; ?>

                <?php foreach ($msgs as $m):
                    $is_mine      = intval($m['sender_id']) === $user_id;
                    $is_admin_msg = in_array($m['sender_role'], ROLES_ADMIN_AND_UP);
                ?>
                <div class="flex <?= $is_mine ? 'justify-end' : 'justify-start' ?>">
                    <div class="max-w-[72%] flex flex-col <?= $is_mine ? 'items-end' : 'items-start' ?>">
                        <?php if (!$is_mine): ?>
                        <div class="flex items-center gap-2 mb-1.5">
                            <div class="w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-black
                                        <?= $is_admin_msg ? 'bg-purple-100 text-purple-600' : 'bg-slate-100 text-slate-500' ?>">
                                <?= strtoupper(substr($m['sender_username'] ?? '?', 0, 1)) ?>
                            </div>
                            <p class="text-[10px] text-slate-500 font-bold"><?= htmlspecialchars($m['sender_username'] ?? '') ?></p>
                            <?php if ($is_admin_msg): ?>
                            <span class="bg-purple-50 text-purple-500 border border-purple-100 px-1.5 py-0.5 rounded text-[8px] font-black">
                                <?= $m['sender_role'] === ROLE_SUPERADMIN ? 'Superadmin' : ($m['sender_role'] === ROLE_OWNER ? 'Owner' : 'Admin') ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="px-4 py-3 rounded-2xl text-[13px] leading-relaxed
                            <?= $is_mine
                                ? 'bg-slate-900 text-white rounded-tr-sm'
                                : ($is_admin_msg
                                    ? 'bg-purple-50 text-slate-700 border border-purple-100 rounded-tl-sm'
                                    : 'bg-slate-50 text-slate-700 border border-slate-100 rounded-tl-sm') ?>">
                            <?= nl2br(htmlspecialchars($m['message'])) ?>
                        </div>
                        <p class="text-[9px] text-slate-300 font-bold mt-1.5 <?= $is_mine ? 'pr-0.5' : 'pl-0.5' ?>">
                            <?= date("M d, Y · g:i A", strtotime($m['created_at'])) ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
                <div id="thread-bottom"></div>
            </div>

            <!-- Reply Form or Resolved Banner -->
            <?php if (!$is_resolved): ?>
            <form id="reply-form" method="POST" action="help.php" class="bg-white rounded-[1.5rem] border border-slate-100 shadow-sm p-4 flex items-end gap-3">
                <input type="hidden" name="action" value="send_reply">
                <input type="hidden" name="ticket_id" value="<?= $sel['id'] ?>">
                <textarea name="message" rows="2" required maxlength="5000"
                          class="flex-1 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-transparent resize-none transition-all"
                          placeholder="Type your reply..."></textarea>
                <button type="submit"
                        class="bg-slate-900 text-white px-6 py-3 rounded-xl font-black text-[11px] hover:bg-slate-700 transition-all flex-shrink-0 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                    Send
                </button>
            </form>
            <?php else: ?>
            <div class="bg-slate-50 rounded-[1.5rem] border border-slate-100 p-5 text-center">
                <p class="text-slate-400 font-black text-xs uppercase tracking-widest">Ticket Resolved</p>
                <p class="text-slate-300 text-[10px] font-bold mt-1">
                    <?= $can_manage
                        ? 'Click "Reopen Ticket" above to continue the conversation.'
                        : 'This ticket has been closed. Contact an admin to reopen it.' ?>
                </p>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- Empty State -->
            <div class="flex-1 bg-white rounded-[1.5rem] border border-slate-50 shadow-sm flex flex-col items-center justify-center py-24 text-center px-8">
                <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center mb-5">
                    <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                    </svg>
                </div>
                <p class="font-black text-slate-300 text-sm mb-1">
                    <?= $role === ROLE_STAFF ? 'No ticket selected' : 'Select a ticket to view the conversation' ?>
                </p>
                <p class="text-[10px] text-slate-300 font-bold">
                    <?= $role === ROLE_STAFF
                        ? 'Click "New Query" to submit a support request, or select a ticket from the list.'
                        : 'Open tickets appear at the top of the list.' ?>
                </p>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php if ($role === ROLE_STAFF): ?>
<div id="new-ticket-modal"
     class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-[200] flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl p-8 w-full max-w-lg shadow-2xl relative">
        <!-- U1: × close button -->
        <button type="button" onclick="closeNewTicket()"
                class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-all">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
        <div class="mb-6">
            <h3 class="font-black text-slate-900 text-lg">New Support Query</h3>
            <p class="text-slate-400 text-xs font-bold mt-1">Describe your issue and our team will respond shortly.</p>
        </div>
        <form method="POST" action="help.php">
            <input type="hidden" name="action" value="create_ticket">
            <div class="mb-4">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Subject</label>
                <input type="text" id="nt-subject" name="subject" required maxlength="255"
                       class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-transparent"
                       placeholder="Brief description of your issue">
            </div>
            <div class="mb-6">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">
                    Message
                    <span id="nt-char-count" class="normal-case font-bold text-slate-300 ml-1"></span>
                </label>
                <textarea id="nt-message" name="message" required maxlength="5000" rows="4"
                          oninput="document.getElementById('nt-char-count').textContent = this.value.length + '/5000'"
                          class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-transparent resize-none"
                          placeholder="Explain your issue in detail..."></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeNewTicket()"
                        class="flex-1 border border-slate-200 text-slate-600 py-3 rounded-xl font-black text-xs hover:bg-slate-50 transition-all">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 bg-slate-900 text-white py-3 rounded-xl font-black text-xs hover:bg-slate-700 transition-all">
                    Submit Query
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
@keyframes bubbleIn {
    from { opacity: 0; transform: translateY(8px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0)   scale(1);    }
}
.bubble-new { animation: bubbleIn 0.18s cubic-bezier(.4,0,.2,1) forwards; }
</style>
<script>
function openNewTicket() {
    document.getElementById('new-ticket-modal').classList.remove('hidden');
    setTimeout(() => document.getElementById('nt-subject')?.focus(), 50);
}
function closeNewTicket() {
    document.getElementById('new-ticket-modal').classList.add('hidden');
}
document.getElementById('new-ticket-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeNewTicket();
});

const _thread = document.getElementById('message-thread');
if (_thread) _thread.scrollTop = _thread.scrollHeight;

function _esc(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/\n/g,'<br>');
}

function _buildBubble(msg) {
    const outer = document.createElement('div');
    outer.className = 'flex justify-end bubble-new';

    const inner = document.createElement('div');
    inner.className = 'max-w-[72%] flex flex-col items-end';

    const bubble = document.createElement('div');
    bubble.className = 'px-4 py-3 rounded-2xl text-[13px] leading-relaxed bg-slate-900 text-white rounded-tr-sm';
    bubble.innerHTML = _esc(msg.message);

    const ts = document.createElement('p');
    ts.className = 'text-[9px] text-slate-300 font-bold mt-1.5 pr-0.5';
    ts.textContent = msg.time;

    inner.appendChild(bubble);
    inner.appendChild(ts);
    outer.appendChild(inner);
    return outer;
}

function _updateStatusBadge(newStatus) {
    const el = document.getElementById('sel-status-badge');
    if (!el) return;
    const map = {
        open:        { cls: 'bg-emerald-50 text-emerald-600 border-emerald-100', label: 'Open' },
        in_progress: { cls: 'bg-blue-50 text-blue-600 border-blue-100',          label: 'In Progress' },
        resolved:    { cls: 'bg-slate-100 text-slate-400 border-slate-100',       label: 'Resolved' },
    };
    const def = map[newStatus] || map.open;
    el.className = 'px-2.5 py-0.5 rounded-full text-[8px] font-black uppercase tracking-wide border ' + def.cls;
    el.textContent = def.label;
}

const _replyForm = document.getElementById('reply-form');
if (_replyForm) {
    _replyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const textarea = this.querySelector('textarea[name="message"]');
        const sendBtn  = this.querySelector('button[type="submit"]');
        if (!textarea.value.trim()) return;
        sendBtn.disabled      = true;
        sendBtn.style.opacity = '0.6';
        fetch('help.php', {
            method:  'POST',
            body:    new FormData(this),
            headers: { 'X-Support-Ajax': '1' }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                textarea.value = '';
                const thread = document.getElementById('message-thread');
                const bottom = document.getElementById('thread-bottom');
                const bubble = _buildBubble(data.message);
                thread.insertBefore(bubble, bottom);
                thread.scrollTo({ top: thread.scrollHeight, behavior: 'smooth' });
                if (data.new_status) _updateStatusBadge(data.new_status);
            } else {
                if (typeof showFlash === 'function') showFlash(data.error || 'Could not send reply.', 'error');
            }
        })
        .catch(() => {
            if (typeof showFlash === 'function') showFlash('Network error. Please try again.', 'error');
        })
        .finally(() => {
            sendBtn.disabled      = false;
            sendBtn.style.opacity = '';
        });
    });
}
</script>

<?php include 'layout_bottom.php'; ?>
