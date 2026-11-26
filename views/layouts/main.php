<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
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
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Alpine.js & HTMX (Local Vendored with CDN Fallback) -->
    <script defer src="<?= asset('public/assets/js/alpine.min.js') ?>" onerror="this.src='https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js'"></script>
    <script src="<?= asset('public/assets/js/htmx.min.js') ?>" onerror="this.src='https://unpkg.com/htmx.org@1.9.10'"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            900: '#1e3a8a',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        /* High sunlight contrast and thumb tap targets */
        .tap-target {
            min-height: 48px;
            min-width: 48px;
        }
        body {
            -webkit-tap-highlight-color: transparent;
        }
    </style>
</head>
<body class="h-full flex flex-col text-slate-900 bg-slate-50 pb-20 md:pb-6" hx-headers='{"X-CSRF-Token": "<?= \App\Core\Auth::csrfToken() ?>"}'>
    <?php
    $isDelivery = \App\Core\Auth::isDeliveryBoy();
    $currentTenant = \App\Core\Auth::tenant();
    $userName = $currentTenant['user_name'] ?? $currentTenant['owner_name'] ?? 'Milkman';
    $assignedRoute = $currentTenant['assigned_route_name'] ?? null;
    ?>
    <!-- Top Header -->
    <header class="sticky top-0 z-40 bg-white border-b border-slate-200 shadow-sm">
        <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
            <!-- Brand -->
            <div class="flex items-center gap-3">
                <a href="<?= $isDelivery ? url('/deliveries') : url('/') ?>" class="flex items-center gap-2.5 group">
                    <img src="<?= asset('public/assets/images/logo/doodhbee-icon-rounded.png') ?>" alt="DoodhBee" class="w-10 h-10 rounded-xl shadow-md group-hover:scale-105 transition transform">
                    <div>
                        <div class="flex items-center gap-1.5">
                            <span class="font-black text-slate-900 tracking-tight leading-none text-base sm:text-lg">DoodhBee</span>
                            <?php if ($isDelivery): ?>
                                <span class="px-2 py-0.5 text-[10px] font-bold bg-amber-100 text-amber-800 rounded-full border border-amber-300">🛵 Rider</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-[11px] font-semibold text-amber-700 truncate max-w-[140px] sm:max-w-xs">
                            <?= e($currentTenant['business_name'] ?? 'Dairy SaaS') ?><?= ($isDelivery && $assignedRoute) ? ' · ' . e($assignedRoute) : '' ?>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Desktop Nav -->
            <nav class="hidden md:flex items-center gap-1 font-semibold text-sm">
                <?php if (!$isDelivery): ?>
                <a href="<?= url('/') ?>" class="px-3 py-2 rounded-lg transition <?= isActiveRoute('/') && !isActiveRoute('/deliveries') && !isActiveRoute('/customers') && !isActiveRoute('/invoices') && !isActiveRoute('/settings') ? 'bg-blue-50 text-blue-700 font-bold' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' ?>">
                    Dashboard
                </a>
                <?php endif; ?>
                <a href="<?= url('/deliveries') ?>" class="px-3 py-2 rounded-lg transition <?= isActiveRoute('/deliveries') ? 'bg-blue-50 text-blue-700 font-bold' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' ?>">
                    📋 Run-Sheet
                </a>
                <?php if (!$isDelivery): ?>
                <a href="<?= url('/customers') ?>" class="px-3 py-2 rounded-lg transition <?= isActiveRoute('/customers') ? 'bg-blue-50 text-blue-700 font-bold' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' ?>">
                    👥 Customers
                </a>
                <a href="<?= url('/invoices') ?>" class="px-3 py-2 rounded-lg transition <?= isActiveRoute('/invoices') ? 'bg-blue-50 text-blue-700 font-bold' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' ?>">
                    🧾 Invoices
                </a>
                <a href="<?= url('/settings') ?>" class="px-3 py-2 rounded-lg transition <?= isActiveRoute('/settings') ? 'bg-blue-50 text-blue-700 font-bold' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' ?>">
                    ⚙️ Settings & Taxes
                </a>
                <?php endif; ?>
            </nav>

            <!-- User Menu & Quota Badge -->
            <div class="flex items-center gap-2">
                <?php if (!$isDelivery): ?>
                <a href="<?= url('/subscription') ?>" class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200 hover:bg-amber-100 transition">
                    <span>⚡ Quota & Plan</span>
                </a>
                <?php endif; ?>
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-slate-100 transition text-sm font-semibold">
                        <span class="w-8 h-8 rounded-full <?= $isDelivery ? 'bg-amber-100 text-amber-800' : 'bg-slate-200 text-slate-700' ?> flex items-center justify-center font-bold">
                            <?= strtoupper(substr($userName, 0, 1)) ?>
                        </span>
                        <span class="hidden lg:inline text-slate-800"><?= e($userName) ?></span>
                    </button>
                    <div x-show="open" @click.away="open = false" x-cloak class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-xl border border-slate-200 py-1.5 z-50 text-sm">
                        <div class="px-4 py-2 border-b border-slate-100">
                            <p class="font-bold text-slate-900 truncate"><?= e($currentTenant['business_name'] ?? '') ?></p>
                            <p class="text-xs text-slate-500 font-mono"><?= e($userName) ?> (<?= $isDelivery ? 'Delivery Rider' : 'Admin' ?>)</p>
                            <?php if ($isDelivery && $assignedRoute): ?>
                                <p class="text-[11px] text-amber-700 font-semibold mt-0.5">Route: <?= e($assignedRoute) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isDelivery): ?>
                        <a href="<?= url('/subscription') ?>" class="flex items-center px-4 py-2.5 text-slate-700 hover:bg-slate-50">⚡ Quota & Upgrade</a>
                        <a href="<?= url('/settings') ?>" class="flex items-center px-4 py-2.5 text-slate-700 hover:bg-slate-50">⚙️ Surcharges & Taxes</a>
                        <div class="border-t border-slate-100 my-1"></div>
                        <?php endif; ?>
                        <a href="<?= url('/logout') ?>" class="flex items-center px-4 py-2.5 text-rose-600 hover:bg-rose-50 font-semibold">Sign Out</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Flash Messages -->
    <?php if (!empty($success)): ?>
        <div class="max-w-6xl mx-auto px-4 mt-4 w-full">
            <div class="p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl flex items-center justify-between text-sm font-semibold shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="text-emerald-600 text-base">✓</span>
                    <span><?= e($success) ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-800 font-bold px-2">×</button>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="max-w-6xl mx-auto px-4 mt-4 w-full">
            <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl flex items-center justify-between text-sm font-semibold shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="text-rose-600 text-base">⚠</span>
                    <span><?= e($error) ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-800 font-bold px-2">×</button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="flex-1 max-w-6xl w-full mx-auto px-4 py-6">
        <?= $content ?>
    </main>

    <!-- Mobile Bottom Navigation (Thumb-friendly) -->
    <div class="md:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 px-2 py-1.5 z-40 shadow-lg flex justify-around items-center">
        <?php if ($isDelivery): ?>
            <a href="<?= url('/deliveries') ?>" class="flex flex-col items-center justify-center p-1 rounded-lg tap-target text-blue-600 font-bold">
                <span class="text-xl">📋</span>
                <span class="text-[11px] font-bold">Run-Sheet</span>
            </a>
            <a href="<?= url('/logout') ?>" class="flex flex-col items-center justify-center p-1 rounded-lg tap-target text-rose-600 font-semibold">
                <span class="text-xl">🚪</span>
                <span class="text-[11px] font-bold">Logout</span>
            </a>
        <?php else: ?>
            <a href="<?= url('/') ?>" class="flex flex-col items-center justify-center p-1 rounded-lg tap-target <?= isActiveRoute('/') && !isActiveRoute('/deliveries') && !isActiveRoute('/customers') && !isActiveRoute('/invoices') && !isActiveRoute('/settings') ? 'text-blue-600 font-bold' : 'text-slate-600' ?>">
                <span class="text-xl">📊</span>
                <span class="text-[11px] font-bold">Home</span>
            </a>
            <a href="<?= url('/deliveries') ?>" class="flex flex-col items-center justify-center p-1 rounded-lg tap-target <?= isActiveRoute('/deliveries') ? 'text-blue-600 font-bold' : 'text-slate-600' ?>">
                <span class="text-xl">📋</span>
                <span class="text-[11px] font-bold">Run-Sheet</span>
            </a>
            <a href="<?= url('/customers') ?>" class="flex flex-col items-center justify-center p-1 rounded-lg tap-target <?= isActiveRoute('/customers') ? 'text-blue-600 font-bold' : 'text-slate-600' ?>">
                <span class="text-xl">👥</span>
                <span class="text-[11px] font-bold">Customers</span>
            </a>
            <a href="<?= url('/invoices') ?>" class="flex flex-col items-center justify-center p-1 rounded-lg tap-target <?= isActiveRoute('/invoices') ? 'text-blue-600 font-bold' : 'text-slate-600' ?>">
                <span class="text-xl">🧾</span>
                <span class="text-[11px] font-bold">Invoices</span>
            </a>
            <a href="<?= url('/settings') ?>" class="flex flex-col items-center justify-center p-1 rounded-lg tap-target <?= isActiveRoute('/settings') ? 'text-blue-600 font-bold' : 'text-slate-600' ?>">
                <span class="text-xl">⚙️</span>
                <span class="text-[11px] font-bold">Settings</span>
            </a>
        <?php endif; ?>
    </div>

    <footer class="border-t border-slate-200 bg-white py-4 px-4 text-center text-xs text-slate-500">
        © <?= date('Y') ?> <strong class="text-slate-700">Shri Ram Open Source Technologies</strong>. All rights reserved.
    </footer>

    <!-- Quick Demo Switcher Bar (Bottom Footer for easy review - Only in Demo/Dev Mode) -->
    <?php if (isDemoMode()): ?>
    <div class="border-t border-slate-200 bg-slate-100 py-2 px-4 text-xs text-slate-500 text-center flex flex-wrap items-center justify-center gap-3">
        <span class="font-semibold text-slate-700">Quick Demo Switch:</span>
        <a href="<?= url('/demo/tenant1') ?>" class="px-2 py-0.5 rounded bg-white border border-slate-300 font-medium text-blue-700 hover:bg-blue-50">🥛 Krishna Dairy (Paid Tier)</a>
        <a href="<?= url('/demo/tenant2') ?>" class="px-2 py-0.5 rounded bg-white border border-slate-300 font-medium text-emerald-700 hover:bg-emerald-50">🐄 Radha Dairy (Free 4/5 Quota)</a>
        <a href="<?= url('/demo/admin') ?>" class="px-2 py-0.5 rounded bg-white border border-slate-300 font-medium text-purple-700 hover:bg-purple-50">🛡️ Super Admin Portal</a>
    </div>
    <?php endif; ?>
</body>
</html>
