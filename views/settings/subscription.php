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

    <?php if (!empty($pendingRequest)): ?>
    <!-- Pending Subscription Request Banner -->
    <div class="bg-amber-50 rounded-3xl border-2 border-amber-300 p-6 shadow-sm space-y-3">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-start gap-3">
                <span class="text-2xl">⏳</span>
                <div>
                    <h3 class="text-sm font-black text-amber-950 uppercase tracking-wider">
                        Subscription Request Pending SuperAdmin Verification
                    </h3>
                    <p class="text-xs text-amber-800 mt-0.5">
                        <?php
                            $pReqType = ucfirst((string)($pendingRequest['request_type'] ?? 'plan'));
                            $pReqCycle = ucfirst((string)($pendingRequest['billing_cycle'] ?? 'monthly'));
                            $pPlanName = (string)($pendingRequest['plan_name'] ?? 'Paid Plan');
                            $pLimit = (int)($pendingRequest['customer_limit'] ?? $pendingRequest['customer_slots'] ?? 100);
                        ?>
                        Your payment proof of <strong><?= formatMoney((float)$pendingRequest['amount'], $quota['currency_symbol']) ?></strong> for <strong><?= e($pPlanName) ?> (<?= e($pReqCycle) ?> • <?= e($pReqType) ?> • <?= $pLimit ?> customers)</strong> has been recorded and is awaiting verification.
                    </p>
                </div>
            </div>
            <span class="px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-amber-200 text-amber-900 border border-amber-300 self-start sm:self-center">
                Pending Verification
            </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-3 border-t border-amber-200 text-xs text-amber-900">
            <div>
                <span class="text-[10px] font-black uppercase text-amber-700 block">Transaction Reference / UTR</span>
                <span class="font-mono font-black text-sm"><?= e($pendingRequest['utr_number']) ?></span>
            </div>
            <div>
                <span class="text-[10px] font-black uppercase text-amber-700 block">Payment Method</span>
                <span class="font-bold uppercase"><?= e($pendingRequest['payment_method']) ?></span>
            </div>
            <div>
                <span class="text-[10px] font-black uppercase text-amber-700 block">Submitted At</span>
                <span class="font-medium"><?= formatDate($pendingRequest['created_at'], 'd M Y, h:i A') ?></span>
            </div>
        </div>
        <p class="text-[11px] text-amber-700 italic">
            Once platform administration reconciles the transaction, your selected plan will activate automatically.
        </p>
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
            Your <strong><?= e($currentPlanName) ?></strong> plan expired on <strong><?= formatDate($quota['valid_until'], 'd M Y') ?></strong>. Capacity has soft-downgraded to the Free limit (<?= (int)$quota['free_limit'] ?> active customers). Existing customers and daily deliveries continue working; choose any plan below to reactivate.
        </p>
    </div>
    <?php elseif ($isInGrace): ?>
    <div class="p-4 rounded-2xl bg-amber-50 border-2 border-amber-300 text-amber-900 text-xs">
        <div class="font-black uppercase tracking-wider text-amber-800">⏳ Grace Period Active (<?= (int)($quota['grace_days_remaining'] ?? 0) ?> day(s) left)</div>
        <p class="mt-0.5">
            Your <strong><?= e($currentPlanName) ?></strong> plan ended on <strong><?= formatDate($quota['valid_until'], 'd M Y') ?></strong>. Renew or switch plan before <strong><?= formatDate($quota['grace_ends_on'], 'd M Y') ?></strong> to keep your <?= (int)$quota['max_allowed'] ?>-customer limit.
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
                    <?= $quota['active_customers'] ?> <span class="text-sm font-semibold text-slate-500">/ <?= $quota['max_allowed'] ?> Slots</span>
                </div>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="py-6 border-b border-slate-100">
            <div class="flex justify-between text-xs font-bold text-slate-700 mb-2">
                <span>Capacity Utilized: <?= $quota['usage_percent'] ?>%</span>
                <span class="<?= $quota['remaining_slots'] <= 1 ? 'text-rose-600' : 'text-emerald-600' ?>">
                    <?= $quota['remaining_slots'] ?> Slots Remaining
                </span>
            </div>
            <div class="w-full bg-slate-100 rounded-full h-3.5 overflow-hidden p-0.5 border border-slate-200">
                <div class="h-full rounded-full transition-all duration-500 <?= $quota['usage_percent'] >= 90 ? 'bg-rose-500' : 'bg-emerald-500' ?>" style="width: <?= $quota['usage_percent'] ?>%"></div>
            </div>
        </div>

        <!-- Current Plan Summary -->
        <div class="py-6 border-b border-slate-100 grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                <div class="text-[10px] uppercase font-black text-slate-400">Active Plan</div>
                <div class="text-xl font-black text-slate-900 mt-1"><?= e($currentPlanName) ?></div>
                <p class="text-slate-500 mt-0.5">Free tier base: <?= (int)$quota['free_limit'] ?> customers.</p>
            </div>
            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                <div class="text-[10px] uppercase font-black text-slate-400">Allowed Customers</div>
                <div class="text-xl font-black text-slate-900 mt-1"><?= (int)$quota['max_allowed'] ?> Max</div>
                <p class="text-slate-500 mt-0.5"><?= (int)$quota['active_customers'] ?> active currently.</p>
            </div>
            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                <div class="text-[10px] uppercase font-black text-slate-400">Renewal Rule</div>
                <div class="text-sm font-black text-blue-700 mt-1">
                    <?= !empty($quota['valid_until']) ? 'Exp: ' . formatDate($quota['valid_until'], 'd M Y') : 'No Expiry (Free)' ?>
                </div>
                <p class="text-slate-500 mt-0.5">Early renewal extends after current expiry.</p>
            </div>
        </div>

        <!-- Plan Selection & Offline Payment -->
        <div class="pt-6 space-y-6">
            <div>
                <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
                    <span>⚡ Subscribe, Renew, or Switch Plan</span>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Renewing the same pack before expiry extends from your current expiry date. Switching to a smaller pack is available within <?= (int)($quota['renewal_window_days'] ?? 7) ?> days of expiry.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                <!-- STEP 1: PAYMENT DETAILS & QR -->
                <div class="bg-gradient-to-br from-slate-900 to-slate-950 text-white p-6 rounded-2xl border border-slate-800 space-y-4 shadow-sm">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                        <span class="text-[11px] font-black uppercase tracking-wider text-purple-400">Step 1: Transfer Payment</span>
                        <span class="text-xs text-slate-400">UPI / IMPS / NEFT</span>
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Official Platform UPI ID</label>
                        <div class="flex items-center justify-between bg-slate-800/80 px-3.5 py-2.5 rounded-xl border border-slate-700">
                            <span class="font-mono text-sm font-black text-emerald-400" id="upi-id-text"><?= e($bankDetails['upi_id'] ?? 'kaustubh51-3@okicici') ?></span>
                            <button type="button" onclick="copyToClipboard('upi-id-text', this)" class="text-[11px] font-bold px-2 py-1 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition">
                                Copy
                            </button>
                        </div>
                    </div>

                    <?php 
                        $upiId = $bankDetails['upi_id'] ?? 'kaustubh51-3@okicici';
                        $accName = $bankDetails['account_name'] ?? 'DoodhBee SaaS';
                        $upiUri = "upi://pay?pa=" . urlencode($upiId) . "&pn=" . urlencode($accName) . "&cu=INR";
                        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=" . urlencode($upiUri);
                    ?>
                    <div class="p-3 bg-white rounded-xl flex items-center justify-center gap-4 text-slate-900">
                        <img src="<?= $qrUrl ?>" alt="UPI QR Code" class="w-28 h-28 object-contain rounded-lg border border-slate-200" onerror="this.style.display='none'">
                        <div class="space-y-1 text-left">
                            <div class="text-[11px] font-black text-slate-900">Scan via any UPI App</div>
                            <div class="flex flex-wrap gap-1 text-[10px] font-bold text-slate-500">
                                <span class="px-1.5 py-0.5 rounded bg-slate-100">GPay</span>
                                <span class="px-1.5 py-0.5 rounded bg-slate-100">PhonePe</span>
                                <span class="px-1.5 py-0.5 rounded bg-slate-100">Paytm</span>
                                <span class="px-1.5 py-0.5 rounded bg-slate-100">BHIM</span>
                            </div>
                            <a href="<?= e($upiUri) ?>" class="inline-block mt-2 text-[10px] font-black text-blue-600 hover:underline">
                                ↗ Pay directly on Mobile
                            </a>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-800 space-y-2 text-xs">
                        <div class="text-[10px] font-black uppercase text-slate-400">Direct Bank Transfer (IMPS / NEFT)</div>
                        <div class="grid grid-cols-2 gap-2 text-[11px]">
                            <div>
                                <span class="text-slate-400 block text-[10px]">Beneficiary Name</span>
                                <span class="font-bold text-slate-200"><?= e($bankDetails['account_name'] ?? 'Shri Ram Open Source Technologies') ?></span>
                            </div>
                            <div>
                                <span class="text-slate-400 block text-[10px]">Bank</span>
                                <span class="font-bold text-slate-200"><?= e($bankDetails['bank_name'] ?? 'Saraswat Co-operative Bank Ltd.') ?></span>
                            </div>
                            <div>
                                <span class="text-slate-400 block text-[10px]">Account No</span>
                                <span class="font-mono font-bold text-slate-200"><?= e($bankDetails['account_number'] ?? '610000000039273') ?></span>
                            </div>
                            <div>
                                <span class="text-slate-400 block text-[10px]">IFSC Code</span>
                                <span class="font-mono font-bold text-slate-200"><?= e($bankDetails['ifsc_code'] ?? 'srcb0000370') ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: PLAN PICKER & SUBMIT UTR -->
                <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200 space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-200">
                        <span class="text-[11px] font-black uppercase tracking-wider text-blue-700">Step 2: Choose Plan & Submit UTR</span>
                        <span class="text-xs text-slate-500">UTR Verification</span>
                    </div>

                    <form method="POST" action="<?= url('/subscription/request-upgrade') ?>" class="space-y-4" id="plan-request-form">
                        <?= \App\Core\Auth::csrfField() ?>

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
                            <div class="space-y-2">
                                <?php foreach ($paidPlans as $pp): ?>
                                    <?php
                                        $pid = (int)$pp['id'];
                                        $isCurrent = ($pid === $currentPlanId && $isPaidActive);
                                    ?>
                                    <label class="block p-3.5 rounded-xl border-2 border-slate-200 bg-white hover:border-blue-500 cursor-pointer transition plan-card" data-plan-id="<?= $pid ?>">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-2.5">
                                                <input type="radio" name="plan_id" value="<?= $pid ?>" <?= $pid === $defaultSelectedPlanId ? 'checked' : '' ?> onchange="refreshPlanCards()" class="text-blue-600">
                                                <div>
                                                    <div class="font-black text-xs text-slate-900 flex items-center gap-1.5">
                                                        <span><?= e($pp['name']) ?></span>
                                                        <?php if ($isCurrent): ?>
                                                            <span class="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[9px] uppercase font-black">Current</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-[11px] text-slate-500">Up to <strong><?= (int)$pp['customer_limit'] ?></strong> active customers</div>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <div class="font-black text-sm text-blue-700 plan-card-price" data-plan-id="<?= $pid ?>">
                                                    <?= formatMoney((float)$pp['monthly_price'], $currency) ?>
                                                </div>
                                                <div class="text-[10px] font-bold uppercase text-slate-400 plan-card-action" data-plan-id="<?= $pid ?>"></div>
                                            </div>
                                        </div>
                                        <div class="text-[10px] text-rose-600 font-semibold mt-1 hidden plan-card-reason" data-plan-id="<?= $pid ?>"></div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Amount & GST Preview -->
                        <div class="p-3.5 rounded-xl bg-blue-50 border border-blue-200 space-y-1.5 text-xs">
                            <div class="flex items-center justify-between text-slate-600">
                                <span>Base Plan Price:</span>
                                <span class="font-bold" id="display-base-amount">—</span>
                            </div>
                            <div class="flex items-center justify-between text-slate-600" id="display-gst-row">
                                <span id="display-gst-label">GST (18% Exclusive):</span>
                                <span class="font-bold" id="display-gst-amount">—</span>
                            </div>
                            <div class="flex items-center justify-between pt-1 border-t border-blue-200">
                                <span class="font-black text-slate-800">Total Payable (Incl. GST):</span>
                                <span class="text-base font-black text-blue-800" id="display-amount">—</span>
                            </div>
                            <p class="text-[11px] text-blue-900" id="plan-action-note"></p>
                            <p class="text-[10px] text-slate-500">
                                Tax Invoice issued by <strong><?= e($pricing['company_legal_name'] ?? 'Shri Ram Open Source Technologies') ?></strong> (GSTIN: <span class="font-mono"><?= e($pricing['company_gstin'] ?? '27AARPE1865G1ZJ') ?></span>) via Email & WhatsApp upon approval.
                            </p>
                        </div>

                        <!-- Payment Method -->
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-700 mb-1">
                                Payment Method Used
                            </label>
                            <select name="payment_method" class="w-full text-xs font-bold px-3 py-2 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                                <option value="upi">UPI (GPay / PhonePe / Paytm / BHIM)</option>
                                <option value="bank_transfer">Direct Bank Transfer (IMPS / NEFT / RTGS)</option>
                                <option value="cash">Cash / Direct Admin Settlement</option>
                                <option value="other">Other / Cheque</option>
                            </select>
                        </div>

                        <!-- Transaction Reference / UTR -->
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-700 mb-1">
                                UTR / Transaction Reference ID <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" name="utr_number" required placeholder="e.g. 12-digit UTR like 432109876543" class="w-full text-xs font-mono font-bold px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 text-slate-900">
                            <p class="text-[10px] text-slate-500 mt-1">Found in your payment app's receipt details.</p>
                        </div>

                        <!-- Optional Notes -->
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-700 mb-1">
                                Optional Notes / Payer Phone
                            </label>
                            <input type="text" name="notes" placeholder="e.g. Paid from PhonePe number 9876543210" class="w-full text-xs px-3.5 py-2 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 text-slate-900">
                        </div>

                        <button type="submit" id="submit-plan-btn" class="tap-target w-full py-3.5 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-400 text-white rounded-xl text-xs font-black shadow-md transition flex items-center justify-center gap-2">
                            <span>⚡ Submit Payment for Verification</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Subscription Requests History -->
    <?php if (!empty($upgradeRequests)): ?>
    <div class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm space-y-4">
        <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
            <span>📋 Subscription Request History</span>
        </h3>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-600">
                <thead class="bg-slate-50 text-slate-400 font-bold uppercase text-[10px] border-b border-slate-200">
                    <tr>
                        <th class="p-3">Date</th>
                        <th class="p-3">Plan</th>
                        <th class="p-3">Capacity</th>
                        <th class="p-3">Amount</th>
                        <th class="p-3">UTR / Ref</th>
                        <th class="p-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($upgradeRequests as $req): ?>
                        <tr class="hover:bg-slate-50/50">
                            <td class="p-3 font-medium whitespace-nowrap">
                                <?= formatDate($req['created_at'], 'd M Y, h:i A') ?>
                            </td>
                            <td class="p-3 font-bold text-slate-900">
                                <?php
                                    $rName = $req['plan_name'] ?: ('+' . $req['blocks_requested'] . ' Block(s)');
                                    $rType = ucfirst((string)($req['request_type'] ?? 'plan'));
                                    $rCycle = ucfirst((string)($req['billing_cycle'] ?? 'monthly'));
                                ?>
                                <?= e($rName) ?>
                                <span class="text-[10px] font-bold text-blue-700 block"><?= e($rCycle) ?> • <?= e($rType) ?></span>
                            </td>
                            <td class="p-3 text-emerald-700 font-bold">
                                <?= (int)($req['customer_limit'] ?: $req['customer_slots']) ?> Customers
                            </td>
                            <td class="p-3 font-black text-slate-900">
                                <?= formatMoney((float)$req['amount'], $currency) ?>
                            </td>
                            <td class="p-3 font-mono text-[11px]">
                                <span class="bg-slate-100 px-2 py-0.5 rounded font-black"><?= e($req['utr_number']) ?></span>
                                <?php if (!empty($req['notes'])): ?>
                                    <div class="text-[10px] text-slate-400 mt-0.5"><?= e($req['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 text-center whitespace-nowrap">
                                <?php if ($req['status'] === 'approved'): ?>
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-emerald-100 text-emerald-800 border border-emerald-300">
                                        ✓ Approved
                                    </span>
                                <?php elseif ($req['status'] === 'pending'): ?>
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-amber-100 text-amber-800 border border-amber-300">
                                        ⏳ Pending
                                    </span>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-rose-100 text-rose-800 border border-rose-300" title="<?= e($req['admin_notes'] ?? '') ?>">
                                        ✗ Rejected
                                    </span>
                                    <?php if (!empty($req['admin_notes'])): ?>
                                        <div class="text-[10px] text-rose-600 mt-0.5"><?= e($req['admin_notes']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

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
    const submitBtn = document.getElementById('submit-plan-btn');

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
            if (submitBtn) submitBtn.disabled = false;
            return;
        }
    }

    if (baseElem) baseElem.innerText = '—';
    if (gstElem) gstElem.innerText = '—';
    if (amountElem) amountElem.innerText = '—';
    if (noteElem) noteElem.innerText = 'No eligible plan available for your current active customer count.';
    if (submitBtn) submitBtn.disabled = true;
}

document.addEventListener('DOMContentLoaded', refreshPlanCards);

function copyToClipboard(elementId, btn) {
    const text = document.getElementById(elementId).innerText.trim();
    navigator.clipboard.writeText(text).then(() => {
        const originalText = btn.innerText;
        btn.innerText = 'Copied!';
        btn.classList.add('bg-emerald-600');
        setTimeout(() => {
            btn.innerText = originalText;
            btn.classList.remove('bg-emerald-600');
        }, 2000);
    });
}
</script>
