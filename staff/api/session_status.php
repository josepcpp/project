<?php
/**
 * session_status.php — lightweight poll for the force-logout feature.
 *
 * Returns:
 *   { "forced": false }
 *   { "forced": true, "by": "adminX", "by_role": "admin" }
 *
 * Reports only — it never destroys the session or redirects. The page-level poll
 * shows the modal; auth_check.php is the hard fallback on the next navigation.
 */

// Suppress auth_check's hard redirect so this endpoint can answer as JSON.
define('SUPPRESS_FORCE_LOGOUT_REDIRECT', true);

include '../../config/db.php';          // sets $conn (needed before auth_check)
include '../../includes/auth_check.php'; // still enforces "must be logged in" + IP rules

header('Content-Type: application/json');

$uid      = intval($_SESSION['user_id'] ?? 0);
$login_at = intval($_SESSION['login_at'] ?? 0);

if ($uid < 1) { echo json_encode(['forced' => false]); exit(); }

$q = $conn->prepare("SELECT force_logout_at, force_logout_by, force_logout_by_role FROM users WHERE id = ? LIMIT 1");
$q->bind_param("i", $uid);
$q->execute();
$row = $q->get_result()->fetch_assoc();

if ($row && !empty($row['force_logout_at'])) {
    $forced_ts = strtotime($row['force_logout_at']);
    if ($forced_ts !== false && $forced_ts > $login_at) {
        echo json_encode([
            'forced'  => true,
            'by'      => $row['force_logout_by']      ?? '',
            'by_role' => $row['force_logout_by_role'] ?? '',
        ]);
        exit();
    }
}

echo json_encode(['forced' => false]);
exit();
