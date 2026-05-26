<?php
/**
 * backup.php — Database backup management.
 * - Manual trigger: dumps posiisdb to a timestamped SQL file.
 * - Schedule settings: enable/disable auto-backup, interval, retention.
 * - Auto-trigger: called by a JS fetch on dashboard load when backup is due.
 * - Admin and above only.
 */
include '../../config/db.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'system';
$msg      = '';

// ── SETTINGS HELPER ───────────────────────────────────────────────────────────
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

// ── BACKUP EXECUTOR ───────────────────────────────────────────────────────────
function run_backup(mysqli $conn, int $user_id, string $username, string $method): array {
    $backup_path = get_setting($conn, 'backup_path', 'backups');

    // GAP-10: Reject paths that contain shell metacharacters or traversal sequences
    // before constructing the filesystem path.
    if (preg_match('/[;&|`$><!\(\)\[\]\{\}]|\.\./', $backup_path)) {
        return ['ok' => false, 'msg' => 'Invalid backup path: contains disallowed characters.'];
    }

    $abs_path = __DIR__ . '/../../' . ltrim($backup_path, '/\\');

    if (!is_dir($abs_path) && !mkdir($abs_path, 0755, true)) {
        return ['ok' => false, 'msg' => "Cannot create backup directory: $abs_path"];
    }

    $db       = 'posiisdb';
    $filename = "backup_{$db}_" . date('Ymd_His') . ".sql";
    $filepath = $abs_path . DIRECTORY_SEPARATOR . $filename;

    // Use mysqldump via exec (available on XAMPP) — filepath is shell-escaped (GAP-10)
    $cmd    = "mysqldump --user=root --password= --host=localhost --single-transaction " . escapeshellarg($db) . " > " . escapeshellarg($filepath) . " 2>&1";
    $output = ''; $exit_code = 0;
    exec($cmd, $output_arr, $exit_code);
    $output = implode("\n", $output_arr);

    $size_kb = file_exists($filepath) ? intval(filesize($filepath) / 1024) : 0;
    $status  = ($exit_code === 0 && $size_kb > 0) ? 'success' : 'failed';

    // Log it
    $log = $conn->prepare("INSERT INTO backup_logs (filename, size_kb, status, method, triggered_by, trigger_username) VALUES (?,?,?,?,?,?)");
    $log->bind_param("sisssi", $filename, $size_kb, $status, $method, $user_id, $username);
    $log->execute();

    // Update last-run timestamp
    set_setting($conn, 'backup_last_run', date('Y-m-d H:i:s'));

    // Retention: remove old files — GAP-19: use realpath() to prevent symlink traversal
    $retention_days = intval(get_setting($conn, 'backup_retention_days', '30'));
    if ($retention_days > 0 && is_dir($abs_path)) {
        $real_backup_dir = realpath($abs_path);
        $cutoff = time() - ($retention_days * 86400);
        foreach (glob($abs_path . DIRECTORY_SEPARATOR . 'backup_*.sql') as $f) {
            $real_f = realpath($f);
            // Only unlink files that actually resolve inside the backup directory
            if ($real_f && $real_backup_dir && strncmp($real_f, $real_backup_dir, strlen($real_backup_dir)) === 0
                && filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }

    return ['ok' => $status === 'success', 'msg' => $status === 'success'
        ? "Backup saved: $filename ({$size_kb} KB)"
        : "Backup failed (exit $exit_code). $output"];
}

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'run_backup') {
        $result = run_backup($conn, $user_id, $username, 'manual');
        $msg = $result['ok']
            ? "<p class='msg-success'>{$result['msg']}</p>"
            : "<p class='msg-error'>{$result['msg']}</p>";
    }

    if ($action === 'save_settings') {
        set_setting($conn, 'backup_enabled',        intval($_POST['backup_enabled'] ?? 0) ? '1' : '0');
        set_setting($conn, 'backup_interval_hours', max(1, intval($_POST['backup_interval_hours'] ?? 24)));
        set_setting($conn, 'backup_retention_days', max(1, intval($_POST['backup_retention_days'] ?? 30)));
        set_setting($conn, 'backup_path',           trim($_POST['backup_path'] ?? 'backups'));
        $msg = "<p class='msg-success'>Backup settings saved.</p>";
    }

    if ($action === 'delete_log') {
        $id = intval($_POST['log_id'] ?? 0);
        $conn->prepare("DELETE FROM backup_logs WHERE id=?")->execute();
        $dl = $conn->prepare("DELETE FROM backup_logs WHERE id=?");
        $dl->bind_param("i", $id); $dl->execute();
        $msg = "<p class='msg-success'>Log entry removed.</p>";
    }
}

// ── AUTO-BACKUP CHECK (called via AJAX from dashboard) ───────────────────────
if (isset($_GET['auto_check'])) {
    header('Content-Type: application/json');
    $enabled  = get_setting($conn, 'backup_enabled', '0') === '1';
    $interval = max(1, intval(get_setting($conn, 'backup_interval_hours', '24')));
    $last_run = get_setting($conn, 'backup_last_run', '');

    $due = $enabled && (
        $last_run === '' ||
        (time() - strtotime($last_run)) >= ($interval * 3600)
    );

    if ($due) {
        $result = run_backup($conn, 0, 'auto', 'auto');
        echo json_encode(['triggered' => true, 'ok' => $result['ok'], 'msg' => $result['msg']]);
    } else {
        echo json_encode(['triggered' => false]);
    }
    exit();
}

include '../layout_top.php';

// Load settings
$settings = [
    'backup_enabled'        => get_setting($conn, 'backup_enabled', '0'),
    'backup_interval_hours' => get_setting($conn, 'backup_interval_hours', '24'),
    'backup_retention_days' => get_setting($conn, 'backup_retention_days', '30'),
    'backup_path'           => get_setting($conn, 'backup_path', 'backups'),
    'backup_last_run'       => get_setting($conn, 'backup_last_run', ''),
];

// Load log history
$logs_q = $conn->query("SELECT * FROM backup_logs ORDER BY created_at DESC LIMIT 50");
?>

<div class="max-w-5xl mx-auto pb-20 animate-in space-y-10">

    <?php if ($msg): ?><div><?= $msg ?></div><?php endif; ?>

    <!-- ── MANUAL BACKUP ──────────────────────────────────────────────────── -->
    <div class="card-modern shadow-xl">
        <div class="flex items-center gap-4 mb-6">
            <div class="w-12 h-12 bg-indigo-100 rounded-2xl flex items-center justify-center text-indigo-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
            </div>
            <div>
                <h3 class="serif-title text-2xl font-bold text-slate-800">Database Backup</h3>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest">
                    Last run: <?= $settings['backup_last_run'] ? date('M j, Y g:i A', strtotime($settings['backup_last_run'])) : 'Never' ?>
                </p>
            </div>
        </div>

        <form method="POST" onsubmit="return confirmAction('Run a full database backup now?', null, 'Confirm Backup')">
            <input type="hidden" name="action" value="run_backup">
            <button type="submit" class="btn-pos-primary px-8 shadow-lg shadow-indigo-200">
                ↓ Run Backup Now
            </button>
        </form>
    </div>

    <!-- ── SCHEDULE SETTINGS ─────────────────────────────────────────────── -->
    <div class="card-modern shadow-xl">
        <h4 class="font-black text-slate-700 text-sm uppercase tracking-widest mb-6">Auto-Backup Schedule</h4>

        <form method="POST" action="">
            <input type="hidden" name="action" value="save_settings">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end">
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Auto-Backup</label>
                    <select name="backup_enabled" class="input-modern cursor-pointer">
                        <option value="1" <?= $settings['backup_enabled'] === '1' ? 'selected' : '' ?>>Enabled</option>
                        <option value="0" <?= $settings['backup_enabled'] !== '1' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Interval (hours)</label>
                    <input type="number" name="backup_interval_hours" min="1" max="720"
                           value="<?= htmlspecialchars($settings['backup_interval_hours']) ?>"
                           class="input-modern font-black text-slate-700">
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Keep (days)</label>
                    <input type="number" name="backup_retention_days" min="1" max="365"
                           value="<?= htmlspecialchars($settings['backup_retention_days']) ?>"
                           class="input-modern font-black text-slate-700">
                </div>
                <div class="md:col-span-4">
                    <label class="label-modern ml-2">Save to Folder <span class="text-slate-300 font-normal normal-case">(relative to project root)</span></label>
                    <input type="text" name="backup_path"
                           value="<?= htmlspecialchars($settings['backup_path']) ?>"
                           placeholder="backups"
                           class="input-modern font-mono text-sm">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="btn-pos-primary w-full shadow-lg shadow-indigo-200">Save Settings</button>
                </div>
            </div>
        </form>

        <div class="mt-4 bg-blue-50 border border-blue-100 rounded-2xl p-4 text-xs text-blue-700 font-bold">
            💡 Auto-backup triggers when the admin dashboard loads and the interval has elapsed. Backups are stored as SQL files and can be restored via phpMyAdmin → Import.
        </div>
    </div>

    <!-- ── BACKUP HISTORY ────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="p-6 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
            <h4 class="font-black text-slate-800 text-[11px] uppercase tracking-widest">Backup History</h4>
            <p class="text-[10px] text-slate-400 font-bold uppercase">Last 50 entries</p>
        </div>

        <table class="table-modern text-left w-full">
            <thead>
                <tr class="bg-slate-50/50">
                    <th class="px-8 py-5">Filename</th>
                    <th class="px-4 py-5 text-center">Size</th>
                    <th class="px-4 py-5 text-center">Method</th>
                    <th class="px-4 py-5 text-center">Status</th>
                    <th class="px-4 py-5 text-center">Triggered By</th>
                    <th class="px-4 py-5 text-center">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if ($logs_q && $logs_q->num_rows > 0): ?>
                    <?php while ($l = $logs_q->fetch_assoc()): ?>
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-8 py-4">
                            <code class="text-xs font-mono text-slate-600"><?= htmlspecialchars($l['filename'] ?? '—') ?></code>
                        </td>
                        <td class="px-4 py-4 text-center font-bold text-slate-600 text-sm">
                            <?= $l['size_kb'] ? number_format($l['size_kb']) . ' KB' : '—' ?>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <span class="text-[9px] font-black uppercase px-2 py-1 rounded-full <?= $l['method'] === 'auto' ? 'bg-blue-50 text-blue-500' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $l['method'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <span class="text-[9px] font-black uppercase px-2 py-1 rounded-full <?= $l['status'] === 'success' ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-500' ?>">
                                <?= $l['status'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-4 text-center text-xs font-bold text-slate-500">
                            <?= htmlspecialchars($l['trigger_username'] ?? '—') ?>
                        </td>
                        <td class="px-4 py-4 text-center text-xs font-bold text-slate-400">
                            <?= date('M j, Y g:i A', strtotime($l['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="p-20 text-center text-slate-300 font-bold italic opacity-40 uppercase">No backups yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
