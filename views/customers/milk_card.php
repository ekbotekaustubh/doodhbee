<?php
/** @var array $sheet */
/** @var array $customer */
/** @var int $year */
/** @var int $month */
/** @var string|null $success */
/** @var string|null $error */

$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$monthName = $monthNames[$month] ?? date('F', mktime(0, 0, 0, $month, 1));
$days = $sheet['days'] ?? [];
$summary = $sheet['summary'] ?? [];

// Prev / Next month calculations
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}
?>

<div class="space-y-6" x-data="milkCardManager()">
    <!-- Top Bar Navigation & Customer Info -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
        <div class="space-y-1">
            <div class="flex items-center gap-2 flex-wrap">
                <a href="<?= url('/customers') ?>" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition flex items-center gap-1">
                    <span>← Customers</span>
                </a>
                <span class="text-slate-300">/</span>
                <span class="text-xs font-bold text-slate-400">Monthly Milk Card</span>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-xl md:text-2xl font-black text-slate-900 flex items-center gap-2">
                    <span>🥛</span>
                    <span><?= e($customer['name'] ?? 'Customer') ?></span>
                </h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-blue-50 text-blue-700 border border-blue-200">
                    <?= e($customer['route_name'] ?? 'Main Route') ?>
                </span>
                <?= formatMilkTypesBadge($customer['milk_types'] ?? 'cow') ?>
            </div>
            <p class="text-xs text-slate-500">
                <?= e($customer['address'] ?? '') ?> • 
                <a href="tel:<?= e($customer['phone'] ?? '') ?>" class="text-blue-600 font-mono hover:underline"><?= e($customer['phone'] ?? '') ?></a>
            </p>
        </div>

        <!-- Month & Year Selector -->
        <div class="flex items-center gap-2 flex-wrap">
            <a href="<?= url("/customers/{$customer['id']}/milk-card?year={$prevYear}&month={$prevMonth}") ?>" 
               class="tap-target px-3 py-2 bg-slate-100 hover:bg-slate-200 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 transition"
               title="Previous Month">
                ◀
            </a>

            <form method="GET" action="<?= url("/customers/{$customer['id']}/milk-card") ?>" class="flex items-center gap-2">
                <select name="month" onchange="this.form.submit()" class="px-3 py-2 border border-slate-300 rounded-xl text-xs font-black text-slate-800 bg-white focus:ring-2 focus:ring-blue-500">
                    <?php foreach ($monthNames as $mNum => $mLabel): ?>
                        <option value="<?= $mNum ?>" <?= $mNum === $month ? 'selected' : '' ?>><?= $mLabel ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="year" onchange="this.form.submit()" class="px-3 py-2 border border-slate-300 rounded-xl text-xs font-black text-slate-800 bg-white focus:ring-2 focus:ring-blue-500">
                    <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </form>

            <a href="<?= url("/customers/{$customer['id']}/milk-card?year={$nextYear}&month={$nextMonth}") ?>" 
               class="tap-target px-3 py-2 bg-slate-100 hover:bg-slate-200 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 transition"
               title="Next Month">
                ▶
            </a>

            <a href="<?= url("/customers/{$customer['id']}/edit") ?>" class="tap-target px-3 py-2 bg-slate-100 hover:bg-slate-200 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 transition">
                ✏️ Profile
            </a>
        </div>
    </div>

    <!-- Monthly Summary KPIs -->
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-3">
        <div class="p-4 bg-white rounded-2xl border border-slate-200 shadow-sm">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Total Litres</span>
            <div class="text-2xl font-black text-blue-600 mt-1 flex items-baseline gap-1">
                <span x-text="totals.totalLitres.toFixed(1)"><?= number_format((float)($summary['total_litres'] ?? 0), 1) ?></span>
                <span class="text-xs text-slate-500 font-bold">L</span>
            </div>
            <span class="text-[11px] text-slate-400 mt-0.5 block"><?= $monthName ?> <?= $year ?></span>
        </div>

        <div class="p-4 bg-white rounded-2xl border border-slate-200 shadow-sm">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Delivered Shifts</span>
            <div class="text-2xl font-black text-emerald-600 mt-1" x-text="totals.deliveredShifts">
                <?= (int)($summary['delivered_shifts'] ?? 0) ?>
            </div>
            <span class="text-[11px] text-slate-400 mt-0.5 block">Active delivered</span>
        </div>

        <div class="p-4 bg-white rounded-2xl border border-slate-200 shadow-sm">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Skipped / Paused</span>
            <div class="text-2xl font-black text-rose-500 mt-1" x-text="totals.skippedShifts">
                <?= (int)($summary['skipped_shifts'] ?? 0) ?>
            </div>
            <span class="text-[11px] text-slate-400 mt-0.5 block">Zero-charged</span>
        </div>

        <div class="p-4 bg-white rounded-2xl border border-slate-200 shadow-sm">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Vacation / Leave</span>
            <div class="text-2xl font-black text-amber-600 mt-1" x-text="totals.leaveShifts">
                <?= (int)($summary['leave_shifts'] ?? 0) ?>
            </div>
            <span class="text-[11px] text-slate-400 mt-0.5 block">Customer paused</span>
        </div>

        <div class="p-4 bg-white rounded-2xl border border-blue-200 bg-blue-50/40 shadow-sm col-span-2 sm:col-span-4 lg:col-span-1">
            <span class="text-xs font-bold text-blue-900 uppercase tracking-wider block">Projected Total</span>
            <div class="text-2xl font-black text-slate-900 mt-1">
                <span>₹</span><span x-text="totals.totalAmount.toFixed(2)"><?= number_format((float)($summary['total_amount'] ?? 0), 2) ?></span>
            </div>
            <span class="text-[11px] text-blue-700 mt-0.5 block">Estimated Bill</span>
        </div>
    </div>

    <!-- Interactive Milk Card (1 to 31 Days Grid) -->
    <form method="POST" action="<?= url("/customers/{$customer['id']}/milk-card") ?>" @submit="isSaving = true">
        <?= \App\Core\Auth::csrfField() ?>
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/50">
                <div>
                    <h2 class="text-base font-black text-slate-900 flex items-center gap-2">
                        <span>🗓️</span>
                        <span>Daily Delivery Matrix (Days 1–<?= $sheet['days_in_month'] ?? 31 ?>)</span>
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">Edit shift quantities or toggle Delivered / Skipped / Leave status for each day.</p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <button type="button" @click="showAutoFillModal = true" class="px-3.5 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-300 rounded-xl text-xs font-black transition flex items-center gap-1.5 shadow-xs">
                        <span>⚡ Auto-Fill Month</span>
                    </button>
                    <button type="button" @click="markAllMonthDelivered()" class="px-3 py-1.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-300 rounded-xl text-xs font-bold transition">
                        ✓ All Delivered
                    </button>
                    <button type="submit" :disabled="isSaving" class="tap-target px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow transition flex items-center gap-1.5">
                        <span x-show="!isSaving">💾 Save Changes</span>
                        <span x-show="isSaving">Saving...</span>
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-100 border-b border-slate-200 text-slate-700 font-black uppercase tracking-wider text-[11px]">
                            <th class="p-3 w-28">Day / Date</th>
                            <th class="p-3 w-56">🌅 Morning Shift</th>
                            <th class="p-3 w-56">🌆 Evening Shift</th>
                            <th class="p-3 w-28 text-right">Day Litres</th>
                            <th class="p-3 w-32 text-right">Day Amount</th>
                            <th class="p-3">Notes / Leave</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($days as $idx => $d): ?>
                            <?php 
                            $dateStr = $d['date'];
                            $mShift = $d['shifts']['morning'] ?? [];
                            $eShift = $d['shifts']['evening'] ?? [];
                            $isLeaveDay = !empty($d['is_on_leave']);
                            $isWeekend = in_array($d['day_of_week'], ['Sun'], true);
                            ?>
                            <tr class="hover:bg-blue-50/30 transition <?= $isLeaveDay ? 'bg-amber-50/40' : ($isWeekend ? 'bg-slate-50/60' : '') ?>">
                                <!-- Day & Date -->
                                <td class="p-3 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <span class="w-7 h-7 rounded-lg bg-slate-200/80 text-slate-800 flex items-center justify-center font-black text-xs shrink-0">
                                            <?= $d['day'] ?>
                                        </span>
                                        <div>
                                            <div class="font-black text-slate-800"><?= $d['day_of_week'] ?></div>
                                            <div class="text-[10px] text-slate-400 font-mono"><?= date('d M', strtotime($dateStr)) ?></div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Morning Shift -->
                                <td class="p-3">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <select name="days[<?= $dateStr ?>][morning][status]" 
                                                x-model="days['<?= $dateStr ?>'].morning.status"
                                                @change="recalculateTotals()"
                                                class="text-xs font-bold rounded-lg border px-2 py-1 focus:ring-1 focus:ring-blue-500"
                                                :class="{
                                                    'bg-emerald-50 text-emerald-800 border-emerald-300': days['<?= $dateStr ?>'].morning.status === 'delivered',
                                                    'bg-rose-50 text-rose-800 border-rose-300': days['<?= $dateStr ?>'].morning.status === 'skipped',
                                                    'bg-slate-100 text-slate-700 border-slate-300': days['<?= $dateStr ?>'].morning.status === 'leave',
                                                    'bg-amber-50 text-amber-800 border-amber-300': days['<?= $dateStr ?>'].morning.status === 'pending'
                                                }">
                                            <option value="delivered">✓ Delivered</option>
                                            <option value="skipped">✗ Skipped (0L)</option>
                                            <option value="leave">🏖 Leave (0L)</option>
                                        </select>

                                        <div class="flex items-center gap-1" x-show="days['<?= $dateStr ?>'].morning.status === 'delivered'">
                                            <input type="number" step="0.25" min="0" max="50"
                                                   name="days[<?= $dateStr ?>][morning][quantity]"
                                                   x-model.number="days['<?= $dateStr ?>'].morning.quantity"
                                                   @input="recalculateTotals()"
                                                   class="w-16 px-1.5 py-1 text-center font-bold text-xs border border-slate-300 rounded-lg">
                                            <span class="text-slate-400 font-bold text-[10px]">L</span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Evening Shift -->
                                <td class="p-3">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <select name="days[<?= $dateStr ?>][evening][status]" 
                                                x-model="days['<?= $dateStr ?>'].evening.status"
                                                @change="recalculateTotals()"
                                                class="text-xs font-bold rounded-lg border px-2 py-1 focus:ring-1 focus:ring-blue-500"
                                                :class="{
                                                    'bg-emerald-50 text-emerald-800 border-emerald-300': days['<?= $dateStr ?>'].evening.status === 'delivered',
                                                    'bg-rose-50 text-rose-800 border-rose-300': days['<?= $dateStr ?>'].evening.status === 'skipped',
                                                    'bg-slate-100 text-slate-700 border-slate-300': days['<?= $dateStr ?>'].evening.status === 'leave',
                                                    'bg-amber-50 text-amber-800 border-amber-300': days['<?= $dateStr ?>'].evening.status === 'pending'
                                                }">
                                            <option value="delivered">✓ Delivered</option>
                                            <option value="skipped">✗ Skipped (0L)</option>
                                            <option value="leave">🏖 Leave (0L)</option>
                                        </select>

                                        <div class="flex items-center gap-1" x-show="days['<?= $dateStr ?>'].evening.status === 'delivered'">
                                            <input type="number" step="0.25" min="0" max="50"
                                                   name="days[<?= $dateStr ?>][evening][quantity]"
                                                   x-model.number="days['<?= $dateStr ?>'].evening.quantity"
                                                   @input="recalculateTotals()"
                                                   class="w-16 px-1.5 py-1 text-center font-bold text-xs border border-slate-300 rounded-lg">
                                            <span class="text-slate-400 font-bold text-[10px]">L</span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Day Litres -->
                                <td class="p-3 text-right whitespace-nowrap font-black text-slate-800">
                                    <span x-text="getDayLitres('<?= $dateStr ?>').toFixed(1)">
                                        <?= number_format((float)$d['total_quantity'], 1) ?>
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-normal">L</span>
                                </td>

                                <!-- Day Amount -->
                                <td class="p-3 text-right whitespace-nowrap font-black text-emerald-700">
                                    <span>₹</span>
                                    <span x-text="getDayAmount('<?= $dateStr ?>').toFixed(2)">
                                        <?= number_format((float)$d['total_amount'], 2) ?>
                                    </span>
                                </td>

                                <!-- Notes / Reason -->
                                <td class="p-3">
                                    <input type="text" 
                                           name="days[<?= $dateStr ?>][morning][notes]"
                                           x-model="days['<?= $dateStr ?>'].morning.notes"
                                           placeholder="<?= $isLeaveDay ? e($d['leave_reason'] ?? 'Customer on Leave') : 'Optional notes...' ?>"
                                           class="w-full max-w-xs text-xs px-2 py-1 border border-slate-200 rounded-lg text-slate-700 placeholder-slate-400">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Sticky Save Bar -->
            <div class="p-4 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-4 text-xs font-bold text-slate-600">
                    <span>Delivered Litres: <strong class="text-blue-700 text-sm" x-text="totals.totalLitres.toFixed(1)">0.0</strong> L</span>
                    <span>•</span>
                    <span>Total Bill: <strong class="text-emerald-700 text-sm">₹<span x-text="totals.totalAmount.toFixed(2)">0.00</span></strong></span>
                </div>
                <div class="flex items-center gap-2">
                    <a href="<?= url('/customers') ?>" class="px-4 py-2 bg-white hover:bg-slate-100 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 transition">
                        Cancel
                    </a>
                    <button type="submit" :disabled="isSaving" class="tap-target px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow-md transition flex items-center gap-1.5">
                        <span x-show="!isSaving">💾 Save Monthly Milk Card</span>
                        <span x-show="isSaving">Saving...</span>
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- Single Customer Month Auto-Fill Modal -->
    <div x-show="showAutoFillModal" 
         x-cloak 
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs"
         @keydown.escape.window="showAutoFillModal = false">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 space-y-4"
             @click.outside="showAutoFillModal = false">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-2">
                    <span class="text-2xl">⚡</span>
                    <div>
                        <h3 class="text-base font-black text-slate-900">Auto-Fill for <?= e($customer['name'] ?? 'Customer') ?></h3>
                        <p class="text-xs text-slate-500"><?= $monthName ?> <?= $year ?> • Active Subscriptions</p>
                    </div>
                </div>
                <button type="button" @click="showAutoFillModal = false" class="text-slate-400 hover:text-slate-600 font-bold text-lg p-1">
                    ✕
                </button>
            </div>

            <form method="POST" action="<?= url('/deliveries/month-fill') ?>" class="space-y-4">
                <?= \App\Core\Auth::csrfField() ?>
                <input type="hidden" name="customer_id" value="<?= (int)($customer['id'] ?? 0) ?>">
                <input type="hidden" name="month" value="<?= $month ?>">
                <input type="hidden" name="year" value="<?= $year ?>">

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Target Customer</label>
                    <div class="p-2.5 rounded-xl bg-slate-100 text-xs font-black text-slate-800 border border-slate-200 flex items-center justify-between">
                        <span><?= e($customer['name'] ?? '') ?> (<?= e($customer['route_name'] ?? 'Main Route') ?>)</span>
                        <span class="text-blue-600 font-mono text-[11px]"><?= e($customer['phone'] ?? '') ?></span>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Delivery Shift</label>
                    <select name="shift" class="w-full text-xs font-bold px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 bg-white">
                        <option value="both" selected>Both Shifts (Morning & Evening)</option>
                        <option value="morning">Morning Shift Only</option>
                        <option value="evening">Evening Shift Only</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Conflict Strategy</label>
                    <div class="space-y-2 mt-1">
                        <label class="flex items-start gap-2.5 p-2.5 rounded-xl border border-blue-200 bg-blue-50/50 cursor-pointer">
                            <input type="radio" name="overwrite" value="0" checked class="mt-0.5 text-blue-600 focus:ring-blue-500">
                            <div>
                                <span class="text-xs font-black text-slate-900 block">Preserve Existing (Recommended)</span>
                                <span class="text-[11px] text-slate-500 block">Keeps manual changes/skips; only fills blank days from subscription.</span>
                            </div>
                        </label>
                        <label class="flex items-start gap-2.5 p-2.5 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer">
                            <input type="radio" name="overwrite" value="1" class="mt-0.5 text-blue-600 focus:ring-blue-500">
                            <div>
                                <span class="text-xs font-black text-slate-900 block">Overwrite All</span>
                                <span class="text-[11px] text-slate-500 block">Recalculates every day this month from subscriptions.</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="p-3 bg-amber-50 rounded-xl border border-amber-200 text-[11px] text-amber-800 flex items-start gap-2">
                    <span class="text-base shrink-0">🏖️</span>
                    <span><strong>Vacation Rule:</strong> Any days this customer was on scheduled leave will strictly record <strong>0.00L</strong>.</span>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" @click="showAutoFillModal = false" class="px-4 py-2 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-100 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow transition">
                        🚀 Auto-Fill This Month
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function milkCardManager() {
    // Initial day data prepared from PHP
    const initialDays = {};
    <?php foreach ($days as $d): ?>
        <?php 
        $dStr = $d['date'];
        $m = $d['shifts']['morning'] ?? [];
        $e = $d['shifts']['evening'] ?? [];
        ?>
        initialDays['<?= $dStr ?>'] = {
            morning: {
                status: '<?= $m['status'] ?? 'pending' ?>' === 'pending' ? 'delivered' : '<?= $m['status'] ?? 'delivered' ?>',
                quantity: <?= (float)($m['quantity'] ?? 0.0) ?>,
                default_qty: <?= (float)($m['quantity'] ?? 1.0) ?>,
                rate: <?= !empty($m['products']) ? ((float)$m['products'][0]['unit_price'] ?? 60.0) : 60.0 ?>,
                notes: '<?= addslashes($m['notes'] ?? '') ?>'
            },
            evening: {
                status: '<?= $e['status'] ?? 'pending' ?>' === 'pending' ? 'delivered' : '<?= $e['status'] ?? 'delivered' ?>',
                quantity: <?= (float)($e['quantity'] ?? 0.0) ?>,
                default_qty: <?= (float)($e['quantity'] ?? 0.0) ?>,
                rate: <?= !empty($e['products']) ? ((float)$e['products'][0]['unit_price'] ?? 60.0) : 60.0 ?>,
                notes: '<?= addslashes($e['notes'] ?? '') ?>'
            }
        };
    <?php endforeach; ?>

    return {
        days: initialDays,
        isSaving: false,
        showAutoFillModal: false,
        totals: {
            totalLitres: <?= (float)($summary['total_litres'] ?? 0) ?>,
            totalAmount: <?= (float)($summary['total_amount'] ?? 0) ?>,
            deliveredShifts: <?= (int)($summary['delivered_shifts'] ?? 0) ?>,
            skippedShifts: <?= (int)($summary['skipped_shifts'] ?? 0) ?>,
            leaveShifts: <?= (int)($summary['leave_shifts'] ?? 0) ?>,
        },
        init() {
            this.recalculateTotals();
        },
        getDayLitres(dateStr) {
            const d = this.days[dateStr];
            if (!d) return 0.0;
            let qty = 0.0;
            if (d.morning && d.morning.status === 'delivered') qty += parseFloat(d.morning.quantity) || 0.0;
            if (d.evening && d.evening.status === 'delivered') qty += parseFloat(d.evening.quantity) || 0.0;
            return qty;
        },
        getDayAmount(dateStr) {
            const d = this.days[dateStr];
            if (!d) return 0.0;
            let amt = 0.0;
            if (d.morning && d.morning.status === 'delivered') {
                amt += (parseFloat(d.morning.quantity) || 0.0) * (d.morning.rate || 60.0);
            }
            if (d.evening && d.evening.status === 'delivered') {
                amt += (parseFloat(d.evening.quantity) || 0.0) * (d.evening.rate || 60.0);
            }
            return amt;
        },
        markAllMonthDelivered() {
            Object.keys(this.days).forEach(dateStr => {
                const d = this.days[dateStr];
                if (d.morning.status !== 'leave') {
                    d.morning.status = 'delivered';
                    if (!d.morning.quantity || d.morning.quantity <= 0) d.morning.quantity = d.morning.default_qty || 1.0;
                }
                if (d.evening.status !== 'leave' && d.evening.default_qty > 0) {
                    d.evening.status = 'delivered';
                    if (!d.evening.quantity || d.evening.quantity <= 0) d.evening.quantity = d.evening.default_qty || 1.0;
                }
            });
            this.recalculateTotals();
        },
        recalculateTotals() {
            let litres = 0.0;
            let amount = 0.0;
            let delShifts = 0;
            let skipShifts = 0;
            let lvShifts = 0;

            Object.keys(this.days).forEach(dateStr => {
                const d = this.days[dateStr];
                ['morning', 'evening'].forEach(shift => {
                    const sh = d[shift];
                    if (!sh) return;
                    if (sh.status === 'delivered') {
                        const q = parseFloat(sh.quantity) || 0.0;
                        litres += q;
                        amount += q * (sh.rate || 60.0);
                        delShifts++;
                    } else if (sh.status === 'skipped') {
                        sh.quantity = 0.0;
                        skipShifts++;
                    } else if (sh.status === 'leave') {
                        sh.quantity = 0.0;
                        lvShifts++;
                    }
                });
            });

            this.totals.totalLitres = litres;
            this.totals.totalAmount = amount;
            this.totals.deliveredShifts = delShifts;
            this.totals.skippedShifts = skipShifts;
            this.totals.leaveShifts = lvShifts;
        }
    };
}
</script>
