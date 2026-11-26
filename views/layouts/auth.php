<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= e($title ?? 'DoodhBee - Multi-Tenant Dairy SaaS') ?></title>
    <!-- Favicon & PWA -->
    <link rel="icon" type="image/x-icon" href="<?= asset('public/favicon.ico') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('public/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('public/favicon-16x16.png') ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('public/apple-touch-icon.png') ?>">
    <link rel="manifest" href="<?= asset('public/site.webmanifest') ?>">
    <meta name="theme-color" content="#e8ab06">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .tap-target { min-height: 48px; }
    </style>
</head>
<body class="h-full flex flex-col justify-center items-center px-4 py-8 bg-slate-900 text-white">
    <div class="w-full max-w-md">
        <!-- Logo -->
        <div class="text-center mb-6">
            <a href="<?= url('/') ?>" class="inline-block group transition transform hover:scale-105 duration-200 mb-3">
                <img src="<?= asset('public/assets/images/logo/doodhbee-logo-256.png') ?>" alt="DoodhBee Logo" class="w-24 h-24 rounded-2xl mx-auto shadow-2xl border border-amber-400/30 object-cover">
            </a>
            <h1 class="text-2xl font-black tracking-tight text-white flex items-center justify-center gap-2">
                <span>DoodhBee</span>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/30">SaaS</span>
            </h1>
            <p class="text-sm text-slate-400 mt-1">Multi-Tenant Milk Delivery & Zero-Dispute Invoicing</p>
        </div>

        <?= $content ?>

        <?php if (isDemoMode()): ?>
        <!-- Quick Switcher on Auth (Only in Demo/Dev Mode) -->
        <div class="mt-8 pt-6 border-t border-slate-800 text-center">
            <p class="text-xs text-slate-400 mb-2 font-medium">⚡ Quick Demo One-Click Access:</p>
            <div class="flex flex-wrap items-center justify-center gap-2">
                <a href="<?= url('/demo/tenant1') ?>" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-blue-400 text-xs font-semibold border border-slate-700 transition">
                    🥛 Krishna Dairy (Paid)
                </a>
                <a href="<?= url('/demo/tenant2') ?>" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-emerald-400 text-xs font-semibold border border-slate-700 transition">
                    🐄 Radha Dairy (Free 4/5)
                </a>
                <a href="<?= url('/demo/admin') ?>" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-purple-400 text-xs font-semibold border border-slate-700 transition">
                    🛡️ Super Admin
                </a>
            </div>
        </div>
        <?php endif; ?>

        <footer class="mt-8 pt-4 border-t border-slate-800 text-center text-xs text-slate-400">
            © <?= date('Y') ?> <strong class="text-slate-200">Shri Ram Open Source Technologies</strong>. All rights reserved.
        </footer>
    </div>
</body>
</html>
