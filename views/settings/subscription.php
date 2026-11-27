<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <a href="<?= url('/settings') ?>" class="text-xs font-bold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1">
            <span>←</span> Back to Business Settings
        </a>
    </div>

    <?php if (!empty($success)): ?>
        <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-bold flex items-center gap-2">
            <span>✓</span> <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-bold flex items-center gap-2">
            <span>⚠️</span> <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php
        $currency = $quota['currency_symbol'] ?? '₹';
        $isExpired = !empty($quota['is_expired']);
        $isInGrace = !empty($quota['is_in_grace']);
        $isPaidActive = !empty($quota['is_paid_active']);
        $currentPlanId = (int)($quota['plan_id'] ?? 0);
        $currentPlanName = (string)($quota['plan_name'] ?? 'Free');
        $currentCycle = $quota['billing_cycle'] ?? null;
        $activeCustomers = (int)($quota['active_customers'] ?? 0);
        $maxAllowed = (int)($quota['max_allowed'] ?? 5);
        $freeLimit = (int)($quota['free_limit'] ?? 5);
        $usagePercent = (float)($quota['usage_percent'] ?? 0);
        $remainingSlots = (int)($quota['remaining_slots'] ?? 0);
        $paidPlans = array_values(array_filter($plans ?? [], fn($p) => empty($p['is_free'])));
        $defaultSelectedPlanId = 0;
        foreach ($paidPlans as $pp) {
            if ((int)$pp['id'] === $currentPlanId && !empty($pp['previews']['monthly']['allowed'])) {
                $defaultSelectedPlanId = (int)$pp['id'];
                break;
            }
        }
        if ($defaultSelectedPlanId === 0) {
            foreach ($paidPlans as $pp) {
                if (!empty($pp['previews']['monthly']['allowed']) || !empty($pp['previews']['yearly']['allowed'])) {
                    $defaultSelectedPlanId = (int)$pp['id'];
                    break;
                }
            }
        }
        if ($defaultSelectedPlanId === 0 && !empty($paidPlans)) {
            $defaultSelectedPlanId = (int)$paidPlans[0]['id'];
        }
    ?>

    <?php if ($isExpired): ?>
    <div class="p-4 rounded-2xl bg-rose-50 border-2 border-rose-300 text-rose-900 text-xs">
        <div class="font-black uppercase tracking-wider text-rose-800">⚠️ Paid Subscription Expired</div>
        <p class="mt-0.5">
            Your <strong><?= e($currentPlanName) ?></strong> plan expired on <strong><?= formatDate($quota['valid_until'] ?? null, 'd M Y') ?></strong>. Capacity has soft-downgraded to the Free limit (<?= $freeLimit ?> active customers). Existing customers and daily deliveries continue working; choose any plan below to reactivate.
        </p>
    </div>
    <?php elseif ($isInGrace): ?>
    <div class="p-4 rounded-2xl bg-amber-50 border-2 border-amber-300 text-amber-900 text-xs">
        <div class="font-black uppercase tracking-wider text-amber-800">⏳ Grace Period Active (<?= (int)($quota['grace_days_remaining'] ?? 0) ?> day(s) left)</div>
        <p class="mt-0.5">
            Your <strong><?= e($currentPlanName) ?></strong> plan ended on <strong><?= formatDate($quota['valid_until'] ?? null, 'd M Y') ?></strong>. Renew or switch plan before <strong><?= formatDate($quota['grace_ends_on'] ?? null, 'd M Y') ?></strong> to keep your <?= $maxAllowed ?>-customer limit.
        </p>
    </div>
    <?php endif; ?>

    <!-- Current Subscription Card -->
    <div class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100">
            <div>
                <span class="text-xs px-2.5 py-1 rounded-full font-black uppercase tracking-wider <?= $isExpired ? 'bg-rose-100 text-rose-800 border border-rose-300' : ($isInGrace ? 'bg-amber-100 text-amber-800 border border-amber-300' : ($isPaidActive ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-blue-100 text-blue-800 border border-blue-300')) ?>">
                    <?= e($currentPlanName) ?> Plan
                    <?= $currentCycle ? '• ' . ucfirst($currentCycle) : '' ?>
                    <?= $isExpired ? ' (Expired)' : ($isInGrace ? ' (Grace Period)' : '') ?>
                </span>
                <h1 class="text-2xl font-black text-slate-900 mt-2">SaaS Plans & Capacity</h1>
                <p class="text-xs text-slate-500 mt-0.5">
                    Pick a simple Monthly or Yearly pack matching your active customers.
                    <?php if (!empty($quota['valid_until'])): ?>
                        • Valid until <strong><?= formatDate($quota['valid_until'], 'd M Y') ?></strong>
                    <?php endif; ?>
                </p>
            </div>

            <div class="text-left sm:text-right">
                <div class="text-xs text-slate-400 font-bold uppercase">Customer Capacity</div>
                <div class="text-3xl font-black text-slate-900">
                    <?= $activeCustomers ?> <span class="text-sm font-semibold text-slate-500">/ <?= $maxAllowed ?> Slots</span>
                </div>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="py-6 border-b border-slate-100">
            <div class="flex justify-between text-xs font-bold text-slate-700 mb-2">
                <span>Capacity Utilized: <?= $usagePercent ?>%</span>
                <span class="<?= $remainingSlots <= 1 ? 'text-rose-600' : 'text-emerald-600' ?>">
                    <?= $remainingSlots ?> Slots Remaining
                </span>
            </div>
            <div class="w-full bg-slate-100 rounded-full h-3.5 overflow-hidden p-0.5 border border-slate-200">
                <div class="h-full rounded-full transition-all duration-500 <?= $usagePercent >= 90 ? 'bg-rose-500' : 'bg-emerald-500' ?>" style="width: <?= $usagePercent ?>%"></div>
            </div>
        </div>

        <!-- Current Plan Summary -->
        <div class="py-6 border-b border-slate-100 grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                <div class="text-[10px] uppercase font-black text-slate-400">Active Plan</div>
                <div class="text-xl font-black text-slate-900 mt-1"><?= e($currentPlanName) ?></div>
                <p class="text-slate-500 mt-0.5">Free tier base: <?= $freeLimit ?> customers.</p>
            </div>
            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                <div class="text-[10px] uppercase font-black text-slate-400">Allowed Customers</div>
                <div class="text-xl font-black text-slate-900 mt-1"><?= $maxAllowed ?> Max</div>
                <p class="text-slate-500 mt-0.5"><?= $activeCustomers ?> active currently.</p>
            </div>
            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                <div class="text-[10px] uppercase font-black text-slate-400">Renewal Rule</div>
                <div class="text-sm font-black text-blue-700 mt-1">
                    <?= !empty($quota['valid_until']) ? 'Exp: ' . formatDate($quota['valid_until'], 'd M Y') : 'No Expiry (Free)' ?>
                </div>
                <p class="text-slate-500 mt-0.5">Early renewal extends after current expiry.</p>
            </div>
        </div>

        <!-- Plan Selection & Razorpay Checkout -->
        <div class="pt-6 space-y-5">
            <div>
                <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
                    <span>⚡ Subscribe, Renew, or Switch Plan</span>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Renewing the same pack before expiry extends from your current expiry date. Switching to a smaller pack is available within <?= (int)($quota['renewal_window_days'] ?? 7) ?> days of expiry.
                </p>
            </div>

            <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200 space-y-4">
                <!-- Billing Cycle Toggle -->
                <div>
                    <label class="block text-xs font-black uppercase text-slate-700 mb-2">
                        Billing Cycle
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="p-2.5 rounded-xl border-2 border-slate-200 bg-white hover:border-blue-500 cursor-pointer transition text-center">
                            <input type="radio" name="billing_cycle" value="monthly" <?= ($currentCycle !== 'yearly') ? 'checked' : '' ?> onchange="refreshPlanCards()" class="mr-1 text-blue-600">
                            <span class="font-black text-xs text-slate-900">Monthly</span>
                        </label>
                        <label class="p-2.5 rounded-xl border-2 border-slate-200 bg-white hover:border-emerald-500 cursor-pointer transition text-center">
                            <input type="radio" name="billing_cycle" value="yearly" <?= ($currentCycle === 'yearly') ? 'checked' : '' ?> onchange="refreshPlanCards()" class="mr-1 text-emerald-600">
                            <span class="font-black text-xs text-slate-900">Yearly (Save ~17%)</span>
                        </label>
                    </div>
                </div>

                <!-- Named Plan Cards -->
                <div>
                    <label class="block text-xs font-black uppercase text-slate-700 mb-2">
                        Select Subscription Pack
                    </label>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <?php if (empty($paidPlans)): ?>
                            <div class="col-span-full p-4 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900 font-semibold">
                                No active subscription packages are available right now. Please refresh or contact support.
                            </div>
                        <?php else: ?>
                        <?php foreach ($paidPlans as $pp): ?>
                            <?php
                                $pid = (int)$pp['id'];
                                $isCurrent = ($pid === $currentPlanId && $isPaidActive);
                            ?>
                            <label class="block p-4 rounded-xl border-2 border-slate-200 bg-white hover:border-blue-500 cursor-pointer transition plan-card" data-plan-id="<?= $pid ?>">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex items-start gap-2.5">
                                        <input type="radio" name="plan_id" value="<?= $pid ?>" <?= $pid === $defaultSelectedPlanId ? 'checked' : '' ?> onchange="refreshPlanCards()" class="mt-0.5 text-blue-600">
                                        <div>
                                            <div class="font-black text-sm text-slate-900 flex items-center gap-1.5">
                                                <span><?= e($pp['name']) ?></span>
                                                <?php if ($isCurrent): ?>
                                                    <span class="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[9px] uppercase font-black">Current</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[11px] text-slate-500 mt-0.5">Up to <strong><?= (int)$pp['customer_limit'] ?></strong> active customers</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 pt-2.5 border-t border-slate-100 flex items-baseline justify-between">
                                    <div class="font-black text-sm text-blue-700 plan-card-price" data-plan-id="<?= $pid ?>">
                                        <?= formatMoney((float)$pp['monthly_price'], $currency) ?>
                                    </div>
                                    <div class="text-[10px] font-bold uppercase text-slate-400 plan-card-action" data-plan-id="<?= $pid ?>"></div>
                                </div>
                                <div class="text-[10px] text-rose-600 font-semibold mt-1.5 hidden plan-card-reason" data-plan-id="<?= $pid ?>"></div>
                            </label>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Amount & GST Preview -->
                <div class="p-4 rounded-xl bg-blue-50 border border-blue-200 space-y-1.5 text-xs">
                    <div class="flex items-center justify-between text-slate-600">
                        <span>Base Plan Price:</span>
                        <span class="font-bold" id="display-base-amount">—</span>
                    </div>
                    <div class="flex items-center justify-between text-slate-600" id="display-gst-row">
                        <span id="display-gst-label">GST (18% Exclusive):</span>
                        <span class="font-bold" id="display-gst-amount">—</span>
                    </div>
                    <div class="flex items-center justify-between pt-1.5 border-t border-blue-200">
                        <span class="font-black text-slate-800">Total Payable (Incl. GST):</span>
                        <span class="text-lg font-black text-blue-800" id="display-amount">—</span>
                    </div>
                    <p class="text-[11px] text-blue-900" id="plan-action-note"></p>
                    <p class="text-[10px] text-slate-500">
                        Tax Invoice issued by <strong><?= e($pricing['company_legal_name'] ?? 'Shri Ram Open Source Technologies') ?></strong> (GSTIN: <span class="font-mono"><?= e($pricing['company_gstin'] ?? '27AARPE1865G1ZJ') ?></span>) via Email & WhatsApp.
                    </p>
                </div>

                <?php if (!empty($razorpay['configured'])): ?>
                <!-- Instant Online Payment via Razorpay -->
                <div class="p-4 rounded-xl bg-gradient-to-r from-indigo-50 to-blue-50 border-2 border-indigo-200 space-y-2.5">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-black uppercase tracking-wider text-indigo-900">⚡ Razorpay Secure Checkout</span>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-800">UPI • Cards • NetBanking</span>
                    </div>
                    <div id="razorpay-error-box" class="hidden p-2.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-[11px] font-bold"></div>
                    <button type="button" id="razorpay-pay-btn" onclick="startRazorpayCheckout()" class="tap-target w-full py-3.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-400 text-white rounded-xl text-sm font-black shadow-md transition flex items-center justify-center gap-2">
                        <span>💳 Pay Online with Razorpay</span>
                    </button>
                    <p class="text-[10px] text-indigo-800 text-center">Instant plan activation & automatic GST Tax Invoice via Email & WhatsApp.</p>
                </div>
                <?php else: ?>
                <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900">
                    <strong>Razorpay Gateway Not Configured:</strong> Please ask the Super Admin to enable Razorpay and configure API keys in the Admin Portal.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Subscription GST Tax Invoices -->
    <?php if (!empty($subInvoices)): ?>
    <div class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
                <span>🧾 Subscription GST Tax Invoices</span>
            </h3>
            <span class="text-[11px] text-slate-500 font-semibold">
                Issued by <?= e($pricing['company_legal_name'] ?? 'Shri Ram Open Source Technologies') ?> (GSTIN: <?= e($pricing['company_gstin'] ?? '27AARPE1865G1ZJ') ?>)
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-600">
                <thead class="bg-slate-50 text-slate-400 font-bold uppercase text-[10px] border-b border-slate-200">
                    <tr>
                        <th class="p-3">Invoice No</th>
                        <th class="p-3">Plan & Validity</th>
                        <th class="p-3 text-right">Base</th>
                        <th class="p-3 text-right">GST</th>
                        <th class="p-3 text-right">Total</th>
                        <th class="p-3 text-center">Delivery</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($subInvoices as $inv): ?>
                        <tr class="hover:bg-slate-50/50">
                            <td class="p-3 font-mono font-black text-slate-900 whitespace-nowrap">
                                <?= e($inv['invoice_number']) ?>
                                <div class="text-[10px] font-sans font-normal text-slate-400"><?= formatDate($inv['created_at'], 'd M Y') ?></div>
                            </td>
                            <td class="p-3">
                                <span class="font-bold text-slate-900"><?= e($inv['plan_name']) ?> (<?= ucfirst(e($inv['billing_cycle'])) ?>)</span>
                                <div class="text-[10px] text-slate-500"><?= formatDate($inv['period_start'], 'd M Y') ?> – <?= formatDate($inv['period_end'], 'd M Y') ?></div>
                            </td>
                            <td class="p-3 text-right font-medium"><?= formatMoney((float)$inv['base_amount'], $currency) ?></td>
                            <td class="p-3 text-right text-slate-500"><?= formatMoney((float)$inv['gst_amount'], $currency) ?> (<?= number_format((float)$inv['gst_percentage'], 0) ?>%)</td>
                            <td class="p-3 text-right font-black text-slate-900"><?= formatMoney((float)$inv['total_amount'], $currency) ?></td>
                            <td class="p-3 text-center text-[10px]">
                                <span class="inline-block px-1.5 py-0.5 rounded font-bold <?= $inv['email_status'] === 'sent' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' ?>">
                                    Email: <?= e($inv['email_status']) ?>
                                </span>
                                <span class="inline-block px-1.5 py-0.5 rounded font-bold mt-0.5 <?= $inv['whatsapp_status'] === 'sent' ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-700' ?>">
                                    WA: <?= e($inv['whatsapp_status']) ?>
                                </span>
                            </td>
                            <td class="p-3 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    <a href="<?= url('/subscription/invoices/' . (int)$inv['id']) ?>" target="_blank" class="px-2 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-[11px]">View</a>
                                    <a href="<?= url('/subscription/invoices/' . (int)$inv['id'] . '/pdf') ?>" target="_blank" class="px-2 py-1 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold text-[11px]">PDF</a>
                                    <?php if (!empty($inv['whatsapp_url'])): ?>
                                        <a href="<?= e($inv['whatsapp_url']) ?>" target="_blank" class="px-2 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-bold text-[11px]">WhatsApp</a>
                                    <?php endif; ?>
                                    <form method="POST" action="<?= url('/subscription/invoices/' . (int)$inv['id'] . '/resend') ?>" class="inline">
                                        <?= \App\Core\Auth::csrfField() ?>
                                        <button type="submit" class="px-2 py-1 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-800 font-bold text-[11px]">Resend</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($razorpay['configured'])): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php endif; ?>
<script>
const PLAN_PREVIEWS = <?= json_encode(array_column($paidPlans, 'previews', 'id')) ?>;
const PLAN_CURRENCY = <?= json_encode($currency) ?>;

function refreshPlanCards() {
    const cycleEl = document.querySelector('input[name="billing_cycle"]:checked');
    const cycle = cycleEl ? cycleEl.value : 'monthly';

    document.querySelectorAll('.plan-card').forEach((card) => {
        const pid = card.getAttribute('data-plan-id');
        const radio = card.querySelector('input[name="plan_id"]');
        const preview = PLAN_PREVIEWS[pid] ? PLAN_PREVIEWS[pid][cycle] : null;
        const priceEl = card.querySelector('.plan-card-price');
        const actionEl = card.querySelector('.plan-card-action');
        const reasonEl = card.querySelector('.plan-card-reason');

        if (!preview) return;

        if (priceEl) {
            priceEl.innerText = PLAN_CURRENCY + Number(preview.base_amount).toFixed(2) + (Number(preview.gst_percentage) > 0 ? ' + GST' : '');
        }
        if (actionEl) {
            actionEl.innerText = preview.allowed ? preview.action_type : 'unavailable';
        }
        if (preview.allowed) {
            card.classList.remove('opacity-60', 'bg-slate-100');
            card.classList.add('bg-white');
            if (radio) radio.disabled = false;
            if (reasonEl) {
                reasonEl.classList.add('hidden');
                reasonEl.innerText = '';
            }
        } else {
            card.classList.add('opacity-60', 'bg-slate-100');
            card.classList.remove('bg-white');
            if (radio) radio.disabled = true;
            if (reasonEl) {
                reasonEl.classList.remove('hidden');
                reasonEl.innerText = preview.reason || 'Unavailable';
            }
        }
    });

    let selectedRadio = document.querySelector('input[name="plan_id"]:checked');
    if (selectedRadio && selectedRadio.disabled) {
        const firstEnabled = document.querySelector('input[name="plan_id"]:not(:disabled)');
        if (firstEnabled) {
            firstEnabled.checked = true;
            selectedRadio = firstEnabled;
        }
    }

    const baseElem = document.getElementById('display-base-amount');
    const gstRow = document.getElementById('display-gst-row');
    const gstLabel = document.getElementById('display-gst-label');
    const gstElem = document.getElementById('display-gst-amount');
    const amountElem = document.getElementById('display-amount');
    const noteElem = document.getElementById('plan-action-note');
    const rzpBtn = document.getElementById('razorpay-pay-btn');

    if (selectedRadio && !selectedRadio.disabled) {
        const selPid = selectedRadio.value;
        const selPrev = PLAN_PREVIEWS[selPid] ? PLAN_PREVIEWS[selPid][cycle] : null;
        if (selPrev) {
            if (baseElem) baseElem.innerText = PLAN_CURRENCY + Number(selPrev.base_amount).toFixed(2);
            if (gstLabel) gstLabel.innerText = 'GST (' + Number(selPrev.gst_percentage).toFixed(0) + '% Exclusive):';
            if (gstElem) gstElem.innerText = PLAN_CURRENCY + Number(selPrev.gst_amount).toFixed(2);
            if (gstRow) gstRow.style.display = Number(selPrev.gst_percentage) > 0 ? 'flex' : 'none';
            if (amountElem) amountElem.innerText = PLAN_CURRENCY + Number(selPrev.amount).toFixed(2);
            if (noteElem) noteElem.innerText = selPrev.note || '';
            if (rzpBtn) rzpBtn.disabled = false;
            return;
        }
    }

    if (baseElem) baseElem.innerText = '—';
    if (gstElem) gstElem.innerText = '—';
    if (amountElem) amountElem.innerText = '—';
    if (noteElem) noteElem.innerText = 'No eligible plan available for your current active customer count.';
    if (rzpBtn) rzpBtn.disabled = true;
}

document.addEventListener('DOMContentLoaded', refreshPlanCards);

<?php if (!empty($razorpay['configured'])): ?>
const RZP_ENDPOINTS = {
    order: <?= json_encode(url('/subscription/razorpay/order')) ?>,
    verify: <?= json_encode(url('/subscription/razorpay/verify')) ?>,
    csrf: <?= json_encode(\App\Core\Auth::csrfToken()) ?>
};

function showRzpError(msg) {
    const box = document.getElementById('razorpay-error-box');
    if (box) {
        box.innerText = msg;
        box.classList.remove('hidden');
    }
}

async function startRazorpayCheckout() {
    const btn = document.getElementById('razorpay-pay-btn');
    const errBox = document.getElementById('razorpay-error-box');
    if (errBox) errBox.classList.add('hidden');

    const selectedRadio = document.querySelector('input[name="plan_id"]:checked');
    const cycleEl = document.querySelector('input[name="billing_cycle"]:checked');
    if (!selectedRadio || selectedRadio.disabled) {
        showRzpError('Please select an eligible subscription plan.');
        return;
    }
    if (typeof Razorpay === 'undefined') {
        showRzpError('Razorpay Checkout script failed to load. Please check your internet connection.');
        return;
    }

    const origText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span>⏳ Creating Razorpay Order...</span>';
    }

    try {
        const res = await fetch(RZP_ENDPOINTS.order, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': RZP_ENDPOINTS.csrf
            },
            body: JSON.stringify({
                plan_id: parseInt(selectedRadio.value, 10),
                billing_cycle: cycleEl ? cycleEl.value : 'monthly'
            })
        });
        const json = await res.json();
        if (!json.success || !json.data || !json.data.order_id) {
            throw new Error(json.error || 'Could not initialize Razorpay checkout.');
        }

        const order = json.data;
        const rzp = new Razorpay({
            key: order.key_id,
            amount: order.amount_paise,
            currency: order.currency || 'INR',
            name: order.company_name || 'Shri Ram Open Source Technologies',
            description: order.description || 'DoodhBee Subscription',
            order_id: order.order_id,
            prefill: order.prefill || {},
            theme: { color: '#4f46e5' },
            handler: async function (response) {
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span>⏳ Verifying Payment...</span>';
                }
                try {
                    const vRes = await fetch(RZP_ENDPOINTS.verify, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': RZP_ENDPOINTS.csrf
                        },
                        body: JSON.stringify({
                            razorpay_order_id: response.razorpay_order_id,
                            razorpay_payment_id: response.razorpay_payment_id,
                            razorpay_signature: response.razorpay_signature
                        })
                    });
                    const vJson = await vRes.json();
                    if (!vJson.success) {
                        throw new Error(vJson.error || 'Payment verification failed.');
                    }
                    window.location.reload();
                } catch (e) {
                    showRzpError(e.message || 'Verification failed.');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origText;
                    }
                }
            },
            modal: {
                ondismiss: function () {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origText;
                    }
                }
            }
        });
        rzp.on('payment.failed', function (resp) {
            showRzpError((resp.error && resp.error.description) ? resp.error.description : 'Payment failed.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        });
        rzp.open();
    } catch (e) {
        showRzpError(e.message || 'Unable to start Razorpay checkout.');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }
}
<?php endif; ?>
</script>
