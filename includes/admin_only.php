<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$_role = strtolower($_SESSION['role'] ?? '');

if (empty($_role)) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_role === ROLE_STAFF) {
    header("Location: pos.php");
    exit();
}

if (!in_array($_role, ROLES_ADMIN_AND_UP)) {
    header("Location: ../auth/login.php");
    exit();
}
?>