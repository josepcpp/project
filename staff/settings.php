<?php
include '../config/db.php';
include '../includes/superadmin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

include 'layout_top.php';
?>

<div class="max-w-4xl mx-auto pb-20 animate-in">
    <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm flex flex-col items-center justify-center py-24 gap-4">
        <div class="w-14 h-14 bg-slate-100 rounded-2xl flex items-center justify-center">
            <svg class="w-7 h-7 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <circle cx="12" cy="12" r="3"/>
            </svg>
        </div>
        <p class="font-black text-slate-700 text-lg">App Settings</p>
        <p class="text-slate-400 text-sm font-medium">Configuration options coming soon.</p>
    </div>
</div>

<?php include 'layout_bottom.php'; ?>
