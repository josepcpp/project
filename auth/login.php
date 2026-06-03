<?php
session_start();
$error = $_SESSION['error'] ?? "";
unset($_SESSION['error']);

// Force-logout fallback banner (set by auth_check.php when an admin signs you out
// and JS didn't already show the in-app modal).
$forced       = isset($_GET['forced']) && $_GET['forced'] === '1';
$forced_by    = trim($_GET['by'] ?? '');
$forced_role  = trim($_GET['by_role'] ?? '');
$role_labels  = [
    'superadmin' => 'Super Admin', 'admin' => 'Administrator', 'owner' => 'Owner',
    'staff' => 'Staff', 'receiver' => 'Receiver', 'validator' => 'Validator', 'price_checker' => 'Price Checker',
];
$forced_role_label = $role_labels[strtolower($forced_role)] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Business ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/project/assets/css/style.css">
</head>
<body class="min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-[480px] bg-white rounded-[2.5rem] shadow-2xl shadow-slate-200/60 p-12">
        
        <!-- Force-logout notice -->
        <?php if ($forced): ?>
            <div class="mb-8 bg-orange-50 border border-orange-200 rounded-2xl px-5 py-4 flex items-start gap-3">
                <svg class="h-5 w-5 text-orange-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                <div>
                    <p class="text-sm font-bold text-orange-700">You were signed out
                        <?php if ($forced_by !== ''): ?>by <span class="font-black">@<?= htmlspecialchars($forced_by, ENT_QUOTES, 'UTF-8') ?></span><?php if ($forced_role_label): ?> (<?= htmlspecialchars($forced_role_label, ENT_QUOTES, 'UTF-8') ?>)<?php endif; ?><?php else: ?>by an administrator<?php endif; ?>.
                    </p>
                    <p class="text-xs font-bold text-orange-500 mt-0.5">You can sign back in below.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Header Section -->
        <div class="mb-10">
            <h1 class="text-3xl font-bold text-slate-800">Welcome Back</h1>
            <p class="text-slate-500 mt-2">Please enter your credentials to access your dashboard.</p>
        </div>
        
        <form action="login_process.php" method="POST" id="loginForm">
            
            <!-- EMAIL / USERNAME -->
            <div class="mb-6">
                <label class="block text-slate-700 font-bold mb-2 text-sm">Username or Email <span class="text-red-500">*</span></label>
                <div class="input-box rounded-2xl flex items-center px-4 py-4 gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <input type="text" name="username" placeholder="Email or Username" required
                        class="bg-transparent border-none outline-none w-full text-slate-700 placeholder:text-slate-400 font-medium focus:ring-0">
                </div>
            </div>

            <!-- PASSWORD -->
            <div class="mb-6">
                <label class="block text-slate-700 font-bold mb-2 text-sm">Password <span class="text-red-500">*</span></label>
                <div class="input-box rounded-2xl flex items-center px-4 py-4 gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <input type="password" id="passwordInput" name="password" placeholder="Enter your password" required
                        class="bg-transparent border-none outline-none w-full text-slate-700 placeholder:text-slate-400 font-medium focus:ring-0">
                    <button type="button" onclick="togglePassword()"
                            class="flex-shrink-0 text-slate-300 hover:text-slate-500 transition-colors focus:outline-none"
                            aria-label="Toggle password visibility">
                        <!-- Eye — shown when password is hidden -->
                        <svg id="iconEye" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <!-- Eye-off — shown when password is visible -->
                        <svg id="iconEyeOff" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- SUBMIT BUTTON -->
            <button type="submit" name="login" class="btn-emerald w-full text-white font-bold py-5 rounded-2xl shadow-xl shadow-emerald-100 flex items-center justify-center text-lg">
                Sign In
            </button>

            <div class="text-center mt-4">
                <a href="forgot_password.php" class="text-slate-400 hover:text-emerald-500 text-xs font-bold uppercase tracking-widest transition-colors">
                    Forgot Password?
                </a>
            </div>

            <!-- ERROR MESSAGE -->
            <?php if($error): ?>
                <div class="flex items-center gap-2 text-red-500 text-xs justify-center mt-6 font-bold bg-red-50 py-3 px-4 rounded-xl border border-red-100 italic">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

        </form>
    </div>

<script>
function togglePassword() {
    var input      = document.getElementById('passwordInput');
    var iconEye    = document.getElementById('iconEye');
    var iconEyeOff = document.getElementById('iconEyeOff');
    if (input.type === 'password') {
        input.type = 'text';
        iconEye.classList.add('hidden');
        iconEyeOff.classList.remove('hidden');
    } else {
        input.type = 'password';
        iconEye.classList.remove('hidden');
        iconEyeOff.classList.add('hidden');
    }
}
</script>
</body>
</html>