<?php
/**
 * manual_pref.php — Save the current user's "show manual on every login" toggle.
 * AJAX only. Returns JSON.
 */
include '../../includes/auth_check.php';
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'error' => 'Not signed in.']); exit(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'error' => 'POST required.']); exit(); }

$show = !empty($_POST['show']) ? 1 : 0;

$u = $conn->prepare("UPDATE users SET show_manual_on_login = ? WHERE id = ?");
if ($u) {
    $u->bind_param("ii", $show, $user_id);
    $u->execute();
    echo json_encode(['success' => true, 'show' => $show]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not save preference.']);
}
exit();
