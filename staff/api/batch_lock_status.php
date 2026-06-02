<?php
/**
 * batch_lock_status.php — live lock state for one or more batches.
 * ?ids=1,2,3 → { "1": {locked, username, role, idle_secs, since}, ... }
 * Read-only; used by the "on-going process" view + queue badges to update live.
 */
include '../../includes/auth_check.php';
include '../../config/db.php';
require_once '../../includes/batch_lock.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$ids = array_unique(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')), fn($v) => $v > 0));
$out = [];
foreach ($ids as $id) {
    $h = batch_lock_holder($conn, $id);
    $out[$id] = $h
        ? ['locked' => true, 'username' => $h['working_username'], 'role' => $h['working_role'],
           'idle_secs' => intval($h['idle_secs']), 'since' => $h['working_at']]
        : ['locked' => false];
}
echo json_encode($out);
