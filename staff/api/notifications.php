<?php
/**
 * notifications.php — AJAX endpoint for the notification bell.
 *
 * ?action=count        → { count: N }
 * ?action=list         → [ { id, type, message, is_read, created_at }, ... ]
 * ?action=mark_read    → { ok: true }  (requires &id=N)
 * ?action=mark_all_read → { ok: true }
 */
include '../../includes/auth_check.php';
include '../../config/db.php';

header('Content-Type: application/json');

$user_id = intval($_SESSION['user_id'] ?? 0);
$role    = strtolower($_SESSION['role'] ?? '');
$action  = $_GET['action'] ?? '';

// Build a WHERE clause that matches notifications for this user or role
// Admin/superadmin see role-broadcast notifications
// Receiver/validator/price_checker see their personal or role-broadcast notifications
function notif_where(int $user_id, string $role): string {
    $admin_roles = ['admin', 'owner', 'superadmin'];
    if (in_array($role, $admin_roles)) {
        return "WHERE (recipient_role IN ('admin','superadmin') OR recipient_id = $user_id)";
    }
    return "WHERE (recipient_id = $user_id OR recipient_role = '" . mysqli_real_escape_string($GLOBALS['conn'], $role) . "')";
}

$where = notif_where($user_id, $role);

if ($action === 'count') {
    $q = $GLOBALS['conn']->query("SELECT COUNT(*) AS c FROM notifications $where AND is_read = 0");
    $c = intval($q ? $q->fetch_assoc()['c'] ?? 0 : 0);
    echo json_encode(['count' => $c]);
    exit();
}

if ($action === 'list') {
    $q = $GLOBALS['conn']->query(
        "SELECT id, type, message, is_read,
                DATE_FORMAT(created_at, '%b %e, %Y %h:%i %p') AS created_at
         FROM notifications
         $where
         ORDER BY created_at DESC LIMIT 10"
    );
    $rows = [];
    if ($q) while ($r = $q->fetch_assoc()) $rows[] = $r;
    echo json_encode($rows);
    exit();
}

if ($action === 'mark_read') {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        $upd = $GLOBALS['conn']->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $upd->bind_param("i", $id);
        $upd->execute();
    }
    echo json_encode(['ok' => true]);
    exit();
}

if ($action === 'mark_all_read') {
    $conn = $GLOBALS['conn'];
    $conn->query("UPDATE notifications SET is_read = 1 $where");
    echo json_encode(['ok' => true]);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
