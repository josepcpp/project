<?php
include '../../includes/auth_check.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$_SESSION['cart']             = [];
$_SESSION['bundle_discounts'] = []; // F-13: clear bundle discounts with cart
header("Location: pos.php");
exit();
