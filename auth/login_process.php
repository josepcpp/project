<?php
session_start();

// 1. Include the DB connection
// Path: project/auth/login_process.php -> project/config/db.php
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    die("Error: db.php not found. Check your folder structure.");
}

if (isset($_POST['username']) && isset($_POST['password'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if ($username === "" || $password === "") {
        $_SESSION['error'] = "Please enter username and password";
        header("Location: login.php");
        exit();
    }

    // Prepare statement to prevent SQL Injection
    $stmt = $conn->prepare("SELECT id, username, password, role, status FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();

        // 1. Check if account is active
        if ($user['status'] !== USER_ACTIVE) {
            $_SESSION['error'] = "Account disabled. Please contact admin.";
            header("Location: login.php");
            exit();
        }

        // 2. Verify the password
        if (password_verify($password, $user['password'])) {

            // Prevent session fixation — issue a new session ID after auth
            session_regenerate_id(true);

            // SUCCESS: Set Session Variables
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            // Anchor for force-logout: a session is only killed when force_logout_at
            // is newer than this. Re-login refreshes it, so an old kick never re-fires.
            $_SESSION['login_at'] = time();

            // Clear any stale force-logout marker now that the user has re-authenticated.
            $clr = $conn->prepare("UPDATE users SET force_logout_at = NULL, force_logout_by = NULL, force_logout_by_role = NULL WHERE id = ?");
            $clr->bind_param("i", $user['id']);
            $clr->execute();
            
            // Normalize role to lowercase for consistent logic checks
            $user_role = strtolower($user['role']);
            $_SESSION['role'] = $user_role;

            if (in_array($user_role, ROLES_ADMIN_AND_UP)) {
                header("Location: ../staff/dashboard.php");
            } elseif ($user_role === ROLE_STAFF) {
                header("Location: ../staff/pos/pos.php");
            } elseif ($user_role === ROLE_RECEIVER) {
                header("Location: ../staff/dashboard.php");
            } elseif ($user_role === ROLE_VALIDATOR) {
                header("Location: ../staff/dashboard.php");
            } elseif ($user_role === ROLE_PRICE_CHECKER) {
                header("Location: ../staff/dashboard.php");
            } else {
                header("Location: ../public/index.php");
            }
            exit();

        } else {
            $_SESSION['error'] = "Invalid password. Please try again.";
            header("Location: login.php");
            exit();
        }
    } else {
        $_SESSION['error'] = "User account not found.";
        header("Location: login.php");
        exit();
    }
} else {
    header("Location: login.php");
    exit();
}