<?php
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ADD SUPPLIER ──────────────────────────────────────────────────────────────
if (isset($_POST['name'])) {
    $stmt = $conn->prepare("INSERT INTO suppliers (name, contact) VALUES (?, ?)");
    $stmt->bind_param("ss", $_POST['name'], $_POST['contact']);
    $stmt->execute();
}

// ── DELETE SUPPLIER ───────────────────────────────────────────────────────────
if (isset($_POST['delete'])) {
    $id   = intval($_POST['id']);
    $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
}

header("Location: suppliers.php");
exit();
