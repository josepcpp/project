<?php
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = strtolower($_SESSION['role'] ?? '');
if (!in_array($role, ROLES_ADMIN_AND_UP)) {
    header("Location: ../dashboard.php");
    exit();
}

$user_id = $_SESSION['user_id'] ?? null;
$action  = $_POST['action'] ?? '';

function can_act(string $actor, string $target): bool {
    if ($actor === ROLE_SUPERADMIN) return true;
    if ($actor === ROLE_ADMIN && !in_array($target, ROLES_PROTECTED)) return true;
    return false;
}

function get_target_role(mysqli $conn, int $id): string {
    $q = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $q->bind_param("i", $id);
    $q->execute();
    return strtolower($q->get_result()->fetch_assoc()['role'] ?? '');
}

function log_action(mysqli $conn, int $user_id, string $msg): void {
    $s = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_USERS . "', ?, ?)");
    if (!$s) return;
    $zero = 0;
    $s->bind_param("iis", $user_id, $zero, $msg);
    $s->execute();
}

// ── CREATE ────────────────────────────────────────────────────────────────────
if ($action === 'create') {
    $full_name  = trim($_POST['full_name']  ?? '');
    $contact_no = trim($_POST['contact_no'] ?? '');
    $username   = trim($_POST['username']   ?? '');
    $password   = $_POST['password']        ?? '';
    $new_role   = strtolower(trim($_POST['role'] ?? 'receiver'));

    if (strlen($username) < MIN_USERNAME_LENGTH || strlen($password) < MIN_PASSWORD_LENGTH) {
        header("Location: users.php?error=" . urlencode("Username min " . MIN_USERNAME_LENGTH . " chars, password min " . MIN_PASSWORD_LENGTH . " chars."));
        exit();
    }
    // The Staff role is retired — no new Staff accounts may be created (blocks the
    // dropdown removal being bypassed via a direct POST, for any creator role).
    if ($new_role === ROLE_STAFF) {
        header("Location: users.php?error=" . urlencode("The Staff role has been retired. Choose a Procurement Pipeline or Administration role."));
        exit();
    }
    $admin_creatable = [ROLE_RECEIVER, ROLE_VALIDATOR, ROLE_PRICE_CHECKER];
    if ($role === ROLE_ADMIN && !in_array($new_role, $admin_creatable)) {
        header("Location: users.php?error=" . urlencode("Admins can only create Procurement Pipeline accounts (Receiver, Validator, Price Checker)."));
        exit();
    }
    if ($role !== ROLE_SUPERADMIN && $new_role === ROLE_SUPERADMIN) {
        header("Location: users.php?error=" . urlencode("Only a Super Admin can create another Super Admin."));
        exit();
    }

    $dup = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $dup->bind_param("s", $username);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        header("Location: users.php?error=" . urlencode("Username \"$username\" is already taken."));
        exit();
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, full_name, contact_no, password, role, status) VALUES (?, ?, ?, ?, ?, '" . USER_ACTIVE . "')");
    $stmt->bind_param("sssss", $username, $full_name, $contact_no, $hash, $new_role);
    $stmt->execute();

    log_action($conn, $user_id, "Created account @{$username} ({$new_role}).");
    $new_id = $conn->insert_id;
    $sf = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_STAFF_CHANGE . "','" . SEV_LOW . "',?,'user',?)");
    $sf_msg = "New account created: @{$username} (role: {$new_role}) by @" . ($_SESSION['username'] ?? 'admin') . ".";
    $sf->bind_param("is", $new_id, $sf_msg);
    $sf->execute();
    header("Location: users.php?success=" . urlencode("Account @{$username} created successfully."));
    exit();
}

// ── TOGGLE ACTIVE ↔ INACTIVE ─────────────────────────────────────────────────
if ($action === 'toggle') {
    $id          = intval($_POST['id']);
    $target_role = get_target_role($conn, $id);

    if (!can_act($role, $target_role)) {
        header("Location: users.php?error=" . urlencode("Insufficient permissions."));
        exit();
    }

    $stmt = $conn->prepare("UPDATE users SET status = IF(status='" . USER_ACTIVE . "','" . USER_INACTIVE . "','" . USER_ACTIVE . "') WHERE id = ? AND status != '" . USER_TERMINATED . "'");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    log_action($conn, $user_id, "Toggled ACTIVE/INACTIVE for user ID {$id}.");
    header("Location: users.php?success=" . urlencode("Account status updated."));
    exit();
}

// ── TERMINATE ────────────────────────────────────────────────────────────────
if ($action === 'terminate') {
    $id          = intval($_POST['id']);
    $reason      = trim($_POST['reason'] ?? '');
    $target_role = get_target_role($conn, $id);

    if ($id == $user_id) {
        header("Location: users.php?error=" . urlencode("You cannot terminate your own account."));
        exit();
    }
    if (!can_act($role, $target_role)) {
        header("Location: users.php?error=" . urlencode("Insufficient permissions."));
        exit();
    }

    $now  = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE users SET status = '" . USER_TERMINATED . "', terminated_at = ?, termination_reason = ? WHERE id = ?");
    $stmt->bind_param("ssi", $now, $reason, $id);
    $stmt->execute();

    log_action($conn, $user_id, "Terminated user ID {$id}. Reason: {$reason}");
    $sf = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_STAFF_CHANGE . "','" . SEV_MEDIUM . "',?,'user',?)");
    $sf_msg = "Account terminated: user ID {$id}. Reason: {$reason}. By @" . ($_SESSION['username'] ?? 'admin') . ".";
    $sf->bind_param("is", $id, $sf_msg);
    $sf->execute();
    header("Location: users.php?success=" . urlencode("Account terminated."));
    exit();
}

// ── FORCE LOGOUT ──────────────────────────────────────────────────────────────
if ($action === 'force_logout') {
    $id          = intval($_POST['id']);
    $target_role = get_target_role($conn, $id);
    $actor_uname = $_SESSION['username'] ?? 'admin';

    if ($id == $user_id) {
        header("Location: users.php?error=" . urlencode("You cannot force-logout your own account."));
        exit();
    }
    // Admin → any non-protected role. Superadmin → those plus admin, but never another
    // superadmin or owner. can_act() already blocks admin from protected roles.
    if (!can_act($role, $target_role) || in_array($target_role, [ROLE_SUPERADMIN, ROLE_OWNER])) {
        header("Location: users.php?error=" . urlencode("You don't have permission to force-logout this account."));
        exit();
    }

    $now  = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE users SET force_logout_at = ?, force_logout_by = ?, force_logout_by_role = ? WHERE id = ? AND status != '" . USER_TERMINATED . "'");
    $stmt->bind_param("sssi", $now, $actor_uname, $role, $id);
    $stmt->execute();

    log_action($conn, $user_id, "Force-logged-out user ID {$id} ({$target_role}).");
    header("Location: users.php?success=" . urlencode("Account has been signed out. They will be notified."));
    exit();
}

// ── REINSTATE (superadmin only) ───────────────────────────────────────────────
if ($action === 'reinstate') {
    if ($role !== ROLE_SUPERADMIN) {
        header("Location: users.php?error=" . urlencode("Only Super Admin can reinstate accounts."));
        exit();
    }
    $id   = intval($_POST['id']);
    $stmt = $conn->prepare("UPDATE users SET status = '" . USER_ACTIVE . "', terminated_at = NULL, termination_reason = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    log_action($conn, $user_id, "Reinstated user ID {$id}.");
    header("Location: users.php?success=" . urlencode("Account reinstated successfully."));
    exit();
}

// ── RESET PASSWORD ────────────────────────────────────────────────────────────
if ($action === 'reset_password') {
    $id          = intval($_POST['id']);
    $new_pw      = $_POST['new_password']     ?? '';
    $confirm_pw  = $_POST['confirm_password'] ?? '';
    $target_role = get_target_role($conn, $id);

    if (!can_act($role, $target_role)) {
        header("Location: users.php?error=" . urlencode("Insufficient permissions."));
        exit();
    }
    if (strlen($new_pw) < 8) {
        header("Location: users.php?error=" . urlencode("Password must be at least 8 characters."));
        exit();
    }
    if ($new_pw !== $confirm_pw) {
        header("Location: users.php?error=" . urlencode("Passwords do not match."));
        exit();
    }

    $hash = password_hash($new_pw, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ?, reset_requested = 0 WHERE id = ?");
    $stmt->bind_param("si", $hash, $id);
    $stmt->execute();

    log_action($conn, $user_id, "Reset password for user ID {$id}.");
    $sf = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_STAFF_CHANGE . "','" . SEV_LOW . "',?,'user',?)");
    $sf_msg = "Password reset for user ID {$id} by @" . ($_SESSION['username'] ?? 'admin') . ".";
    $sf->bind_param("is", $id, $sf_msg);
    $sf->execute();
    header("Location: users.php?success=" . urlencode("Password reset successfully."));
    exit();
}

header("Location: users.php");
