<!DOCTYPE html>
<html lang="en">
<head>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@900&family=Inter:wght@400;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',sans-serif; background-image: radial-gradient(#e2e8f0 1.5px, transparent 1.5px); background-size: 30px 30px;}</style>
</head>
<body class="min-h-screen flex items-center justify-center p-6 text-center">
    <div class="max-w-3xl w-full space-y-10">
        <h1 class="font-['Playfair_Display'] text-6xl font-black text-slate-900">Check Stock Status</h1>
        <p class="text-slate-400 font-medium italic">Find out if your favorite items are ready for pickup.</p>
        
        <form method="GET" class="relative">
            <input type="text" name="q" placeholder="Enter Product Name..." class="w-full bg-white border-none rounded-full px-12 py-8 text-2xl shadow-2xl shadow-slate-200 outline-none focus:ring-4 focus:ring-emerald-500/10 transition-all">
            <button class="absolute right-4 top-4 bg-emerald-500 text-white p-4 rounded-full shadow-lg">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            </button>
        </form>

        <!-- Result Box (Hidden until search) -->
        <?php if(isset($_GET['q'])): ?>
        <div class="bg-white p-12 rounded-[4rem] shadow-sm border border-slate-50 flex justify-between items-center">
            <div class="text-left">
                <h3 class="text-3xl font-black text-slate-800">Fresh Milk 1L</h3>
                <p class="text-emerald-500 font-bold uppercase tracking-widest text-xs">Dairy Section</p>
            </div>
            <span class="bg-emerald-50 text-emerald-600 px-8 py-3 rounded-full font-black uppercase text-sm tracking-tighter">Available Now</span>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>