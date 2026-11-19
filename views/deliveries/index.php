<div class="space-y-4">
    <!-- Header Controls: Date Picker, Shift Selector, Route Filter & Search -->
    <div class="bg-white rounded-2xl border border-slate-200 p-4 md:p-5 shadow-sm space-y-4">
        <!-- Date Selector & Day Navigation -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h1 class="text-xl md:text-2xl font-black text-slate-900 flex items-center gap-2">
                    <span>📋 Daily Delivery Run-Sheet</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">
                    Zero-Dispute Delivery Tracker • Missed & paused days are auto-excluded from invoices
                </p>
            </div>

            <!-- Date Controls -->
            <form method="GET" action="<?= url('/deliveries') ?>" class="flex items-center gap-1.5 self-start sm:self-auto">
                <input type="hidden" name="shift" value="<?= e($shift) ?>">
                <?php if (!empty($selectedRoute)): ?>
                    <input type="hidden" name="route" value="<?= e($selectedRoute) ?>">
                <?php endif; ?>
                <?php if (!empty($search)): ?>
                    <input type="hidden" name="q" value="<?= e($search) ?>">
                <?php endif; ?>

                <?php 
                $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
                $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
                $isToday = ($date === date('Y-m-d'));
                $baseDateQuery = (!empty($selectedRoute) ? "&route=" . urlencode($selectedRoute) : "") . (!empty($search) ? "&q=" . urlencode($search) : "") . ($perPage !== 25 ? "&per_page={$perPage}" : "");
                ?>
                <a href="<?= url("/deliveries?date={$prevDate}&shift={$shift}{$baseDateQuery}") ?>" class="tap-target px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 flex items-center justify-center">
                    ◀
                </a>

                <input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()" class="px-3 py-2 border-2 border-slate-300 rounded-xl text-sm font-black text-slate-800 focus:ring-2 focus:ring-blue-500">

                <a href="<?= url("/deliveries?date={$nextDate}&shift={$shift}{$baseDateQuery}") ?>" class="tap-target px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 flex items-center justify-center">
                    ▶
                </a>

                <?php if (!$isToday): ?>
                    <a href="<?= url("/deliveries?date=" . date('Y-m-d') . "&shift={$shift}{$baseDateQuery}") ?>" class="px-2.5 py-2 bg-blue-50 text-blue-700 border border-blue-200 rounded-xl text-xs font-bold hover:bg-blue-100">
                        Today
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Shift Selector Tabs & Bulk Actions -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 pt-3 border-t border-slate-100">
            <!-- Shifts Switcher (Morning vs Evening) -->
            <?php 
            $shiftQuery = (!empty($selectedRoute) ? "&route=" . urlencode($selectedRoute) : "") . (!empty($search) ? "&q=" . urlencode($search) : "") . ($perPage !== 25 ? "&per_page={$perPage}" : "");
            ?>
            <div class="grid grid-cols-2 gap-2 w-full md:w-auto">
                <a href="<?= url("/deliveries?date={$date}&shift=morning{$shiftQuery}") ?>" class="tap-target px-4 py-2.5 rounded-xl text-xs font-black transition flex items-center justify-center gap-2 border-2 <?= $shift === 'morning' ? 'bg-amber-500 border-amber-600 text-white shadow-md' : 'bg-slate-50 border-slate-200 text-slate-700 hover:bg-slate-100' ?>">
                    <span class="text-base">🌅</span>
                    <span>Morning Shift</span>
                </a>
                <a href="<?= url("/deliveries?date={$date}&shift=evening{$shiftQuery}") ?>" class="tap-target px-4 py-2.5 rounded-xl text-xs font-black transition flex items-center justify-center gap-2 border-2 <?= $shift === 'evening' ? 'bg-indigo-600 border-indigo-700 text-white shadow-md' : 'bg-slate-50 border-slate-200 text-slate-700 hover:bg-slate-100' ?>">
                    <span class="text-base">🌆</span>
                    <span>Evening Shift</span>
                </a>
            </div>

            <!-- Bulk Confirm Delivered for all pending -->
            <?php if ($runSheet['stats']['pending'] > 0): ?>
                <div class="w-full md:w-auto">
                    <form method="POST" action="<?= url('/deliveries/bulk-deliver') ?>" onsubmit="return confirm('Mark all <?= $runSheet['stats']['pending'] ?> pending houses <?= !empty($selectedRoute) ? 'in route ' . e($selectedRoute) : '' ?> as Delivered?');">
                        <?= \App\Core\Auth::csrfField() ?>
                        <input type="hidden" name="date" value="<?= e($date) ?>">
                        <input type="hidden" name="shift" value="<?= e($shift) ?>">
                        <?php if (!empty($selectedRoute)): ?>
                            <input type="hidden" name="route_name" value="<?= e($selectedRoute) ?>">
                        <?php endif; ?>
                        <button type="submit" class="tap-target w-full md:w-auto px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black shadow transition flex items-center justify-center gap-1.5 whitespace-nowrap">
                            <span>✓ Mark <?= !empty($selectedRoute) ? e($selectedRoute) : 'All' ?> Delivered</span>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- Search & Route Filter Bar -->
        <div class="pt-2">
            <form method="GET" action="<?= url('/deliveries') ?>" class="flex flex-col sm:flex-row items-center gap-2.5">
                <input type="hidden" name="date" value="<?= e($date) ?>">
                <input type="hidden" name="shift" value="<?= e($shift) ?>">
                <?php if ($perPage !== 25): ?>
                    <input type="hidden" name="per_page" value="<?= e($perPage) ?>">
                <?php endif; ?>

                <!-- Search Input -->
                <div class="relative w-full sm:flex-1">
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search customer name, phone, or address..." class="w-full pl-9 pr-8 py-2 text-xs border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 bg-white">
                    <span class="absolute left-3 top-2.5 text-slate-400 text-xs">🔍</span>
                    <?php if (!empty($search)): ?>
                        <a href="<?= url("/deliveries?date={$date}&shift={$shift}" . (!empty($selectedRoute) ? "&route=" . urlencode($selectedRoute) : "")) ?>" class="absolute right-3 top-2.5 text-slate-400 hover:text-slate-600 font-bold text-xs" title="Clear search">✕</a>
                    <?php endif; ?>
                </div>

                <!-- Route Selector -->
                <div class="w-full sm:w-56">
                    <select name="route" onchange="this.form.submit()" class="w-full text-xs font-bold py-2 px-3 border border-slate-300 rounded-xl bg-slate-50 focus:ring-2 focus:ring-blue-500">
                        <option value="">🛣️ All Routes (<?= $runSheet['stats']['total_houses'] ?>)</option>
                        <?php foreach ($runSheet['routes'] as $rName): ?>
                            <option value="<?= e($rName) ?>" <?= $selectedRoute === $rName ? 'selected' : '' ?>>
                                <?= e($rName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Submit Filter Button -->
                <button type="submit" class="w-full sm:w-auto px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold">
                    Filter
                </button>

                <!-- Clear Filters if any active -->
                <?php if (!empty($search) || !empty($selectedRoute)): ?>
                    <a href="<?= url("/deliveries?date={$date}&shift={$shift}") ?>" class="w-full sm:w-auto px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-bold text-center border border-slate-200">
                        Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Shift Verification Alert Banner -->
    <?php if (!empty($runSheet['pending_alert'])): ?>
        <div class="p-4 bg-amber-50 border-2 border-amber-300 text-amber-950 rounded-2xl shadow-sm flex items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span class="text-xl">⚠️</span>
                <span class="text-xs sm:text-sm font-black"><?= e($runSheet['pending_alert']) ?></span>
            </div>
            <span class="text-xs bg-amber-200 text-amber-900 font-bold px-2.5 py-1 rounded-lg">
                <?= $runSheet['stats']['pending'] ?> left
            </span>
        </div>
    <?php endif; ?>

    <!-- Run-Sheet Shift Metrics Summary Bar -->
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2.5 bg-white p-3.5 rounded-2xl border border-slate-200 shadow-sm text-center">
        <div class="p-2 rounded-xl bg-blue-50/60 border border-blue-100">
            <div class="text-[10px] font-bold uppercase text-slate-500">Delivered Volume</div>
            <div class="text-xl font-black text-blue-700"><?= number_format($runSheet['stats']['total_liters'], 1) ?> <span class="text-xs">Units</span></div>
            <?php if (!empty($runSheet['stats']['product_totals'])): ?>
                <div class="text-[10px] text-blue-800 font-bold mt-0.5 space-x-1.5 flex justify-center flex-wrap">
                    <?php foreach ($runSheet['stats']['product_totals'] as $pName => $pData): ?>
                        <span><?= e($pName) ?>: <?= number_format($pData['quantity'], 1) ?><?= e($pData['unit']) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="p-2 rounded-xl bg-emerald-50/60 border border-emerald-100">
            <div class="text-[10px] font-bold uppercase text-emerald-700">Delivered</div>
            <div class="text-xl font-black text-emerald-700"><?= $runSheet['stats']['delivered'] ?></div>
        </div>
        <div class="p-2 rounded-xl bg-rose-50/60 border border-rose-100">
            <div class="text-[10px] font-bold uppercase text-rose-700">Skipped</div>
            <div class="text-xl font-black text-rose-700"><?= $runSheet['stats']['skipped'] ?></div>
        </div>
        <div class="p-2 rounded-xl bg-slate-100 border border-slate-200">
            <div class="text-[10px] font-bold uppercase text-slate-600">On Vacation</div>
            <div class="text-xl font-black text-slate-700"><?= $runSheet['stats']['leave'] ?></div>
        </div>
        <div class="p-2 rounded-xl bg-amber-50/60 border border-amber-200 col-span-2 sm:col-span-1">
            <div class="text-[10px] font-bold uppercase text-amber-700">Pending</div>
            <div class="text-xl font-black text-amber-700"><?= $runSheet['stats']['pending'] ?></div>
        </div>
    </div>

    <!-- Zero-Charge Rule Reminder Notice -->
    <div class="text-center text-xs text-slate-500 font-medium">
        🛡️ <strong>Zero-Charge Rule Active:</strong> Skipped deliveries or customers on vacation are automatically assigned 0.00L and excluded from the monthly milk invoice.
    </div>

    <!-- Delivery Rows List & Pagination Container -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden divide-y divide-slate-100">
        <?php if (empty($runSheet['items'])): ?>
            <div class="p-12 text-center">
                <span class="text-4xl">🔍</span>
                <h3 class="text-base font-black text-slate-800 mt-2">No Customers Found</h3>
                <?php if (!empty($search) || !empty($selectedRoute)): ?>
                    <p class="text-xs text-slate-500 mt-1">
                        No active customers matched your search query 
                        <?= !empty($search) ? '"<strong>' . e($search) . '</strong>"' : '' ?>
                        <?= !empty($selectedRoute) ? 'in route "<strong>' . e($selectedRoute) . '</strong>"' : '' ?>.
                    </p>
                    <a href="<?= url("/deliveries?date={$date}&shift={$shift}") ?>" class="inline-flex mt-4 px-4 py-2 bg-slate-800 text-white rounded-xl text-xs font-bold">
                        Clear Filters
                    </a>
                <?php else: ?>
                    <p class="text-xs text-slate-500 mt-1">Add customers to your route to start tracking daily deliveries.</p>
                    <a href="<?= url('/customers/create') ?>" class="inline-flex mt-4 px-4 py-2 bg-blue-600 text-white rounded-xl text-xs font-bold">
                        + Add New Customer
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($runSheet['items'] as $item): ?>
                <?= \App\Core\View::renderPartial('deliveries/_row', [
                    'item' => $item,
                    'date' => $date,
                    'shift' => $shift,
                ]) ?>
            <?php endforeach; ?>

            <!-- Pagination Toolbar -->
            <?php
            $from = ($pagination['total_items'] > 0) ? ($pagination['current_page'] - 1) * $pagination['per_page'] + 1 : 0;
            $to = min($pagination['total_items'], $pagination['current_page'] * $pagination['per_page']);
            $total = $pagination['total_items'];
            $curPage = $pagination['current_page'];
            $totPages = $pagination['total_pages'];

            $buildPageUrl = function(int $p) use ($date, $shift, $selectedRoute, $search, $perPage) {
                $params = [
                    'date' => $date,
                    'shift' => $shift,
                ];
                if (!empty($selectedRoute)) $params['route'] = $selectedRoute;
                if (!empty($search)) $params['q'] = $search;
                if ($perPage !== 25) $params['per_page'] = $perPage;
                $params['page'] = $p;
                return url('/deliveries?' . http_build_query($params));
            };
            ?>
            <div class="p-3.5 sm:p-4 bg-slate-50/80 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                <div class="text-slate-600 font-medium">
                    <?php if ($total > 0): ?>
                        Showing <span class="font-bold text-slate-800"><?= $from ?></span> to <span class="font-bold text-slate-800"><?= $to ?></span> of <span class="font-bold text-slate-800"><?= $total ?></span> customers
                        <?php if (!empty($selectedRoute)): ?>
                            in <span class="font-semibold text-blue-700">"<?= e($selectedRoute) ?>"</span>
                        <?php endif; ?>
                    <?php else: ?>
                        0 customers found
                    <?php endif; ?>
                </div>

                <!-- Per-Page Selector & Navigation Buttons -->
                <div class="flex items-center gap-3">
                    <!-- Per Page Dropdown -->
                    <div class="flex items-center gap-1.5 text-slate-500 font-bold">
                        <span>Show:</span>
                        <select onchange="location.href = this.value" class="py-1 px-2 border border-slate-300 rounded-lg text-xs font-bold bg-white text-slate-700 focus:ring-1 focus:ring-blue-500">
                            <?php foreach ([10, 25, 50, 100] as $opt): ?>
                                <?php
                                $optParams = [
                                    'date' => $date,
                                    'shift' => $shift,
                                    'per_page' => $opt,
                                    'page' => 1,
                                ];
                                if (!empty($selectedRoute)) $optParams['route'] = $selectedRoute;
                                if (!empty($search)) $optParams['q'] = $search;
                                $optUrl = url('/deliveries?' . http_build_query($optParams));
                                ?>
                                <option value="<?= $optUrl ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($totPages > 1): ?>
                        <div class="flex items-center gap-1">
                            <?php if ($pagination['has_prev']): ?>
                                <a href="<?= $buildPageUrl($curPage - 1) ?>" class="px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg text-slate-700 hover:bg-slate-100 font-bold shadow-xs">◀ Prev</a>
                            <?php else: ?>
                                <span class="px-2.5 py-1.5 bg-slate-100 border border-slate-200 rounded-lg text-slate-400 cursor-not-allowed">◀ Prev</span>
                            <?php endif; ?>

                            <?php 
                            $startPage = max(1, $curPage - 2);
                            $endPage = min($totPages, $curPage + 2);
                            for ($p = $startPage; $p <= $endPage; $p++): 
                            ?>
                                <a href="<?= $buildPageUrl($p) ?>" class="px-3 py-1.5 rounded-lg font-bold <?= $p === $curPage ? 'bg-blue-600 text-white shadow-sm' : 'bg-white border border-slate-300 text-slate-700 hover:bg-slate-100' ?>">
                                    <?= $p ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($pagination['has_next']): ?>
                                <a href="<?= $buildPageUrl($curPage + 1) ?>" class="px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg text-slate-700 hover:bg-slate-100 font-bold shadow-xs">Next ▶</a>
                            <?php else: ?>
                                <span class="px-2.5 py-1.5 bg-slate-100 border border-slate-200 rounded-lg text-slate-400 cursor-not-allowed">Next ▶</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
