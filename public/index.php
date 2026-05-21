<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cynthia Bersabe Grocery</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        emerald: { 500: '#00a651', 600: '#008a44' }
                    }
                }
            }
        }
    </script>

    <style>
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
        .serif-title { font-family: 'Playfair Display', serif; color: #0f172a; }
        .dot-pattern {
            background-image: radial-gradient(#e2e8f0 1.5px, transparent 1.5px);
            background-size: 30px 30px;
        }
        .text-gradient-emerald {
            background: linear-gradient(to right, #00a651, #06b6d4);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .card-purple { background: linear-gradient(135deg, #faf5ff 0%, #fdf2f8 100%); border-color: #f3e8ff; }
        .card-emerald { background: linear-gradient(135deg, #f0fdf4 0%, #f0fdfa 100%); border-color: #dcfce7; }
        .card-blue { background: linear-gradient(135deg, #f5f3ff 0%, #eef2ff 100%); border-color: #e0e7ff; }
        .btn-emerald { background-color: #00a651; transition: all 0.3s ease; }
        .btn-emerald:hover { background-color: #008a44; transform: translateY(-2px); }
        svg { max-width: 100%; height: auto; }
    </style>
</head>
<body class="dot-pattern min-h-screen">

    <!-- Navigation -->
    <header class="max-w-7xl mx-auto px-6 py-8 flex justify-between items-center sticky top-0 bg-white/80 backdrop-blur-md z-50">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 flex-shrink-0 bg-emerald-500 rounded-2xl flex items-center justify-center text-white shadow-lg shadow-green-100">
                <div class="w-6 h-6">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </div>
            </div>
            <div>
                <h2 class="serif-title text-2xl leading-none font-bold">Cynthia Bersabe</h2>
                <span class="text-sm text-slate-400 font-medium">Grocey Store</span>
            </div>
        </div>
        
        <nav class="hidden md:flex items-center gap-10 font-semibold text-slate-600">
            <a href="index.php" class="text-emerald-500">Home</a>
            <a href="#about" class="hover:text-emerald-500 transition-colors">About</a>
            <a href="#" class="hover:text-emerald-500 transition-colors">Contact</a>
        </nav>

        <a href="../auth/login.php" class="btn-emerald text-white px-8 py-3 rounded-full font-bold shadow-lg shadow-green-200">
            Login
        </a>
    </header>

    <!-- Hero Section -->
    <main class="max-w-7xl mx-auto px-6 pt-12 pb-24 grid grid-cols-1 lg:grid-cols-2 items-center gap-16">
        <div class="space-y-8">
            <!-- Professional Subtitle -->
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-emerald-50 border border-emerald-100 text-emerald-600 text-[10px] font-black uppercase tracking-[0.2em]">
                System Overview & Research Fulfillment
            </div>
            
            <!-- Scaled Headline -->
            <h1 class="serif-title text-4xl md:text-5xl lg:text-6xl leading-[1.15] font-black text-slate-900">
                Integrated <span class="text-gradient-emerald">Inventory</span> & <br>
                <span class="text-gradient-emerald">Payment Verification</span> System
            </h1>

            <!-- Sub-headline -->
            <p class="text-emerald-600 font-bold text-sm md:text-base tracking-tight italic border-l-4 border-emerald-500 pl-4">
                A Solution for Cynthia Bersabe Grocery Operational Streamlining.
            </p>

            <!-- Detailed Description -->
            <div class="space-y-4">
                <p class="text-base md:text-lg text-slate-500 leading-relaxed text-justify">
                    This study focuses on the development of the <strong>Point-of-Sale Integrated Inventory System (POSIIS)</strong> to improve the efficiency of operations at Cynthia Bersabe Grocery, a small business functioning as both wholesaler and retailer. 
                </p>
                <p class="text-base md:text-lg text-slate-500 leading-relaxed text-justify">
                    The system was created to address issues caused by manual, paper-based sales and inventory processes that often lead to errors, delays, and data inconsistencies. 
                    <span class="text-slate-800 font-semibold">POSIIS automates transaction recording, updates inventory in real time</span> through barcode scanning, and includes a Payment Verification feature to ensure accurate tracking of supplier and distributor payments.
                </p>
                <p class="text-sm font-medium text-slate-400 leading-relaxed">
                    Overall, the integration of these features reduces human error, lessens manual workload, supports better decision-making, and promotes sustainable practices by minimizing paper use and encouraging technological innovation.
                </p>
            </div>

            <div class="pt-4">
                <a href="../auth/login.php" class="btn-emerald text-white px-10 py-4 rounded-full font-bold text-lg inline-flex items-center gap-3 shadow-xl shadow-green-200/50">
                    Access System Portal
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </a>
            </div>
        </div>

        <div class="relative flex justify-center items-center">
            <div class="absolute -top-12 right-12 bg-gradient-to-br from-pink-400 to-purple-600 w-28 h-28 rounded-[2rem] rotate-12 opacity-90 blur-[0.5px] shadow-2xl"></div>
            <div class="absolute bottom-12 -left-8 bg-gradient-to-br from-orange-400 to-yellow-500 w-20 h-20 rounded-2xl -rotate-12 shadow-xl"></div>
            <div class="absolute top-1/4 -right-8 bg-gradient-to-br from-blue-400 to-cyan-500 w-16 h-16 rounded-2xl rotate-6 shadow-lg"></div>
            
            <div class="relative p-8 bg-white/40 backdrop-blur-xl rounded-[4rem] border border-white/80 shadow-2xl overflow-hidden min-w-[320px] md:min-w-[450px] aspect-square flex items-center justify-center">
                <svg class="w-64 h-64 text-slate-200 opacity-40" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/>
                </svg>
            </div>
        </div>
    </main>

    <!-- Features Section -->
    <section class="max-w-7xl mx-auto px-6 pb-24 grid grid-cols-1 md:grid-cols-3 gap-10">
        <div class="card-purple p-12 rounded-[3.5rem] border transition-all hover:scale-105 hover:shadow-2xl">
            <h3 class="serif-title text-2xl font-bold mb-4 text-purple-900">Premium Quality</h3>
            <p class="text-slate-500 text-lg leading-relaxed">Handpicked fresh groceries from trusted local suppliers daily</p>
        </div>
        <div class="card-emerald p-12 rounded-[3.5rem] border transition-all hover:scale-105 hover:shadow-2xl">
            <h3 class="serif-title text-2xl font-bold mb-4 text-emerald-900">Flash Deals</h3>
            <p class="text-slate-500 text-lg leading-relaxed">Exclusive daily deals and special promotions for members</p>
        </div>
        <div class="card-blue p-12 rounded-[3.5rem] border transition-all hover:scale-105 hover:shadow-2xl">
            <h3 class="serif-title text-2xl font-bold mb-4 text-blue-900">Secure Payment</h3>
            <p class="text-slate-500 text-lg leading-relaxed">Safe and encrypted transactions for peace of mind</p>
        </div>
    </section>

    <section id="about" class="max-w-7xl mx-auto px-6 pb-32">
        <div class="bg-slate-900 rounded-[4rem] p-12 md:p-20 shadow-2xl relative overflow-hidden text-white">
            <!-- Background Decoration -->
            <div class="absolute -right-20 -top-20 w-80 h-80 bg-emerald-500/10 rounded-full blur-3xl"></div>
            <div class="absolute -left-20 -bottom-20 w-80 h-80 bg-blue-500/10 rounded-full blur-3xl"></div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-20 relative z-10">
                <!-- Text Area -->
                <div class="space-y-8">
                    <div>
                        <h2 class="serif-title text-white text-5xl font-black mb-6 tracking-tight">Academic Research & <span class="text-emerald-400 underline decoration-emerald-500/30">Development</span></h2>
                        <p class="text-slate-400 text-lg leading-relaxed">
                            The project aims to address real-world operational challenges faced by <strong>Cynthia Bersabe Grocery</strong>, a local business that operates both as a wholesaler and retailer.
                        </p>
                    </div>
                    
                    <div class="space-y-4 text-slate-400 leading-relaxed">
                        <p>The system was developed in fulfillment of the subject <strong>Fundamentals of Research</strong>, and serves as a practical demonstration of how information technology can streamline business processes, reduce human error, and promote digital transformation in small-scale enterprises.</p>
                        <p class="text-sm italic p-6 bg-white/5 rounded-3xl border border-white/10 text-slate-300">
                            "This system is strictly for academic purposes and is not intended for commercial distribution. It reflects the students’ commitment to applying theoretical knowledge to real-world scenarios, and showcases their skills in system analysis, design, development, and deployment using Laravel, PHP, MySQL, HTML, CSS, and JavaScript."
                        </p>
                    </div>
                </div>

                <!-- Team Area -->
                <div class="bg-white/5 backdrop-blur-sm p-10 rounded-[3rem] border border-white/10">
                    <h3 class="serif-title text-white text-3xl font-bold mb-2">Development Team</h3>
                    <p class="text-emerald-400 font-black text-xs uppercase tracking-[0.3em] mb-4">Diploma in Information Technology</p>
                    <p class="text-slate-400 text-sm mb-10">Polytechnic University of the Philippines – Maragondon Campus</p>

                    <div class="space-y-4">
                        <?php 
                        $team = ["Kyle S. Anchores", "Joseph Christian B. Polido", "Marco M. Sisit", "Arvin P. Britos", "Carl Lester R. Barquillo"];
                        foreach($team as $member): 
                        ?>
                        <div class="flex items-center gap-4 group">
                            <div class="w-10 h-10 bg-white/10 rounded-xl flex items-center justify-center text-emerald-400 font-black group-hover:bg-emerald-500 group-hover:text-white transition-all">
                                <?= substr($member, 0, 1) ?>
                            </div>
                            <span class="text-lg font-bold text-slate-200 group-hover:text-white transition-colors"><?= $member ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

</body>
</html>