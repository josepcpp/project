<?php
session_start();
$error   = $_SESSION['signup_error'] ?? '';
$success = $_SESSION['signup_success'] ?? '';
unset($_SESSION['signup_error'], $_SESSION['signup_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | Business ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/project/assets/css/style.css">
</head>
<body class="min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-[480px] bg-white rounded-[2.5rem] shadow-2xl shadow-slate-200/60 p-12">

        <div class="mb-10">
            <h1 class="text-3xl font-bold text-slate-800">Join as a Member</h1>
            <p class="text-slate-500 mt-2">Create your account to get started.</p>
        </div>

        <form action="signup_process.php" method="POST" class="space-y-6">

            <div>
                <label class="block text-slate-700 font-bold mb-2 text-sm">Username <span class="text-red-500">*</span></label>
                <div class="input-box rounded-2xl flex items-center px-4 py-4 gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <input type="text" name="username" placeholder="Choose a username" required minlength="3" maxlength="50"
                        class="bg-transparent border-none outline-none w-full text-slate-700 placeholder:text-slate-400 font-medium focus:ring-0">
                </div>
            </div>

            <div>
                <label class="block text-slate-700 font-bold mb-2 text-sm">Password <span class="text-red-500">*</span></label>
                <div class="input-box rounded-2xl flex items-center px-4 py-4 gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <input type="password" name="password" placeholder="Min. 8 characters" required minlength="8"
                        class="bg-transparent border-none outline-none w-full text-slate-700 placeholder:text-slate-400 font-medium focus:ring-0">
                </div>
            </div>

            <button type="submit" class="btn-emerald w-full text-white font-bold py-5 rounded-2xl shadow-xl shadow-emerald-100 flex items-center justify-center text-lg">
                Create My Account
            </button>

            <?php if ($error): ?>
                <div class="flex items-center gap-2 text-red-500 text-xs justify-center mt-4 font-bold bg-red-50 py-3 px-4 rounded-xl border border-red-100 italic">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="text-emerald-600 text-xs text-center font-bold bg-emerald-50 py-3 px-4 rounded-xl border border-emerald-100">
                    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

        </form>

        <p class="text-center text-sm text-slate-500 mt-8">
            Already have an account? <a href="login.php" class="text-emerald-600 font-bold hover:underline">Sign in</a>
        </p>

    </div>

</body>
</html>
