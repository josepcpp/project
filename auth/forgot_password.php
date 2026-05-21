<?php
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ABANDON: go back to step 1 ────────────────────────────────────────────────
if (isset($_GET['back'])) {
    unset($_SESSION['pw_uid'], $_SESSION['pw_needs_name'], $_SESSION['pw_needs_contact']);
    header("Location: forgot_password.php");
    exit();
}

$step          = 1;
$error         = '';
$success       = false;
$needs_name    = false;
$needs_contact = false;

// Restore step-2 state on page refresh
if (!empty($_SESSION['pw_uid'])) {
    $step          = 2;
    $needs_name    = $_SESSION['pw_needs_name']    ?? false;
    $needs_contact = $_SESSION['pw_needs_contact'] ?? false;
}

// ── STEP 1 POST: validate username ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '1') {
    $step     = 1;
    $username = trim($_POST['username'] ?? '');

    if (empty($username)) {
        $error = "Please enter your username.";
    } else {
        $stmt = $conn->prepare("SELECT id, status, full_name, contact_no FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            $error = "No account found with that username.";
        } elseif ($user['status'] === USER_TERMINATED) {
            $error = "This account has been terminated. Contact your administrator.";
        } else {
            $has_name    = !empty(trim($user['full_name']    ?? ''));
            $has_contact = !empty(trim($user['contact_no']   ?? ''));

            if (!$has_name && !$has_contact) {
                // No identity data on file — fall back to username-only reset
                $upd = $conn->prepare("UPDATE users SET reset_requested = 1 WHERE id = ?");
                $upd->bind_param("i", $user['id']);
                $upd->execute();
                $success = true;
            } else {
                $_SESSION['pw_uid']           = $user['id'];
                $_SESSION['pw_needs_name']    = $has_name;
                $_SESSION['pw_needs_contact'] = $has_contact;
                $step          = 2;
                $needs_name    = $has_name;
                $needs_contact = $has_contact;
            }
        }
    }
}

// ── STEP 2 POST: verify identity ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '2') {
    $step = 2;
    $uid  = $_SESSION['pw_uid'] ?? null;

    if (!$uid) {
        $step  = 1;
        $error = "Session expired. Please start over.";
        unset($_SESSION['pw_uid'], $_SESSION['pw_needs_name'], $_SESSION['pw_needs_contact']);
    } else {
        $needs_name    = $_SESSION['pw_needs_name']    ?? false;
        $needs_contact = $_SESSION['pw_needs_contact'] ?? false;
        $input_name    = trim($_POST['full_name']  ?? '');
        $input_contact = trim($_POST['contact_no'] ?? '');

        $stmt = $conn->prepare("SELECT full_name, contact_no FROM users WHERE id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            $step  = 1;
            $error = "Account not found. Please start over.";
            unset($_SESSION['pw_uid'], $_SESSION['pw_needs_name'], $_SESSION['pw_needs_contact']);
        } else {
            // Each field is only checked if the account has that data on file
            $name_ok    = !$needs_name    || (strcasecmp(trim($user['full_name']  ?? ''), $input_name)    === 0);
            $contact_ok = !$needs_contact || (trim($user['contact_no'] ?? '') === $input_contact);

            if ($name_ok && $contact_ok) {
                $upd = $conn->prepare("UPDATE users SET reset_requested = 1 WHERE id = ?");
                $upd->bind_param("i", $uid);
                $upd->execute();
                unset($_SESSION['pw_uid'], $_SESSION['pw_needs_name'], $_SESSION['pw_needs_contact']);
                $success = true;
            } else {
                $error = "Identity verification failed. The details you entered do not match our records.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Business ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/project/assets/css/style.css">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="min-h-screen flex items-center justify-center p-6 bg-slate-50">

<?php if ($success): ?>
<!-- ── SUCCESS ──────────────────────────────────────────────────────────────── -->
<div class="w-full max-w-[420px] bg-white rounded-[2.5rem] shadow-2xl shadow-slate-200/60 p-12 text-center">
    <div class="w-16 h-16 bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
        <svg class="w-8 h-8 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
        </svg>
    </div>
    <h2 class="text-2xl font-bold text-slate-800 mb-3">Identity Verified</h2>
    <p class="text-slate-500 text-sm mb-2">Your password reset request has been submitted.</p>
    <p class="text-slate-400 text-xs mb-8">Your administrator will set a new temporary password for you. Please check with them directly.</p>
    <a href="login.php" class="block bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-4 rounded-2xl transition-all text-sm uppercase tracking-widest">
        Back to Login
    </a>
</div>

<?php elseif ($step === 2): ?>
<!-- ── STEP 2: IDENTITY VERIFICATION ───────────────────────────────────────── -->
<div class="w-full max-w-[420px] bg-white rounded-[2.5rem] shadow-2xl shadow-slate-200/60 p-12">

    <!-- Back + Step indicator -->
    <div class="flex items-center justify-between mb-8">
        <a href="forgot_password.php?back=1"
           class="text-slate-400 hover:text-slate-600 text-xs font-bold uppercase tracking-widest transition-colors">
            &larr; Back
        </a>
        <div class="flex items-center gap-2">
            <span class="w-6 h-1.5 rounded-full bg-emerald-500"></span>
            <span class="w-6 h-1.5 rounded-full bg-slate-900"></span>
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Step 2 of 2</span>
        </div>
    </div>

    <!-- Shield icon -->
    <div class="w-14 h-14 bg-slate-100 rounded-2xl flex items-center justify-center mb-6">
        <svg class="w-7 h-7 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
    </div>

    <h1 class="text-2xl font-bold text-slate-800 mb-2">Verify Your Identity</h1>
    <p class="text-slate-400 text-sm mb-8">
        Enter the details registered to your account to confirm your identity.
    </p>

    <?php if ($error): ?>
    <div class="flex items-center gap-2 text-red-500 text-xs bg-red-50 py-3 px-4 rounded-xl border border-red-100 mb-6 font-bold">
        <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-5">
        <input type="hidden" name="step" value="2">

        <?php if ($needs_name): ?>
        <div>
            <label class="block text-slate-700 font-bold mb-2 text-sm">
                Full Name <span class="text-red-500">*</span>
            </label>
            <div class="input-box rounded-2xl flex items-center px-4 py-4 gap-3">
                <svg class="h-5 w-5 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <input type="text" name="full_name" placeholder="Your registered full name"
                       autocomplete="off" required
                       class="bg-transparent border-none outline-none w-full text-slate-700 placeholder:text-slate-400 font-medium focus:ring-0">
            </div>
        </div>
        <?php endif; ?>

        <?php if ($needs_contact): ?>
        <div>
            <label class="block text-slate-700 font-bold mb-2 text-sm">
                Contact Number <span class="text-red-500">*</span>
            </label>
            <div class="input-box rounded-2xl flex items-center px-4 py-4 gap-3">
                <svg class="h-5 w-5 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
                <input type="text" name="contact_no" placeholder="Your registered contact number"
                       autocomplete="off" required
                       class="bg-transparent border-none outline-none w-full text-slate-700 placeholder:text-slate-400 font-medium focus:ring-0">
            </div>
        </div>
        <?php endif; ?>

        <!-- What we verify notice -->
        <div class="bg-slate-50 rounded-2xl px-4 py-3 flex gap-3 items-start">
            <svg class="w-4 h-4 text-slate-400 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-[11px] text-slate-400 font-medium leading-relaxed">
                <?php
                $fields = [];
                if ($needs_name)    $fields[] = 'full name';
                if ($needs_contact) $fields[] = 'contact number';
                echo 'We will verify your ' . implode(' and ', $fields) . ' against your registered account details.';
                ?>
            </p>
        </div>

        <button type="submit"
                class="btn-emerald w-full text-white font-bold py-4 rounded-2xl shadow-xl shadow-emerald-100 text-sm uppercase tracking-widest">
            Verify &amp; Submit Request
        </button>
    </form>
</div>

<?php else: ?>
<!-- ── STEP 1: USERNAME ─────────────────────────────────────────────────────── -->
<div class="w-full max-w-[420px] bg-white rounded-[2.5rem] shadow-2xl shadow-slate-200/60 p-12">

    <!-- Back + Step indicator -->
    <div class="flex items-center justify-between mb-8">
        <a href="login.php"
           class="text-slate-400 hover:text-slate-600 text-xs font-bold uppercase tracking-widest transition-colors">
            &larr; Back to Login
        </a>
        <div class="flex items-center gap-2">
            <span class="w-6 h-1.5 rounded-full bg-slate-900"></span>
            <span class="w-6 h-1.5 rounded-full bg-slate-200"></span>
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Step 1 of 2</span>
        </div>
    </div>

    <h1 class="text-2xl font-bold text-slate-800 mb-2">Forgot Password?</h1>
    <p class="text-slate-400 text-sm mb-8">
        Enter your username to begin. You will then be asked to verify your identity before a reset is requested.
    </p>

    <?php if ($error): ?>
    <div class="flex items-center gap-2 text-red-500 text-xs bg-red-50 py-3 px-4 rounded-xl border border-red-100 mb-6 font-bold">
        <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="step" value="1">
        <div class="mb-6">
            <label class="block text-slate-700 font-bold mb-2 text-sm">
                Username <span class="text-red-500">*</span>
            </label>
            <div class="input-box rounded-2xl flex items-center px-4 py-4 gap-3">
                <svg class="h-5 w-5 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <input type="text" name="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES) ?>"
                       placeholder="Your login username" required autocomplete="username"
                       class="bg-transparent border-none outline-none w-full text-slate-700 placeholder:text-slate-400 font-medium focus:ring-0">
            </div>
        </div>
        <button type="submit"
                class="btn-emerald w-full text-white font-bold py-4 rounded-2xl shadow-xl shadow-emerald-100 text-sm uppercase tracking-widest">
            Continue
        </button>
    </form>
</div>
<?php endif; ?>

</body>
</html>
