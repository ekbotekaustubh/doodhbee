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
    <!-- Pending Upgrade Request Banner -->
    <div class="bg-amber-50 rounded-3xl border-2 border-amber-300 p-6 shadow-sm space-y-3">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-start gap-3">
                <span class="text-2xl">⏳</span>
                <div>
                    <h3 class="text-sm font-black text-amber-950 uppercase tracking-wider">
                        Upgrade Request Pending SuperAdmin Verification
                    </h3>
                    <p class="text-xs text-amber-800 mt-0.5">
                        Your payment proof of <strong><?= formatMoney((float)$pendingRequest['amount'], $quota['currency_symbol']) ?></strong> for <strong>+<?= (int)$pendingRequest['blocks_requested'] ?> capacity block (+<?= (int)$pendingRequest['customer_slots'] ?> customer slots)</strong> has been recorded and is currently awaiting verification.
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
            Once platform administration reconciles the transaction in the bank statement, your extra capacity will activate automatically.
        </p>
    </div>
    <?php endif; ?>

    <!-- Current Subscription Card -->
    <div class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100">
            <div>
                <span class="text-xs px-2.5 py-1 rounded-full font-black uppercase tracking-wider <?= $quota['payment_status'] === 'active' ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-blue-100 text-blue-800 border border-blue-300' ?>">
                    <?= ucfirst($quota['payment_status']) ?> Subscription Plan
                </span>
                <h1 class="text-2xl font-black text-slate-900 mt-2">SaaS Quota & Capacity</h1>
                <p class="text-xs text-slate-500 mt-0.5">Scale your dairy deliveries as your customer base expands.</p>
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

        <!-- Quota Rule Explanation -->
        <div class="py-6 border-b border-slate-100 grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                <div class="text-[10px] uppercase font-black text-slate-400">Free Tier Allowance</div>
                <div class="text-xl font-black text-slate-900 mt-1"><?= $quota['free_limit'] ?> Customers</div>
                <p class="text-slate-500 mt-0.5">Free forever for startup milkmen.</p>
            </div>
            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                <div class="text-[10px] uppercase font-black text-slate-400">Purchased Blocks</div>
                <div class="text-xl font-black text-slate-900 mt-1"><?= $quota['purchased_blocks'] ?> Blocks</div>
                <p class="text-slate-500 mt-0.5">+<?= $quota['purchased_blocks'] * $quota['unit_size'] ?> customer capacity slots.</p>
            </div>
            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                <div class="text-[10px] uppercase font-black text-slate-400">Block Pricing</div>
                <div class="text-xl font-black text-blue-700 mt-1"><?= formatMoney($quota['unit_price'], $quota['currency_symbol']) ?></div>
                <p class="text-slate-500 mt-0.5">per <?= $quota['unit_size'] ?> active customers.</p>
            </div>
        </div>

        <!-- Offline Payment Upgrade Instructions (Approach 1) -->
        <div class="pt-6 space-y-6">
            <div>
                <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
                    <span>⚡ Purchase Additional Customer Capacity</span>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Follow the two simple steps below to transfer payment and submit your transaction ID (UTR) for instant verification.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                <!-- STEP 1: PAYMENT DETAILS & QR -->
                <div class="bg-gradient-to-br from-slate-900 to-slate-950 text-white p-6 rounded-2xl border border-slate-800 space-y-4 shadow-sm">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                        <span class="text-[11px] font-black uppercase tracking-wider text-purple-400">Step 1: Transfer Payment</span>
                        <span class="text-xs text-slate-400">UPI / IMPS / NEFT</span>
                    </div>

                    <!-- UPI Details -->
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Official Platform UPI ID</label>
                        <div class="flex items-center justify-between bg-slate-800/80 px-3.5 py-2.5 rounded-xl border border-slate-700">
                            <span class="font-mono text-sm font-black text-emerald-400" id="upi-id-text"><?= e($bankDetails['upi_id'] ?? 'kaustubh51-3@okicici') ?></span>
                            <button type="button" onclick="copyToClipboard('upi-id-text', this)" class="text-[11px] font-bold px-2 py-1 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition">
                                Copy
                            </button>
                        </div>
                    </div>

                    <!-- UPI QR Code Visual -->
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

                    <!-- Direct Bank Transfer Details -->
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

                <!-- STEP 2: SUBMIT TRANSACTION UTR FORM -->
                <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200 space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-200">
                        <span class="text-[11px] font-black uppercase tracking-wider text-blue-700">Step 2: Submit Payment Details</span>
                        <span class="text-xs text-slate-500">UTR Verification</span>
                    </div>

                    <form method="POST" action="<?= url('/subscription/request-upgrade') ?>" class="space-y-4">
                        <?= \App\Core\Auth::csrfField() ?>

                        <!-- Block Selection -->
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-700 mb-2">
                                Select Additional Capacity Blocks
                            </label>
                            <div class="grid grid-cols-3 gap-2">
                                <label class="p-3 rounded-xl border-2 border-slate-200 bg-white hover:border-blue-500 cursor-pointer transition text-center flex flex-col justify-between">
                                    <input type="radio" name="blocks" value="1" checked onchange="updatePayable(1, <?= (float)$quota['unit_price'] ?>, <?= (int)$quota['unit_size'] ?>)" class="mx-auto text-blue-600 mb-1">
                                    <div class="font-black text-slate-900 text-xs">+<?= $quota['unit_size'] ?></div>
                                    <div class="text-[10px] text-slate-500">1 Block</div>
                                    <div class="font-black text-xs text-blue-700 mt-1"><?= formatMoney($quota['unit_price'] * 1, $quota['currency_symbol']) ?></div>
                                </label>

                                <label class="p-3 rounded-xl border-2 border-slate-200 bg-white hover:border-blue-500 cursor-pointer transition text-center flex flex-col justify-between">
                                    <input type="radio" name="blocks" value="2" onchange="updatePayable(2, <?= (float)$quota['unit_price'] ?>, <?= (int)$quota['unit_size'] ?>)" class="mx-auto text-blue-600 mb-1">
                                    <div class="font-black text-slate-900 text-xs">+<?= $quota['unit_size'] * 2 ?></div>
                                    <div class="text-[10px] text-slate-500">2 Blocks</div>
                                    <div class="font-black text-xs text-blue-700 mt-1"><?= formatMoney($quota['unit_price'] * 2, $quota['currency_symbol']) ?></div>
                                </label>

                                <label class="p-3 rounded-xl border-2 border-slate-200 bg-white hover:border-blue-500 cursor-pointer transition text-center flex flex-col justify-between">
                                    <input type="radio" name="blocks" value="5" onchange="updatePayable(5, <?= (float)$quota['unit_price'] ?>, <?= (int)$quota['unit_size'] ?>)" class="mx-auto text-blue-600 mb-1">
                                    <div class="font-black text-slate-900 text-xs">+<?= $quota['unit_size'] * 5 ?></div>
                                    <div class="text-[10px] text-slate-500">5 Blocks</div>
                                    <div class="font-black text-xs text-blue-700 mt-1"><?= formatMoney($quota['unit_price'] * 5, $quota['currency_symbol']) ?></div>
                                </label>
                            </div>
                        </div>

                        <!-- Amount Display Banner -->
                        <div class="p-3 rounded-xl bg-blue-50 border border-blue-200 flex items-center justify-between text-xs">
                            <span class="font-bold text-slate-700">Total Payable Amount:</span>
                            <span class="text-base font-black text-blue-800" id="display-amount">
                                <?= formatMoney($quota['unit_price'] * 1, $quota['currency_symbol']) ?>
                            </span>
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

                        <button type="submit" class="tap-target w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow-md transition flex items-center justify-center gap-2">
                            <span>⚡ Submit Payment for Verification</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Upgrade Requests History -->
    <?php if (!empty($upgradeRequests)): ?>
    <div class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm space-y-4">
        <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
            <span>📋 Upgrade Request History</span>
        </h3>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-600">
                <thead class="bg-slate-50 text-slate-400 font-bold uppercase text-[10px] border-b border-slate-200">
                    <tr>
                        <th class="p-3">Date</th>
                        <th class="p-3">Plan Blocks</th>
                        <th class="p-3">Customer Slots</th>
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
                                +<?= $req['blocks_requested'] ?> Block(s)
                            </td>
                            <td class="p-3 text-emerald-700 font-bold">
                                +<?= $req['customer_slots'] ?> Slots
                            </td>
                            <td class="p-3 font-black text-slate-900">
                                <?= formatMoney((float)$req['amount'], $quota['currency_symbol']) ?>
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
</div>

<script>
function updatePayable(blocks, unitPrice, unitSize) {
    const total = (blocks * unitPrice).toFixed(2);
    const amountElem = document.getElementById('display-amount');
    if (amountElem) {
        amountElem.innerText = '₹' + total;
    }
}

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
