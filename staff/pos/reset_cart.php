<?php
include '../../includes/auth_check.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$_SESSION['cart'] = [];
header("Location: pos.php");
exit();
