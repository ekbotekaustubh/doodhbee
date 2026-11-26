<?php
$invoicesMap = [];
foreach ($invoices as $inv) {
    $invoicesMap[(int)$inv['id']] = [
        'id' => (int)$inv['id'],
        'customer_name' => $inv['customer_name'] ?? 'Customer',
        'customer_phone' => $inv['customer_phone'] ?? '',
        'final_amount' => (float)($inv['final_amount'] ?? 0.0),
        'total_units' => (float)($inv['total_units'] ?? 0.0),
        'payment_status' => $inv['payment_status'] ?? 'unpaid',
        'wa_url' => url("/invoices/{$inv['id']}/whatsapp"),
        'pdf_url' => url("/invoices/{$inv['id']}/pdf?type=a4"),
    ];
}
?>

<div class="space-y-4" x-data="invoiceBatchApp(<?= htmlspecialchars(json_encode($invoicesMap), ENT_QUOTES, 'UTF-8') ?>)">
    <!-- Header with Batch Generate Action -->
    <div class="bg-white rounded-2xl border border-slate-200 p-4 md:p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl md:text-2xl font-black text-slate-900 flex items-center gap-2">
                <span>🧾 Invoices & Billing Engine</span>
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Automated monthly calculation excluding missed days • WhatsApp bill dispatch • Custom surcharges & GST
            </p>
        </div>

        <form method="POST" action="<?= url('/invoices/generate-batch') ?>" onsubmit="return confirm('Generate or recalculate invoices for all customers for <?= sprintf('%02d/%04d', $month, $year) ?>?');" class="flex items-center gap-2">
            <?= \App\Core\Auth::csrfField() ?>
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="month" value="<?= $month ?>">
            <button type="submit" class="tap-target px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow-md transition flex items-center gap-1.5 whitespace-nowrap">
                <span>⚡ Generate All <?= sprintf('%02d/%04d', $month, $year) ?> Bills</span>
            </button>
        </form>
    </div>

    <!-- Month / Year Selector & Filter Bar -->
    <div class="bg-white rounded-2xl border border-slate-200 p-3 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-3">
        <form method="GET" action="<?= url('/invoices') ?>" class="w-full flex flex-wrap items-center gap-2">
            <!-- Month Selector -->
            <select name="month" onchange="this.form.submit()" class="text-xs font-bold py-2 px-3 border border-slate-300 rounded-xl bg-slate-50">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>>
                        <?= date('F', mktime(0, 0, 0, $m, 10)) ?>
                    </option>
                <?php endfor; ?>
            </select>

            <!-- Year Selector -->
            <select name="year" onchange="this.form.submit()" class="text-xs font-bold py-2 px-3 border border-slate-300 rounded-xl bg-slate-50">
                <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $year === (int)$y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>

            <!-- Status Filter -->
            <select name="status" onchange="this.form.submit()" class="text-xs font-bold py-2 px-3 border border-slate-300 rounded-xl bg-slate-50">
                <option value="">All Statuses</option>
                <option value="unpaid" <?= $selectedStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                <option value="paid" <?= $selectedStatus === 'paid' ? 'selected' : '' ?>>Paid in Full</option>
                <option value="partial" <?= $selectedStatus === 'partial' ? 'selected' : '' ?>>Partially Paid</option>
            </select>

            <button type="submit" class="px-4 py-2 bg-slate-800 text-white rounded-xl text-xs font-bold">
                Apply
            </button>
        </form>
    </div>

    <!-- Summary KPI Counters -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm text-center">
            <div class="text-[10px] font-bold uppercase text-slate-500">Total Billed</div>
            <div class="text-xl font-black text-slate-900"><?= formatMoney($totalBilled) ?></div>
        </div>
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm text-center">
            <div class="text-[10px] font-bold uppercase text-emerald-700">Collected</div>
            <div class="text-xl font-black text-emerald-700"><?= formatMoney($totalPaid) ?></div>
        </div>
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm text-center">
            <div class="text-[10px] font-bold uppercase text-amber-700">Outstanding Dues</div>
            <div class="text-xl font-black text-amber-700"><?= formatMoney(max(0, $totalBilled - $totalPaid)) ?></div>
        </div>
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm text-center">
            <div class="text-[10px] font-bold uppercase text-purple-700">Bills Generated</div>
            <div class="text-xl font-black text-purple-700"><?= count($invoices) ?> / <?= $activeCustomersCount ?></div>
        </div>
    </div>

    <!-- WhatsApp Queue Activity Bar (If messages in queue) -->
    <?php if (!empty($queueStats['total']) && $queueStats['total'] > 0): ?>
        <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-3 px-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs">
            <div class="flex items-center gap-2 text-emerald-900 font-semibold">
                <span class="text-base">📨</span>
                <span>WhatsApp Queue:</span>
                <span class="bg-emerald-200 text-emerald-900 px-2 py-0.5 rounded-full font-bold"><?= $queueStats['sent'] ?> Sent</span>
                <?php if ($queueStats['pending'] > 0): ?>
                    <span class="bg-amber-200 text-amber-900 px-2 py-0.5 rounded-full font-bold animate-pulse"><?= $queueStats['pending'] ?> Pending (Cron)</span>
                <?php endif; ?>
                <?php if ($queueStats['failed'] > 0): ?>
                    <span class="bg-rose-200 text-rose-900 px-2 py-0.5 rounded-full font-bold"><?= $queueStats['failed'] ?> Failed</span>
                <?php endif; ?>
            </div>
            <span class="text-slate-500 text-[11px]">Processed automatically via Cron / Queue worker</span>
        </div>
    <?php endif; ?>

    <!-- Table Header & Bulk Selection Controls -->
    <?php if (!empty($invoices)): ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-3 shadow-sm flex flex-wrap items-center justify-between gap-3 text-xs">
            <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 cursor-pointer font-bold text-slate-800 select-none">
                    <input type="checkbox" :checked="isAllSelected()" @change="toggleSelectAll($event.target.checked)" class="w-4 h-4 rounded text-blue-600 border-slate-300 focus:ring-blue-500">
                    <span>Select All (<?= count($invoices) ?>)</span>
                </label>
                <button type="button" @click="selectOnlyUnpaid()" class="px-2.5 py-1 bg-amber-50 hover:bg-amber-100 text-amber-800 rounded-lg font-bold border border-amber-200 transition">
                    ⚡ Select Unpaid Only (<?= $unpaidCount ?>)
                </button>
                <button type="button" x-show="selectedIds.length > 0" @click="selectedIds = []" class="text-slate-500 hover:text-slate-800 font-semibold underline">
                    Clear
                </button>
            </div>

            <div class="text-slate-600 font-semibold" x-show="selectedIds.length > 0">
                <span class="font-bold text-blue-700" x-text="selectedIds.length"></span> invoices selected
                (<span class="font-bold text-slate-900" x-text="formatCurrency(selectedTotalAmount())"></span>)
            </div>
        </div>
    <?php endif; ?>

    <!-- Invoices List -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden divide-y divide-slate-100">
        <?php if (empty($invoices)): ?>
            <div class="p-12 text-center">
                <span class="text-4xl">📄</span>
                <h3 class="text-base font-black text-slate-800 mt-2">No Invoices for <?= sprintf('%02d/%04d', $month, $year) ?></h3>
                <p class="text-xs text-slate-500 mt-1">Click "Generate All Bills" above to calculate and issue invoices for this month.</p>
            </div>
        <?php else: ?>
            <?php foreach ($invoices as $inv): ?>
                <div class="p-4 hover:bg-slate-50 transition flex flex-col md:flex-row md:items-center justify-between gap-3" :class="selectedIds.includes(<?= (int)$inv['id'] ?>) ? 'bg-blue-50/40' : ''">
                    <div class="flex items-start gap-3">
                        <!-- Checkbox -->
                        <div class="pt-2">
                            <input type="checkbox" :value="<?= (int)$inv['id'] ?>" x-model="selectedIds" class="w-4 h-4 rounded text-blue-600 border-slate-300 focus:ring-blue-500 cursor-pointer">
                        </div>

                        <!-- Payment Status Avatar -->
                        <span class="w-10 h-10 rounded-full flex items-center justify-center font-black text-base shrink-0 <?= $inv['payment_status'] === 'paid' ? 'bg-emerald-100 text-emerald-800' : ($inv['payment_status'] === 'partial' ? 'bg-sky-100 text-sky-800' : 'bg-amber-100 text-amber-800') ?>">
                            <?= $inv['payment_status'] === 'paid' ? '✓' : '₹' ?>
                        </span>

                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-black text-base text-slate-900"><?= e($inv['customer_name']) ?></span>
                                <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 font-semibold border border-slate-200">
                                    <?= e($inv['route_name'] ?? 'Main Route') ?>
                                </span>
                                <?= formatMilkTypesBadge($inv['milk_types'] ?? 'cow') ?>
                                <span class="text-xs px-2.5 py-0.5 rounded-full font-bold border <?= \App\Enums\PaymentStatus::from($inv['payment_status'])->badgeClass() ?>">
                                    <?= \App\Enums\PaymentStatus::from($inv['payment_status'])->label() ?>
                                </span>
                            </div>
                            <p class="text-xs text-slate-500 mt-0.5">
                                Phone: <?= e($inv['customer_phone']) ?> • Billed Liters: <strong><?= number_format((float)$inv['total_units'], 1) ?> L</strong>
                            </p>
                            <div class="text-[11px] text-slate-500 mt-1 flex items-center gap-2 flex-wrap">
                                <span>Milk Subtotal: <strong><?= formatMoney($inv['milk_subtotal']) ?></strong></span>
                                <?php if ((float)$inv['additional_charges_total'] > 0): ?>
                                    <span>•</span>
                                    <span>Charges: <strong><?= formatMoney($inv['additional_charges_total']) ?></strong></span>
                                <?php endif; ?>
                                <?php if ((float)$inv['tax_amount'] > 0): ?>
                                    <span>•</span>
                                    <span>Tax: <strong><?= formatMoney($inv['tax_amount']) ?></strong></span>
                                <?php endif; ?>
                                <?php if ((float)$inv['previous_balance'] > 0): ?>
                                    <span>•</span>
                                    <span class="text-rose-600 font-bold">Prev Bal: <?= formatMoney($inv['previous_balance']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Final Amount & Actions -->
                    <div class="flex items-center gap-3 self-end md:self-center">
                        <div class="text-right mr-2">
                            <div class="text-xs text-slate-400 font-semibold uppercase">Total Payable</div>
                            <div class="text-xl font-black text-slate-900"><?= formatMoney($inv['final_amount']) ?></div>
                        </div>

                        <div class="flex items-center gap-1.5 flex-wrap">
                            <!-- Direct 1-Click WhatsApp PDF Send (Cron/Meta Engine) -->
                            <form method="POST" action="<?= url("/invoices/{$inv['id']}/whatsapp-send") ?>" class="inline" x-data="{ sending: false }" @submit="sending = true">
                                <?= \App\Core\Auth::csrfField() ?>
                                <input type="hidden" name="provider" value="meta_cloud">
                                <button type="submit" :disabled="sending" class="tap-target px-2.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition flex items-center gap-1 shadow-sm disabled:opacity-50" title="Send Bill PDF directly as document via WhatsApp">
                                    <span x-show="!sending">📄 WhatsApp</span>
                                    <span x-show="sending">⏳...</span>
                                </button>
                            </form>

                            <!-- Direct WhatsApp Web Fallback Link -->
                            <a href="<?= url("/invoices/{$inv['id']}/whatsapp") ?>" target="_blank" class="tap-target px-2 py-2 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-xs font-bold border border-emerald-300 transition flex items-center gap-1 shadow-sm" title="Open Click-to-Chat in WhatsApp Web">
                                <span>💬</span>
                            </a>

                            <a href="<?= url("/invoices/{$inv['id']}") ?>" class="tap-target px-3 py-2 rounded-xl bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-bold border border-blue-200 transition">
                                View
                            </a>
                            <a href="<?= url("/invoices/{$inv['id']}/thermal") ?>" class="tap-target px-2.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold border border-slate-300 transition" title="Print Thermal Receipt (80mm/58mm)">
                                🧾
                            </a>
                            <a href="<?= url("/invoices/{$inv['id']}/a4") ?>" class="tap-target px-2.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold border border-slate-300 transition" title="Print Standard A4 Tax Invoice">
                                📄
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Floating Sticky Action Bar (Triggered when 1 or more invoices selected) -->
    <div x-show="selectedIds.length > 0" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0" class="fixed bottom-4 left-4 right-4 max-w-4xl mx-auto z-40 bg-slate-900 text-white rounded-2xl p-4 shadow-2xl border border-slate-700 flex flex-col sm:flex-row items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <span class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center font-black text-xs" x-text="selectedIds.length"></span>
            <div>
                <div class="font-bold text-sm">
                    <span x-text="selectedIds.length"></span> Customer Bills Selected
                </div>
                <div class="text-xs text-slate-300">
                    Total Amount: <strong class="text-emerald-400" x-text="formatCurrency(selectedTotalAmount())"></strong>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap w-full sm:w-auto justify-end">
            <!-- Mode 1: Interactive Sequential WhatsApp Web Dispatcher -->
            <button type="button" @click="startSequentialRunner()" class="flex-1 sm:flex-initial px-4 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-black shadow transition flex items-center justify-center gap-1.5 whitespace-nowrap">
                <span>📱 WhatsApp Web Runner</span>
            </button>

            <!-- Mode 2: Automated Background Cron Queue -->
            <form method="POST" action="<?= url('/invoices/whatsapp/bulk-queue') ?>" class="inline flex-1 sm:flex-initial">
                <?= \App\Core\Auth::csrfField() ?>
                <input type="hidden" name="year" value="<?= $year ?>">
                <input type="hidden" name="month" value="<?= $month ?>">
                <template x-for="id in selectedIds" :key="id">
                    <input type="hidden" name="invoice_ids[]" :value="id">
                </template>
                <button type="submit" onclick="return confirm('Queue all selected invoices for background WhatsApp delivery via Cron?');" class="w-full px-4 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-xs font-black shadow transition flex items-center justify-center gap-1.5 whitespace-nowrap">
                    <span>⚡ Queue for Cron</span>
                </button>
            </form>

            <button type="button" @click="selectedIds = []" class="px-3 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition">
                ✕
            </button>
        </div>
    </div>

    <!-- Sequential WhatsApp Web Dispatcher Modal (Mode 1) -->
    <div x-show="showRunnerModal" class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm" x-cloak>
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 relative text-left" @click.away="showRunnerModal = false">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-2">
                    <span class="text-2xl">📱</span>
                    <div>
                        <h3 class="font-black text-base text-slate-900">WhatsApp Web Batch Dispatcher</h3>
                        <p class="text-[11px] text-slate-500">Send personalized bills sequentially without popup block</p>
                    </div>
                </div>
                <button @click="showRunnerModal = false" class="text-slate-400 hover:text-slate-600 text-lg font-bold">✕</button>
            </div>

            <!-- Modal Content State -->
            <div class="py-4 space-y-4">
                <!-- If Not Completed -->
                <template x-if="!runnerDone">
                    <div class="space-y-4">
                        <!-- Progress Bar -->
                        <div>
                            <div class="flex justify-between text-xs font-bold text-slate-600 mb-1">
                                <span>Customer <span x-text="runnerIndex + 1"></span> of <span x-text="runnerList.length"></span></span>
                                <span x-text="Math.round(((runnerIndex) / runnerList.length) * 100) + '% Completed'"></span>
                            </div>
                            <div class="w-full h-2.5 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full bg-emerald-500 transition-all duration-300 rounded-full" :style="'width: ' + (((runnerIndex) / runnerList.length) * 100) + '%'"></div>
                            </div>
                        </div>

                        <!-- Current Customer Card -->
                        <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200 space-y-2" x-show="currentRunnerInvoice()">
                            <div class="flex items-center justify-between">
                                <span class="font-black text-base text-slate-900" x-text="currentRunnerInvoice()?.customer_name"></span>
                                <span class="text-xs font-mono font-bold text-slate-600" x-text="currentRunnerInvoice()?.customer_phone"></span>
                            </div>
                            <div class="flex items-center justify-between text-xs text-slate-600">
                                <span>Total Milk: <strong class="text-slate-800" x-text="currentRunnerInvoice()?.total_units + ' L'"></strong></span>
                                <span>Payable: <strong class="text-emerald-700 text-sm" x-text="formatCurrency(currentRunnerInvoice()?.final_amount)"></strong></span>
                            </div>
                            <div class="text-[11px] text-slate-400 pt-1">
                                WhatsApp will open in a new tab with the pre-filled monthly bill details.
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex flex-col sm:flex-row items-center gap-2 pt-2">
                            <a :href="currentRunnerInvoice()?.pdf_url" target="_blank" download class="w-full sm:w-auto py-3 px-3.5 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-xl text-xs font-black border border-blue-200 transition flex items-center justify-center gap-1.5" title="Download PDF to drag and drop into WhatsApp chat">
                                <span>📥 Download PDF</span>
                            </a>
                            <button type="button" @click="dispatchCurrentAndNext()" class="flex-1 w-full py-3 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black shadow-md flex items-center justify-center gap-2 transition">
                                <span>🚀 Open Chat & Next</span>
                                <span>→</span>
                            </button>
                            <button type="button" @click="skipCurrent()" class="w-full sm:w-auto py-3 px-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
                                Skip
                            </button>
                        </div>
                    </div>
                </template>

                <!-- If Completed -->
                <template x-if="runnerDone">
                    <div class="text-center py-6 space-y-3">
                        <span class="text-5xl">🎉</span>
                        <h4 class="text-lg font-black text-slate-900">Batch Dispatch Complete!</h4>
                        <p class="text-xs text-slate-600 max-w-sm mx-auto">
                            All selected invoices have been processed through WhatsApp Web.
                        </p>
                        <button type="button" @click="showRunnerModal = false; selectedIds = []" class="px-6 py-2.5 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-black shadow">
                            Done
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
function invoiceBatchApp(invoicesMap) {
    return {
        invoices: invoicesMap || {},
        selectedIds: [],
        showRunnerModal: false,
        runnerIndex: 0,
        runnerList: [],
        runnerDone: false,

        isAllSelected() {
            const keys = Object.keys(this.invoices);
            return keys.length > 0 && this.selectedIds.length === keys.length;
        },

        toggleSelectAll(checked) {
            if (checked) {
                this.selectedIds = Object.keys(this.invoices).map(k => parseInt(k, 10));
            } else {
                this.selectedIds = [];
            }
        },

        selectOnlyUnpaid() {
            this.selectedIds = Object.values(this.invoices)
                .filter(inv => inv.payment_status !== 'paid')
                .map(inv => inv.id);
        },

        selectedTotalAmount() {
            return this.selectedIds.reduce((sum, id) => {
                const inv = this.invoices[id];
                return sum + (inv ? inv.final_amount : 0);
            }, 0);
        },

        formatCurrency(num) {
            return '₹' + parseFloat(num || 0).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        },

        startSequentialRunner() {
            if (this.selectedIds.length === 0) return;
            this.runnerList = this.selectedIds.map(id => this.invoices[id]).filter(Boolean);
            this.runnerIndex = 0;
            this.runnerDone = false;
            this.showRunnerModal = true;
        },

        currentRunnerInvoice() {
            return this.runnerList[this.runnerIndex] || null;
        },

        dispatchCurrentAndNext() {
            const current = this.currentRunnerInvoice();
            if (current && current.wa_url) {
                window.open(current.wa_url, '_blank');
            }

            if (this.runnerIndex + 1 < this.runnerList.length) {
                this.runnerIndex++;
            } else {
                this.runnerDone = true;
            }
        },

        skipCurrent() {
            if (this.runnerIndex + 1 < this.runnerList.length) {
                this.runnerIndex++;
            } else {
                this.runnerDone = true;
            }
        }
    };
}
</script>
