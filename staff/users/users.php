<?php
include '../../config/db.php';
include '../../includes/admin_only.php';
include '../layout_top.php';

$session_role  = strtolower($_SESSION['role'] ?? '');
$is_superadmin = ($session_role === ROLE_SUPERADMIN);

function can_act(string $actor, string $target): bool {
    if ($actor === ROLE_SUPERADMIN) return true;
    if ($actor === ROLE_ADMIN && !in_array($target, ROLES_ADMIN_AND_UP)) return true;
    return false;
}

$users = $conn->query("SELECT * FROM users ORDER BY FIELD(status,'" . USER_ACTIVE . "','" . USER_INACTIVE . "','" . USER_TERMINATED . "'), id DESC");

// Pending resets: admin sees only staff; superadmin sees all
if ($is_superadmin) {
    $pending_resets = $conn->query("
        SELECT id, username, full_name, role
        FROM users
        WHERE reset_requested = 1
        ORDER BY FIELD(role,'" . ROLE_STAFF . "','" . ROLE_RECEIVER . "','" . ROLE_VALIDATOR . "','" . ROLE_PRICE_CHECKER . "','" . ROLE_ADMIN . "','" . ROLE_OWNER . "','" . ROLE_SUPERADMIN . "'), full_name ASC
    ");
} else {
    $pending_resets = $conn->query("
        SELECT id, username, full_name, role
        FROM users
        WHERE reset_requested = 1 AND role = '" . ROLE_STAFF . "'
        ORDER BY full_name ASC
    ");
}

$reset_rows = $pending_resets ? $pending_resets->fetch_all(MYSQLI_ASSOC) : [];
?>

<div class="max-w-6xl mx-auto space-y-8 pb-20 animate-in">

    <!-- ── PENDING PASSWORD RESET REQUESTS ──────────────────────────────────── -->
    <?php if (!empty($reset_rows)): ?>
    <div class="bg-white border-2 border-amber-200 rounded-[3rem] shadow-2xl overflow-hidden">

        <!-- Panel header -->
        <div class="px-10 py-7 bg-amber-50/60 border-b border-amber-100 flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-amber-400 rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="serif-title text-2xl font-black text-amber-800">Password Reset Requests</h3>
                    <p class="text-amber-600 text-xs font-bold uppercase tracking-widest mt-0.5">
                        <?= $is_superadmin
                            ? 'All roles — Super Admin can reset any account'
                            : 'Staff accounts only — Admin cannot reset other admins' ?>
                    </p>
                </div>
            </div>
            <span class="bg-amber-400 text-white font-black text-sm px-5 py-2 rounded-full shadow-md flex-shrink-0">
                <?= count($reset_rows) ?> pending
            </span>
        </div>

        <!-- Request rows -->
        <div class="divide-y divide-slate-50">
        <?php foreach ($reset_rows as $rr):
            $rr_role   = strtolower($rr['role']);
            $can_reset = can_act($session_role, $rr_role);
            $initial   = strtoupper(substr($rr['full_name'] ?: $rr['username'], 0, 1));

            $role_color = match($rr_role) {
                ROLE_SUPERADMIN => ['bg' => 'bg-rose-100',   'text' => 'text-rose-600',   'badge' => 'bg-rose-50 text-rose-600 border-rose-200'],
                ROLE_ADMIN, ROLE_OWNER => ['bg' => 'bg-purple-100', 'text' => 'text-purple-700', 'badge' => 'bg-purple-50 text-purple-600 border-purple-200'],
                default         => ['bg' => 'bg-blue-100',   'text' => 'text-blue-600',   'badge' => 'bg-blue-50 text-blue-500 border-blue-200'],
            };
            $role_label = $rr_role === ROLE_SUPERADMIN ? '★ Super Admin' : ucfirst($rr_role);
        ?>
        <div class="px-10 py-6 flex flex-col sm:flex-row sm:items-center gap-5 hover:bg-slate-50/30 transition-all <?= !$can_reset ? 'opacity-50' : '' ?>">

            <!-- Identity -->
            <div class="flex items-center gap-4 flex-1 min-w-0">
                <div class="w-12 h-12 rounded-2xl <?= $role_color['bg'] ?> <?= $role_color['text'] ?> flex items-center justify-center font-black text-lg flex-shrink-0">
                    <?= $initial ?>
                </div>
                <div class="min-w-0">
                    <p class="font-black text-slate-800 text-base leading-tight truncate">
                        <?= htmlspecialchars($rr['full_name'] ?: $rr['username']) ?>
                    </p>
                    <p class="text-slate-400 text-xs font-bold mt-0.5">@<?= htmlspecialchars($rr['username']) ?></p>
                </div>
            </div>

            <!-- Badges -->
            <div class="flex items-center gap-3 flex-shrink-0">
                <span class="px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-wider border <?= $role_color['badge'] ?>">
                    <?= $role_label ?>
                </span>
                <span class="bg-amber-50 text-amber-600 border border-amber-200 text-[9px] font-black px-3 py-1.5 rounded-full uppercase tracking-widest">
                    Reset Requested
                </span>
                <?php if (!$can_reset): ?>
                    <span class="bg-slate-100 text-slate-400 text-[9px] font-black px-3 py-1.5 rounded-full uppercase tracking-widest">
                        Protected — Super Admin Only
                    </span>
                <?php endif; ?>
            </div>

            <!-- Action -->
            <?php if ($can_reset): ?>
            <button onclick="openResetModal(<?= $rr['id'] ?>, '<?= htmlspecialchars(addslashes($rr['username'])) ?>', '<?= htmlspecialchars(addslashes($rr['full_name'] ?? $rr['username'])) ?>', '<?= $rr_role ?>')"
                class="bg-amber-500 hover:bg-amber-600 active:scale-95 text-white font-black text-[10px] uppercase tracking-widest px-7 py-3.5 rounded-2xl transition-all shadow-lg shadow-amber-100 flex-shrink-0">
                Set New Password
            </button>
            <?php else: ?>
            <span class="text-[10px] font-black text-slate-300 uppercase tracking-widest flex-shrink-0 px-7 py-3.5">
                No Permission
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── CREATE NEW USER ───────────────────────────────────────────────────── -->
    <div class="bg-white p-8 rounded-[2rem] border border-slate-100 shadow-sm">
        <h3 class="serif-title text-xl font-bold mb-6 flex items-center gap-3">
            <span class="w-8 h-8 bg-emerald-500 text-white rounded-lg flex items-center justify-center">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/></svg>
            </span>
            Create New Account
        </h3>
        <form method="POST" action="users_process.php" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <input type="hidden" name="action" value="create">
            <input type="text" name="full_name" placeholder="Full Name" required
                class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-emerald-500/20 transition-all">
            <input type="text" name="contact_no" placeholder="Contact No."
                class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-emerald-500/20 transition-all">
            <input type="text" name="username" placeholder="Username or Email" required
                class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-emerald-500/20 transition-all">
            <input type="password" name="password" placeholder="Password (min 8 chars)" required minlength="8"
                class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-emerald-500/20 transition-all">
            <select name="role" class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none">
                <option value="staff">Staff Member</option>
                <optgroup label="── Procurement Pipeline ──">
                    <option value="receiver">Receiver</option>
                    <option value="validator">Validator</option>
                    <option value="price_checker">Price Checker</option>
                </optgroup>
                <?php if ($is_superadmin): ?>
                <optgroup label="── Administration ──">
                    <option value="admin">Administrator</option>
                    <option value="superadmin">Super Admin</option>
                </optgroup>
                <?php endif; ?>
            </select>
            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-bold rounded-xl py-3 shadow-lg shadow-emerald-100 transition-all">
                Add Account
            </button>
        </form>
    </div>

    <!-- ── USER LIST ─────────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[2rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/50">
            <h4 class="font-black text-slate-700 text-xs uppercase tracking-widest">All Accounts</h4>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-slate-100 bg-slate-50/30">
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Person</th>
                        <th class="px-4 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Contact</th>
                        <th class="px-4 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Role</th>
                        <th class="px-4 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                <?php while ($u = $users->fetch_assoc()):
                    $u_role   = strtolower($u['role']);
                    $u_status = $u['status'];
                    $actable  = can_act($session_role, $u_role);
                    $is_self  = $u['id'] == ($_SESSION['user_id'] ?? 0);
                    $initial  = strtoupper(substr($u['full_name'] ?: $u['username'], 0, 1));

                    $badge = match($u_role) {
                        ROLE_SUPERADMIN    => 'bg-rose-50 text-rose-600',
                        ROLE_ADMIN, ROLE_OWNER => 'bg-purple-50 text-purple-600',
                        ROLE_RECEIVER      => 'bg-sky-50 text-sky-600',
                        ROLE_VALIDATOR     => 'bg-teal-50 text-teal-600',
                        ROLE_PRICE_CHECKER => 'bg-orange-50 text-orange-600',
                        default            => 'bg-blue-50 text-blue-500',
                    };
                    $badge_label = match($u_role) {
                        ROLE_SUPERADMIN    => '★ Super Admin',
                        ROLE_PRICE_CHECKER => 'Price Checker',
                        default            => ucfirst($u_role),
                    };

                    $status_class = match($u_status) {
                        USER_ACTIVE     => 'bg-emerald-50 text-emerald-600 border border-emerald-100',
                        USER_INACTIVE   => 'bg-slate-100 text-slate-400',
                        USER_TERMINATED => 'bg-red-50 text-red-500 border border-red-100',
                        default         => 'bg-slate-100 text-slate-400',
                    };
                ?>
                <tr class="hover:bg-slate-50/50 transition-all <?= $u_status === USER_TERMINATED ? 'opacity-60' : '' ?>">
                    <td class="px-6 py-5">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center font-black text-sm flex-shrink-0 <?= $badge ?>">
                                <?= $initial ?>
                            </div>
                            <div>
                                <p class="font-bold text-slate-800 leading-none"><?= htmlspecialchars($u['full_name'] ?: '—') ?></p>
                                <p class="text-[10px] text-slate-400 font-bold mt-0.5">@<?= htmlspecialchars($u['username']) ?></p>
                                <?php if ($u_status === USER_TERMINATED && $u['terminated_at']): ?>
                                    <p class="text-[9px] text-red-400 font-bold mt-1">Terminated <?= date('M d, Y', strtotime($u['terminated_at'])) ?></p>
                                    <?php if ($u['termination_reason']): ?>
                                        <p class="text-[9px] text-slate-400 italic"><?= htmlspecialchars($u['termination_reason']) ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($u['reset_requested']): ?>
                                    <span class="text-[9px] font-black text-amber-600 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full mt-1 inline-block">Reset Requested</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-5 text-sm text-slate-500 font-medium">
                        <?= htmlspecialchars($u['contact_no'] ?: '—') ?>
                    </td>
                    <td class="px-4 py-5 text-center">
                        <span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider <?= $badge ?>">
                            <?= $badge_label ?>
                        </span>
                    </td>
                    <td class="px-4 py-5 text-center">
                        <span class="px-3 py-1.5 rounded-full text-[9px] font-black uppercase <?= $status_class ?>">
                            <?= $u_status ?>
                        </span>
                    </td>
                    <td class="px-6 py-5 text-right">
                        <?php if (!$actable): ?>
                            <span class="text-[10px] font-bold text-slate-200 uppercase tracking-widest">Protected</span>
                        <?php elseif ($u_status === USER_TERMINATED): ?>
                            <?php if ($is_superadmin): ?>
                            <form method="POST" action="users_process.php" class="inline">
                                <input type="hidden" name="action" value="reinstate">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button class="text-[10px] font-black text-emerald-600 hover:underline uppercase tracking-widest">Reinstate</button>
                            </form>
                            <?php else: ?>
                                <span class="text-[10px] font-bold text-slate-300 uppercase">Terminated</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="flex items-center justify-end gap-4">

                                <!-- Toggle Active/Inactive -->
                                <?php if (!$is_self): ?>
                                <form method="POST" action="users_process.php" class="inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button class="text-[10px] font-black uppercase tracking-widest <?= $u_status === USER_ACTIVE ? 'text-slate-400 hover:text-amber-500' : 'text-slate-400 hover:text-emerald-500' ?> transition-colors">
                                        <?= $u_status === USER_ACTIVE ? 'Suspend' : 'Activate' ?>
                                    </button>
                                </form>
                                <?php endif; ?>

                                <!-- Reset Password -->
                                <button onclick="openResetModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>', '<?= htmlspecialchars(addslashes($u['full_name'] ?? $u['username'])) ?>', '<?= $u_role ?>')"
                                    class="text-[10px] font-black uppercase tracking-widest transition-colors <?= $u['reset_requested'] ? 'text-amber-500 hover:text-amber-700' : 'text-blue-400 hover:text-blue-600' ?>">
                                    <?= $u['reset_requested'] ? 'Set PW ●' : 'Reset PW' ?>
                                </button>

                                <!-- Terminate -->
                                <?php if (!$is_self): ?>
                                <button onclick="openTerminateModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'] ?? $u['username'])) ?>')"
                                    class="text-[10px] font-black text-red-400 hover:text-red-600 uppercase tracking-widest transition-colors">
                                    Terminate
                                </button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── TERMINATE MODAL ───────────────────────────────────────────────────────── -->
<div id="terminate-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[200] hidden flex items-center justify-center p-6">
    <div class="bg-white rounded-3xl shadow-2xl p-10 max-w-md w-full">
        <div class="w-14 h-14 bg-red-100 rounded-2xl flex items-center justify-center mx-auto mb-5">
            <svg class="w-7 h-7 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
        </div>
        <h3 class="serif-title text-2xl font-bold text-slate-800 text-center mb-1">Terminate Account</h3>
        <p id="terminate-name" class="text-slate-400 text-sm text-center font-bold mb-6"></p>
        <form method="POST" action="users_process.php">
            <input type="hidden" name="action" value="terminate">
            <input type="hidden" name="id" id="terminate-id">
            <div class="mb-5">
                <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Reason for Termination</label>
                <textarea name="reason" required rows="3" placeholder="e.g. End of contract, misconduct..."
                    class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 font-medium text-slate-700 outline-none focus:ring-4 focus:ring-red-500/10 focus:border-red-300 resize-none transition-all"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeModals()" class="flex-1 bg-slate-100 text-slate-600 font-bold py-3 rounded-2xl hover:bg-slate-200 transition-all">Cancel</button>
                <button type="submit" class="flex-1 bg-red-500 text-white font-bold py-3 rounded-2xl hover:bg-red-600 transition-all shadow-lg shadow-red-100">Confirm Terminate</button>
            </div>
        </form>
    </div>
</div>

<!-- ── RESET PASSWORD MODAL ──────────────────────────────────────────────────── -->
<div id="reset-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[200] hidden flex items-center justify-center p-6">
    <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-md overflow-hidden">

        <!-- Modal header -->
        <div class="bg-slate-900 px-8 pt-8 pb-6 text-center">
            <div class="w-14 h-14 bg-amber-400 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                </svg>
            </div>
            <h3 class="serif-title text-2xl font-bold text-white mb-1">Set New Password</h3>
            <p id="reset-name" class="text-slate-400 text-sm font-bold"></p>

            <!-- Role + permission context -->
            <div class="flex items-center justify-center gap-2 mt-3">
                <span id="reset-role-badge" class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider"></span>
                <span id="reset-actor-badge" class="text-[9px] font-black text-slate-500 uppercase tracking-widest"></span>
            </div>
        </div>

        <!-- Form body -->
        <div class="px-8 py-7">
            <form method="POST" action="users_process.php" onsubmit="return validateReset(this)">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" id="reset-id">
                <div class="space-y-4 mb-6">
                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">New Password</label>
                        <input type="password" name="new_password" id="reset-pw" required minlength="8"
                            class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 font-medium text-slate-700 outline-none focus:ring-4 focus:ring-amber-500/10 focus:border-amber-300 transition-all"
                            placeholder="Minimum 8 characters">
                    </div>
                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Confirm Password</label>
                        <input type="password" name="confirm_password" id="reset-confirm" required
                            class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 font-medium text-slate-700 outline-none focus:ring-4 focus:ring-amber-500/10 focus:border-amber-300 transition-all"
                            placeholder="Repeat password">
                    </div>
                    <p id="reset-mismatch" class="text-rose-500 text-xs font-bold hidden">Passwords do not match.</p>
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeModals()" class="flex-1 bg-slate-100 text-slate-600 font-bold py-3.5 rounded-2xl hover:bg-slate-200 transition-all">Cancel</button>
                    <button type="submit" class="flex-1 bg-amber-500 text-white font-bold py-3.5 rounded-2xl hover:bg-amber-600 transition-all shadow-lg shadow-amber-100">
                        Set Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const SESSION_ROLE = '<?= $session_role ?>';

// Role display helpers
const ROLE_BADGES = {
    superadmin:    { cls: 'bg-rose-50 text-rose-600',     label: '★ Super Admin' },
    admin:         { cls: 'bg-purple-50 text-purple-600',  label: 'Administrator' },
    owner:         { cls: 'bg-purple-50 text-purple-600',  label: 'Owner' },
    staff:         { cls: 'bg-blue-50 text-blue-500',      label: 'Staff' },
    receiver:      { cls: 'bg-sky-50 text-sky-600',        label: 'Receiver' },
    validator:     { cls: 'bg-teal-50 text-teal-600',      label: 'Validator' },
    price_checker: { cls: 'bg-orange-50 text-orange-600',  label: 'Price Checker' },
};

function openResetModal(id, username, name, targetRole) {
    document.getElementById('reset-id').value = id;
    document.getElementById('reset-name').textContent = name + ' (@' + username + ')';
    document.getElementById('reset-pw').value = '';
    document.getElementById('reset-confirm').value = '';
    document.getElementById('reset-mismatch').classList.add('hidden');

    // Role badge
    const rb = ROLE_BADGES[targetRole] || ROLE_BADGES.staff;
    const roleBadge = document.getElementById('reset-role-badge');
    roleBadge.textContent = rb.label;
    roleBadge.className = 'px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider ' + rb.cls;

    // Actor permission label
    const actorBadge = document.getElementById('reset-actor-badge');
    if (SESSION_ROLE === 'superadmin') {
        actorBadge.textContent = '· Changed by Super Admin';
    } else {
        actorBadge.textContent = '· Changed by Admin';
    }

    const modal = document.getElementById('reset-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => document.getElementById('reset-pw').focus(), 80);
}

function openTerminateModal(id, name) {
    document.getElementById('terminate-id').value = id;
    document.getElementById('terminate-name').textContent = name;
    const modal = document.getElementById('terminate-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeModals() {
    ['terminate-modal', 'reset-modal'].forEach(id => {
        const m = document.getElementById(id);
        if (m) { m.classList.add('hidden'); m.classList.remove('flex'); }
    });
}

function validateReset(form) {
    const pw = document.getElementById('reset-pw').value;
    const cf = document.getElementById('reset-confirm').value;
    const err = document.getElementById('reset-mismatch');
    if (pw !== cf) {
        err.classList.remove('hidden');
        document.getElementById('reset-confirm').focus();
        return false;
    }
    err.classList.add('hidden');
    return true;
}

// Live confirm match feedback
document.getElementById('reset-confirm').addEventListener('input', function() {
    const pw = document.getElementById('reset-pw').value;
    const err = document.getElementById('reset-mismatch');
    if (this.value && this.value !== pw) {
        err.classList.remove('hidden');
    } else {
        err.classList.add('hidden');
    }
});

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModals(); });
['terminate-modal', 'reset-modal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', function(e) { if (e.target === this) closeModals(); });
});
</script>

<?php include '../layout_bottom.php'; ?>
