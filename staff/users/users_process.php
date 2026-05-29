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
    $new_role   = strtolower(trim($_POST['role'] ?? 'staff'));

    if (strlen($username) < MIN_USERNAME_LENGTH || strlen($password) < MIN_PASSWORD_LENGTH) {
        header("Location: users.php?error=" . urlencode("Username min " . MIN_USERNAME_LENGTH . " chars, password min " . MIN_PASSWORD_LENGTH . " chars."));
        exit();
    }
    $admin_creatable = [ROLE_STAFF, ROLE_RECEIVER, ROLE_VALIDATOR, ROLE_PRICE_CHECKER];
    if ($role === ROLE_ADMIN && !in_array($new_role, $admin_creatable)) {
        header("Location: users.php?error=" . urlencode("Admins can only create Staff and Procurement Pipeline accounts."));
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

// ── PROCUREMENT ACCESS APPROVE / DENY ────────────────────────────────────────
if ($action === 'procurement_approve' || $action === 'procurement_deny') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        header("Location: ../dashboard.php?error=" . urlencode("Invalid request. Please try again."));
        exit();
    }
    $id = intval($_POST['id']);

    if ($action === 'procurement_deny') {
        $denial_reason = trim($_POST['denial_reason'] ?? '');
        $stmt = $conn->prepare("UPDATE users SET procurement_access = '" . PROC_DENIED . "', procurement_denial_reason = ? WHERE id = ? AND role = '" . ROLE_STAFF . "'");
        $stmt->bind_param("si", $denial_reason, $id);
        $stmt->execute();

        $sq = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $sq->bind_param("i", $id); $sq->execute();
        $staff_uname = $sq->get_result()->fetch_assoc()['username'] ?? '';
        $admin_uname = $_SESSION['username'] ?? '';

        $pal = $conn->prepare("INSERT INTO procurement_access_log (staff_id, staff_username, action, actioned_by, actioned_by_username) VALUES (?, ?, ?, ?, ?)");
        $procurement_action = PROC_DENIED;
        $pal->bind_param("isiss", $id, $staff_uname, $procurement_action, $user_id, $admin_uname);
        $pal->execute();

        $sf = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_ACCESS_EVENT . "','" . SEV_LOW . "',?,'user',?)");
        $sf_msg = "Procurement access denied for @{$staff_uname} by @{$admin_uname}.";
        $sf->bind_param("is", $id, $sf_msg);
        $sf->execute();

        log_action($conn, $user_id, "Procurement access denied for user ID {$id}.");
        header("Location: users.php?success=" . urlencode("Procurement access denied."));
        exit();
    }

    // Approve: redirect admin to create the supply voucher.
    // Nothing is written to the DB here — access is granted only after the voucher is saved in suppliers.php.
    $sq = $conn->prepare("SELECT username FROM users WHERE id = ? AND role = '" . ROLE_STAFF . "' AND procurement_access = '" . PROC_PENDING . "'");
    $sq->bind_param("i", $id); $sq->execute();
    $staff_row = $sq->get_result()->fetch_assoc();
    if (!$staff_row) {
        header("Location: users.php?error=" . urlencode("Request not found or already processed."));
        exit();
    }
    header("Location: ../suppliers/suppliers.php?procurement_for=" . $id . "&uname=" . urlencode($staff_row['username']));
    exit();
}

// ── CHANGE PROCUREMENT VOUCHER (superadmin only) ──────────────────────────────
if ($action === 'change_voucher') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        header("Location: users.php?error=" . urlencode("Invalid request."));
        exit();
    }
    if ($role !== ROLE_SUPERADMIN) {
        header("Location: users.php?error=" . urlencode("Only Super Admin can change procurement vouchers."));
        exit();
    }
    $target_id   = intval($_POST['id']);
    $supplier_id = intval($_POST['supplier_id']);

    $chk = $conn->prepare("SELECT username FROM users WHERE id = ? AND role = '" . ROLE_STAFF . "' AND procurement_access = '" . PROC_APPROVED . "'");
    $chk->bind_param("i", $target_id); $chk->execute();
    $target = $chk->get_result()->fetch_assoc();
    if (!$target) {
        header("Location: users.php?error=" . urlencode("Staff member not found or no active procurement."));
        exit();
    }

    $sq = $conn->prepare("SELECT id, name, invoice_number FROM suppliers WHERE id = ?");
    $sq->bind_param("i", $supplier_id); $sq->execute();
    $new_sup = $sq->get_result()->fetch_assoc();
    if (!$new_sup) {
        header("Location: users.php?error=" . urlencode("Selected supplier not found."));
        exit();
    }

    $target_uname = $target['username'];
    $admin_uname  = $_SESSION['username'] ?? '';

    $upd = $conn->prepare("UPDATE users SET locked_supplier_id = ? WHERE id = ?");
    $upd->bind_param("ii", $supplier_id, $target_id);
    $upd->execute();

    $pb_q = $conn->prepare("SELECT id FROM procurement_batches WHERE staff_id = ? AND status IN ('" . BATCH_APPROVED . "', 'encoding') ORDER BY created_at DESC LIMIT 1");
    $pb_q->bind_param("i", $target_id); $pb_q->execute();
    $pb_row = $pb_q->get_result()->fetch_assoc();
    if ($pb_row) {
        $pb_upd = $conn->prepare("UPDATE procurement_batches SET supplier_id = ?, supplier_name = ?, invoice = ? WHERE id = ?");
        $pb_upd->bind_param("issi", $supplier_id, $new_sup['name'], $new_sup['invoice_number'], $pb_row['id']);
        $pb_upd->execute();
    }

    log_action($conn, $user_id, "Changed procurement voucher for @{$target_uname} to supplier ID {$supplier_id} ({$new_sup['name']}).");
    header("Location: users.php?success=" . urlencode("Voucher updated for @{$target_uname}. Next batch will use: {$new_sup['name']}."));
    exit();
}

// ── CLEAR SUPERVISION FLAG (superadmin only) ─────────────────────────────────
if ($action === 'clear_supervision') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        header("Location: users.php?error=" . urlencode("Invalid request."));
        exit();
    }
    $approver_role = strtolower($_SESSION['role'] ?? '');
    if ($approver_role !== ROLE_SUPERADMIN) {
        header("Location: users.php?error=" . urlencode("Only Super Admin can clear supervision flags."));
        exit();
    }
    $id = intval($_POST['id']);
    $sq = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $sq->bind_param("i", $id); $sq->execute();
    $target_uname = $sq->get_result()->fetch_assoc()['username'] ?? '';

    $clr = $conn->prepare("UPDATE users SET supervision_flag='" . SUPERVISION_NONE . "', supervision_flagged_at=NULL WHERE id=?");
    $clr->bind_param("i", $id);
    $clr->execute();

    $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_USERS . "', ?, ?)");
    $lg_msg = "SUPERVISION FLAG CLEARED: @{$target_uname} (ID {$id}) cleared by @" . ($_SESSION['username'] ?? 'superadmin') . ".";
    $lg->bind_param("iis", $user_id, $id, $lg_msg);
    $lg->execute();

    header("Location: users.php?success=" . urlencode("Supervision flag cleared for @{$target_uname}."));
    exit();
}

header("Location: users.php");
