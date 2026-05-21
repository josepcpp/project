<?php
session_start();

if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    die("Error: db.php not found.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: signup.php");
    exit();
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['signup_error'] = "Username and password are required.";
    header("Location: signup.php");
    exit();
}

if (strlen($username) < MIN_USERNAME_LENGTH || strlen($username) > MAX_USERNAME_LENGTH) {
    $_SESSION['signup_error'] = "Username must be between " . MIN_USERNAME_LENGTH . " and " . MAX_USERNAME_LENGTH . " characters.";
    header("Location: signup.php");
    exit();
}

if (strlen($password) < MIN_PASSWORD_LENGTH) {
    $_SESSION['signup_error'] = "Password must be at least " . MIN_PASSWORD_LENGTH . " characters.";
    header("Location: signup.php");
    exit();
}

$stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $_SESSION['signup_error'] = "Username already taken. Please choose another.";
    header("Location: signup.php");
    exit();
}

$hashed = password_hash($password, PASSWORD_DEFAULT);
$role   = ROLE_MEMBER;
$status = USER_ACTIVE;

$insert = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, ?)");
$insert->bind_param("ssss", $username, $hashed, $role, $status);

if ($insert->execute()) {
    $_SESSION['signup_success'] = "Account created! You can now log in.";
    header("Location: login.php");
} else {
    $_SESSION['signup_error'] = "Registration failed. Please try again.";
    header("Location: signup.php");
}
exit();
