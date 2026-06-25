<?php
/**
 * ip_restrictions.php — Manage IP-based access control.
 * When enabled, only IPs on the whitelist may access the staff panel.
 * Admin's current IP is automatically added when the feature is first enabled
 * to prevent lockout.
 *
 * Supports single IPs (192.168.1.10) and simple CIDR ranges (192.168.1.0/24).
 * Superadmin only.
 */
include '../../config/db.php';
include '../../includes/superadmin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id  = $_SESSION['user_id'] ?? null;
$msg      = '';

function get_setting(mysqli $conn, string $key, string $default = ''): string {
    $q = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $q->bind_param("s", $key); $q->execute();
    $r = $q->get_result()->fetch_assoc();
    return $r ? ($r['setting_value'] ?? $default) : $default;
}
function set_setting(mysqli $conn, string $key, string $value): void {
    $q = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
    $q->bind_param("sss", $key, $value, $value); $q->execute();
}

function get_client_ip(): string {
    foreach (['HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            return trim(explode(',', $_SERVER[$k])[0]);
        }
    }
    return '0.0.0.0';
}

$my_ip = get_client_ip();

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_feature') {
        $enabling = intval($_POST['enable'] ?? 0) === 1;

        if ($enabling) {
            // Safety: add current admin IP automatically before enabling
            $s = $conn->prepare("INSERT IGNORE INTO ip_restrictions (ip_cidr, label, created_by) VALUES (?, 'Admin (auto-added)', ?)");
            $s->bind_param("si", $my_ip, $user_id); $s->execute();
            set_setting($conn, 'ip_restriction_enabled', '1');
            $msg = "<p class='msg-success'>IP restriction ENABLED. Your IP ({$my_ip}) was added automatically.</p>";
        } else {
            set_setting($conn, 'ip_restriction_enabled', '0');
            $msg = "<p class='msg-success'>IP restriction DISABLED. All IPs can now access the panel.</p>";
        }
    }

    if ($action === 'add_ip') {
        $ip_cidr = trim($_POST['ip_cidr'] ?? '');
        $label   = trim($_POST['label']   ?? '');
        $note    = trim($_POST['note']    ?? '');

        if ($ip_cidr === '')
            $msg = "<p class='msg-error'>IP address is required.</p>";
        else {
            $s = $conn->prepare("INSERT IGNORE INTO ip_restrictions (ip_cidr, label, note, created_by) VALUES (?,?,?,?)");
            $s->bind_param("sssi", $ip_cidr, $label, $note, $user_id);
            if ($s->execute() && $conn->affected_rows > 0)
                $msg = "<p class='msg-success'>IP <strong>{$ip_cidr}</strong> added to whitelist.</p>";
            else
                $msg = "<p class='msg-error'>IP already exists or is invalid.</p>";
        }
    }

    if ($action === 'toggle_ip') {
        $id = intval($_POST['id'] ?? 0);
        $s  = $conn->prepare("UPDATE ip_restrictions SET is_active = IF(is_active=1,0,1) WHERE id=?");
        $s->bind_param("i", $id); $s->execute();
        $msg = "<p class='msg-success'>Entry updated.</p>";
    }

    if ($action === 'delete_ip') {
        $id = intval($_POST['id'] ?? 0);
        // Safety: block deletion of my own current IP when restriction is enabled
        $enabled = get_setting($conn, 'ip_restriction_enabled', '0') === '1';
        $rq = $conn->prepare("SELECT ip_cidr FROM ip_restrictions WHERE id=? LIMIT 1");
        $rq->bind_param("i", $id); $rq->execute();
        $row = $rq->get_result()->fetch_assoc();
        if ($enabled && $row && $row['ip_cidr'] === $my_ip) {
            $msg = "<p class='msg-error'>Cannot remove your own IP while restriction is enabled — you would lock yourself out.</p>";
        } else {
            $s = $conn->prepare("DELETE FROM ip_restrictions WHERE id=?");
            $s->bind_param("i", $id); $s->execute();
            $msg = "<p class='msg-success'>IP removed from whitelist.</p>";
        }
    }
}

include '../layout_top.php';

$enabled   = get_setting($conn, 'ip_restriction_enabled', '0') === '1';
$ips_q     = $conn->query("SELECT * FROM ip_restrictions ORDER BY created_at DESC");
$ip_count  = $ips_q ? $ips_q->num_rows : 0;
?>

<div class="max-w-5xl mx-auto pb-20 animate-in space-y-10">

    <?php if ($msg): ?><div><?= $msg ?></div><?php endif; ?>

    <!-- ── STATUS & TOGGLE ────────────────────────────────────────────────── -->
    <div class="card-modern shadow-xl">
        <div class="flex items-center justify-between flex-wrap gap-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 <?= $enabled ? 'bg-rose-100' : 'bg-slate-100' ?> rounded-2xl flex items-center justify-center <?= $enabled ? 'text-rose-600' : 'text-slate-400' ?>">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                </div>
                <div>
                    <h3 class="serif-title text-2xl font-bold text-slate-800">IP Access Restriction</h3>
                    <p class="text-slate-400 text-xs font-bold uppercase tracking-widest">
                        Status: <span class="<?= $enabled ? 'text-rose-500' : 'text-emerald-500' ?> font-black"><?= $enabled ? 'ENABLED' : 'DISABLED' ?></span>
                        &nbsp;·&nbsp; Your IP: <code class="text-blue-500"><?= htmlspecialchars($my_ip) ?></code>
                    </p>
                </div>
            </div>
            <form method="POST" onsubmit="return confirmAction('<?= $enabled ? 'Disable IP restriction? All IPs will have access.' : 'Enable IP restriction? Only whitelisted IPs can log in.' ?>', null, '<?= $enabled ? 'Disable' : 'Enable' ?> IP Restriction')">
                <input type="hidden" name="action" value="toggle_feature">
                <input type="hidden" name="enable" value="<?= $enabled ? '0' : '1' ?>">
                <button type="submit" class="<?= $enabled ? 'bg-slate-700 hover:bg-slate-800' : 'bg-rose-500 hover:bg-rose-600' ?> text-white font-black px-6 py-3 rounded-2xl text-xs uppercase tracking-widest transition-colors shadow-lg">
                    <?= $enabled ? 'Disable Restriction' : 'Enable Restriction' ?>
                </button>
            </form>
        </div>

        <?php if ($enabled): ?>
        <div class="mt-6 bg-rose-50 border border-rose-200 rounded-2xl p-4 text-sm text-rose-700 font-bold">
            ⚠️ Restriction is <strong>ACTIVE</strong>. Only the <?= $ip_count ?> IP(s) below can access the staff panel. Any other IP will be blocked at login.
        </div>
        <?php else: ?>
        <div class="mt-6 bg-slate-50 border border-slate-100 rounded-2xl p-4 text-sm text-slate-500 font-bold">
            💡 Restriction is off — all IPs can access the staff panel. Enable it to lock access to specific office/network IPs only.
        </div>
        <?php endif; ?>
    </div>

    <!-- ── ADD IP ─────────────────────────────────────────────────────────── -->
    <div class="card-modern shadow-xl">
        <h4 class="font-black text-slate-700 text-sm uppercase tracking-widest mb-6">Add IP to Whitelist</h4>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_ip">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end">
                <div class="md:col-span-3">
                    <label class="label-modern ml-2">IP Address / CIDR <span class="text-rose-400">*</span></label>
                    <input type="text" name="ip_cidr" required placeholder="192.168.1.10 or 192.168.1.0/24"
                           class="input-modern font-mono">
                </div>
                <div class="md:col-span-3">
                    <label class="label-modern ml-2">Label</label>
                    <input type="text" name="label" placeholder="e.g. Main Office Router"
                           class="input-modern">
                </div>
                <div class="md:col-span-4">
                    <label class="label-modern ml-2">Notes <span class="text-slate-300 font-normal normal-case">(optional)</span></label>
                    <input type="text" name="note" placeholder="e.g. admin workstation"
                           class="input-modern">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="btn-pos-primary w-full">ADD IP</button>
                </div>
            </div>
        </form>
        <div class="mt-4 text-xs text-slate-400 font-bold ml-2">
            Your current IP: <code class="text-blue-500"><?= htmlspecialchars($my_ip) ?></code>
            — <button type="button" onclick="document.querySelector('[name=ip_cidr]').value='<?= htmlspecialchars($my_ip) ?>'"
                      class="text-blue-400 hover:text-blue-600 underline">Use my IP</button>
        </div>
    </div>

    <!-- ── WHITELIST TABLE ────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="p-6 bg-slate-50 border-b border-slate-100">
            <h4 class="font-black text-slate-800 text-[11px] uppercase tracking-widest">Whitelisted IPs</h4>
        </div>

        <table class="table-modern text-left w-full">
            <thead>
                <tr class="bg-slate-50/50">
                    <th class="px-8 py-5">IP / CIDR</th>
                    <th class="px-4 py-5">Label / Notes</th>
                    <th class="px-4 py-5 text-center">Status</th>
                    <th class="px-4 py-5 text-center">Added</th>
                    <th class="px-8 py-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if ($ip_count > 0):
                    $ips_q->data_seek(0);
                    while ($ip = $ips_q->fetch_assoc()):
                        $is_mine = ($ip['ip_cidr'] === $my_ip);
                ?>
                <tr class="hover:bg-slate-50 transition-colors <?= !$ip['is_active'] ? 'opacity-40' : '' ?>">
                    <td class="px-8 py-5">
                        <code class="font-mono font-bold text-slate-700"><?= htmlspecialchars($ip['ip_cidr']) ?></code>
                        <?php if ($is_mine): ?>
                            <span class="ml-2 text-[8px] font-black text-blue-500 bg-blue-50 px-2 py-0.5 rounded border border-blue-100 uppercase">You</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-5">
                        <?php if ($ip['label']): ?><p class="font-bold text-slate-600 text-sm"><?= htmlspecialchars($ip['label']) ?></p><?php endif; ?>
                        <?php if ($ip['note']): ?><p class="text-slate-400 text-xs"><?= htmlspecialchars($ip['note']) ?></p><?php endif; ?>
                    </td>
                    <td class="px-4 py-5 text-center">
                        <span class="px-3 py-1.5 rounded-full text-[9px] font-black uppercase <?= $ip['is_active'] ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400' ?>">
                            <?= $ip['is_active'] ? 'ALLOWED' : 'BLOCKED' ?>
                        </span>
                    </td>
                    <td class="px-4 py-5 text-center text-xs font-bold text-slate-400">
                        <?= date('M j, Y', strtotime($ip['created_at'])) ?>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <div class="flex items-center justify-end gap-4">
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="toggle_ip">
                                <input type="hidden" name="id" value="<?= $ip['id'] ?>">
                                <button type="submit" class="text-[9px] font-black text-slate-300 hover:text-amber-500 uppercase tracking-widest transition-colors">
                                    [ <?= $ip['is_active'] ? 'Block' : 'Allow' ?> ]
                                </button>
                            </form>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="delete_ip">
                                <input type="hidden" name="id" value="<?= $ip['id'] ?>">
                                <button type="submit"
                                        onclick="return confirmAction('Remove <?= htmlspecialchars($ip['ip_cidr']) ?> from whitelist?', null, 'Remove IP')"
                                        class="text-[9px] font-black text-slate-300 hover:text-rose-500 uppercase tracking-widest transition-colors">
                                    [ Remove ]
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endwhile; endif; ?>
                <?php if ($ip_count === 0): ?>
                    <tr><td colspan="5" class="p-20 text-center text-slate-300 font-bold italic opacity-40 uppercase">No IPs added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
