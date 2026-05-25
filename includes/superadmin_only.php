<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$_role = strtolower($_SESSION['role'] ?? '');

if (empty($_role)) {
    header("Location: /project/auth/login.php");
    exit();
}

if ($_role !== ROLE_SUPERADMIN) {
    header("Location: /project/staff/dashboard.php");
    exit();
}
?>
