<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$_role = strtolower($_SESSION['role'] ?? '');

if (empty($_role)) {
    header("Location: /project/auth/login.php");
    exit();
}

if ($_role === ROLE_STAFF) {
    header("Location: /project/staff/pos/pos.php");
    exit();
}

if (!in_array($_role, ROLES_ADMIN_AND_UP)) {
    header("Location: /project/auth/login.php");
    exit();
}
?>