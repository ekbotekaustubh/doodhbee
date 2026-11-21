<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-white">SaaS Platform Governance</h1>
            <p class="text-xs text-slate-400 mt-0.5">Global subscription tier rules, tenant monitoring & capacity management</p>
        </div>
        <?php if (isDemoMode()): ?>
        <div class="flex items-center gap-2">
            <a href="<?= url('/demo/tenant1') ?>" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-blue-400 text-xs font-bold rounded-xl border border-slate-700">
                🥛 Switch to Krishna Dairy Portal
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Platform KPI Counters -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-slate-900 p-5 rounded-2xl border border-slate-800 shadow-sm">
            <div class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Dairies (Tenants)</div>
            <div class="text-3xl font-black text-white mt-1"><?= $totalTenants ?></div>
            <div class="text-xs text-slate-400 mt-1">Multi-tenant data isolation active</div>
        </div>
        <div class="bg-slate-900 p-5 rounded-2xl border border-slate-800 shadow-sm">
            <div class="text-xs font-bold uppercase tracking-wider text-purple-400">Total Delivery Customers</div>
            <div class="text-3xl font-black text-purple-300 mt-1"><?= $totalActiveCustomers ?></div>
            <div class="text-xs text-slate-400 mt-1">Active delivery houses managed</div>
        </div>
        <div class="bg-slate-900 p-5 rounded-2xl border border-slate-800 shadow-sm">
            <div class="text-xs font-bold uppercase tracking-wider text-emerald-400">Platform Subscription Revenue</div>
            <div class="text-3xl font-black text-emerald-400 mt-1"><?= formatMoney($platformRevenue, $systemSettings['currency_symbol']) ?></div>
            <div class="text-xs text-slate-400 mt-1">From paid tier customer blocks</div>
        </div>
    </div>

    <!-- Offline Payment Upgrade Requests (Approach 1) -->
    <?php 
        $pendingRequests = array_values(array_filter($upgradeRequests ?? [], fn($r) => ($r['status'] ?? '') === 'pending'));
        $reviewedRequests = array_values(array_filter($upgradeRequests ?? [], fn($r) => ($r['status'] ?? '') !== 'pending'));
    ?>
    <div id="subscription-requests" class="bg-slate-900 rounded-3xl border <?= count($pendingRequests) > 0 ? 'border-amber-500/50 shadow-amber-950/20 shadow-lg' : 'border-slate-800' ?> p-6 md:p-8 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-800">
            <div>
                <h2 class="text-lg font-black text-white flex items-center gap-2">
                    <span>⚡ Tenant Subscription Upgrade Requests</span>
                    <?php if (count($pendingRequests) > 0): ?>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-black uppercase tracking-wider bg-amber-500/20 text-amber-300 border border-amber-500/40">
                            <?= count($pendingRequests) ?> Pending Verification
                        </span>
                    <?php endif; ?>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">
                    Reconcile offline payments (UPI / IMPS / Cash) using tenant-submitted Transaction UTR numbers.
                </p>
            </div>
        </div>

        <?php if (count($pendingRequests) > 0): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-950 text-slate-400 font-bold uppercase text-[10px] border-b border-slate-800">
                        <tr>
                            <th class="p-3">Tenant / Business</th>
                            <th class="p-3">Requested Plan</th>
                            <th class="p-3">Amount</th>
                            <th class="p-3">UTR / Payment Ref</th>
                            <th class="p-3">Submitted At</th>
                            <th class="p-3 text-right">Verification Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($pendingRequests as $pr): ?>
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="p-3">
                                    <div class="font-bold text-white"><?= e($pr['business_name'] ?? ('Tenant #' . $pr['tenant_id'])) ?></div>
                                    <div class="text-[10px] text-slate-400">
                                        <?= e($pr['owner_name'] ?? '') ?> • <?= e($pr['phone'] ?? '') ?>
                                    </div>
                                    <div class="text-[10px] text-slate-500">
                                        Current Limit: <?= $pr['current_max'] ?? '5' ?> slots (<?= $pr['current_blocks'] ?? 0 ?> blocks)
                                    </div>
                                </td>
                                <td class="p-3 font-bold">
                                    <span class="text-purple-300">+<?= $pr['blocks_requested'] ?> Block(s)</span>
                                    <span class="text-[11px] text-emerald-400 block">+<?= $pr['customer_slots'] ?> Slots</span>
                                </td>
                                <td class="p-3 font-black text-white whitespace-nowrap">
                                    <?= formatMoney((float)$pr['amount'], $systemSettings['currency_symbol']) ?>
                                    <span class="text-[10px] text-slate-400 uppercase font-semibold block"><?= e($pr['payment_method']) ?></span>
                                </td>
                                <td class="p-3 font-mono">
                                    <span class="px-2 py-1 rounded bg-slate-950 text-emerald-400 font-black border border-slate-700 text-xs select-all">
                                        <?= e($pr['utr_number']) ?>
                                    </span>
                                    <?php if (!empty($pr['notes'])): ?>
                                        <div class="text-[10px] text-slate-400 mt-1"><?= e($pr['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-slate-400 text-[11px] whitespace-nowrap">
                                    <?= formatDate($pr['created_at'], 'd M Y, h:i A') ?>
                                </td>
                                <td class="p-3 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-2">
                                        <!-- Approve Button Form -->
                                        <form method="POST" action="<?= url("/admin/subscription-requests/{$pr['id']}/approve") ?>" onsubmit="return confirm('Confirm UTR verification and immediately add +<?= $pr['blocks_requested'] ?> blocks to <?= e(addslashes($pr['business_name'] ?? 'tenant')) ?>?');">
                                            <?= \App\Core\Auth::csrfField() ?>
                                            <input type="hidden" name="admin_notes" value="Verified in bank statement">
                                            <button type="submit" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-black rounded-lg shadow transition flex items-center gap-1">
                                                <span>✓</span> Approve & Unlock
                                            </button>
                                        </form>

                                        <!-- Reject Button Form -->
                                        <form method="POST" action="<?= url("/admin/subscription-requests/{$pr['id']}/reject") ?>" onsubmit="var reason = prompt('Please enter rejection reason for tenant (e.g. UTR not found in bank statement):'); if(!reason) return false; this.reason.value = reason; return true;">
                                            <?= \App\Core\Auth::csrfField() ?>
                                            <input type="hidden" name="reason" value="Payment not found in bank statement">
                                            <button type="submit" class="px-2.5 py-1.5 bg-rose-950/80 hover:bg-rose-900 text-rose-300 border border-rose-800 text-[11px] font-bold rounded-lg transition">
                                                Reject
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-4 rounded-2xl bg-slate-950/60 border border-slate-800 text-slate-400 text-xs flex items-center justify-between">
                <span>✓ All tenant upgrade requests have been reviewed and reconciled. No pending items.</span>
            </div>
        <?php endif; ?>

        <!-- Historical Reviewed Requests toggle / list -->
        <?php if (count($reviewedRequests) > 0): ?>
            <details class="pt-2 text-xs text-slate-400">
                <summary class="cursor-pointer font-bold hover:text-white select-none">
                    View Reconciled / Past Upgrade History (<?= count($reviewedRequests) ?> records)
                </summary>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-400">
                        <thead class="bg-slate-950 text-slate-500 font-bold uppercase text-[9px] border-b border-slate-800">
                            <tr>
                                <th class="p-2">Date</th>
                                <th class="p-2">Tenant</th>
                                <th class="p-2">Blocks</th>
                                <th class="p-2">Amount</th>
                                <th class="p-2">UTR</th>
                                <th class="p-2">Status</th>
                                <th class="p-2">Admin Note</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800 text-[11px]">
                            <?php foreach ($reviewedRequests as $rr): ?>
                                <tr class="hover:bg-slate-800/30">
                                    <td class="p-2"><?= formatDate($rr['created_at'], 'd M Y') ?></td>
                                    <td class="p-2 font-bold text-white"><?= e($rr['business_name'] ?? ('#' . $rr['tenant_id'])) ?></td>
                                    <td class="p-2">+<?= $rr['blocks_requested'] ?> (<?= $rr['customer_slots'] ?> slots)</td>
                                    <td class="p-2"><?= formatMoney((float)$rr['amount'], $systemSettings['currency_symbol']) ?></td>
                                    <td class="p-2 font-mono"><?= e($rr['utr_number']) ?></td>
                                    <td class="p-2">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $rr['status'] === 'approved' ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-rose-950 text-rose-400 border border-rose-800' ?>">
                                            <?= e($rr['status']) ?>
                                        </span>
                                    </td>
                                    <td class="p-2 text-slate-400 italic"><?= e($rr['admin_notes'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </div>

    <!-- Global SaaS Platform Settings Card (Specification 3.A) -->
    <div class="bg-slate-900 rounded-3xl border border-slate-800 p-6 md:p-8 shadow-sm space-y-4">
        <div class="flex items-center justify-between pb-4 border-b border-slate-800">
            <div>
                <h2 class="text-lg font-black text-white flex items-center gap-2">
                    <span>⚙️ Global SaaS Subscription Tiering & Receiving Account Details</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">
                    Governs free customer limits, paid block pricing, and bank account/UPI details displayed to tenants.
                </p>
            </div>
        </div>

        <form method="POST" action="<?= url('/admin/settings') ?>" class="space-y-4">
            <?= \App\Core\Auth::csrfField() ?>

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <!-- Free Customer Limit -->
                <div>
                    <label class="block text-xs font-black uppercase text-slate-300 mb-1">
                        Free Customer Limit
                    </label>
                    <input type="number" min="1" max="1000" name="free_customer_limit" required value="<?= e($systemSettings['free_customer_limit']) ?>" class="w-full text-sm font-black px-3.5 py-2.5 bg-slate-950 border border-slate-700 text-white rounded-xl focus:ring-2 focus:ring-purple-500">
                    <p class="text-[10px] text-slate-500 mt-1">Default: 5 active customers</p>
                </div>

                <!-- Paid Tier Unit Size -->
                <div>
                    <label class="block text-xs font-black uppercase text-slate-300 mb-1">
                        Paid Tier Block Size
                    </label>
                    <input type="number" min="1" max="10000" name="paid_tier_unit_size" required value="<?= e($systemSettings['paid_tier_unit_size']) ?>" class="w-full text-sm font-black px-3.5 py-2.5 bg-slate-950 border border-slate-700 text-white rounded-xl focus:ring-2 focus:ring-purple-500">
                    <p class="text-[10px] text-slate-500 mt-1">Default: 100 customers per block</p>
                </div>

                <!-- Paid Tier Unit Price -->
                <div>
                    <label class="block text-xs font-black uppercase text-slate-300 mb-1">
                        Price Per Block (<?= e($systemSettings['currency_symbol']) ?>)
                    </label>
                    <input type="number" step="0.5" min="0" name="paid_tier_unit_price" required value="<?= e($systemSettings['paid_tier_unit_price']) ?>" class="w-full text-sm font-black px-3.5 py-2.5 bg-slate-950 border border-slate-700 text-white rounded-xl focus:ring-2 focus:ring-purple-500">
                    <p class="text-[10px] text-slate-500 mt-1">Default: ₹200.00</p>
                </div>

                <!-- Currency Symbol -->
                <div>
                    <label class="block text-xs font-black uppercase text-slate-300 mb-1">
                        Currency Symbol
                    </label>
                    <input type="text" name="currency_symbol" required value="<?= e($systemSettings['currency_symbol']) ?>" class="w-full text-sm font-black px-3.5 py-2.5 bg-slate-950 border border-slate-700 text-white rounded-xl focus:ring-2 focus:ring-purple-500">
                    <p class="text-[10px] text-slate-500 mt-1">Default: ₹</p>
                </div>
            </div>

            <!-- Offline Payment Details Displayed to Tenants -->
            <div class="pt-4 border-t border-slate-800">
                <h3 class="text-xs font-black uppercase text-purple-400 mb-3 tracking-wider">
                    💳 Offline Payment Receiving Details (Shown on Tenant Upgrade Page)
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-[11px] font-bold uppercase text-slate-300 mb-1">Platform UPI ID</label>
                        <input type="text" name="payment_upi_id" value="<?= e($systemSettings['payment_upi_id'] ?? 'kaustubh51-3@okicici') ?>" class="w-full text-xs font-mono font-bold px-3 py-2 bg-slate-950 border border-slate-700 text-white rounded-xl focus:ring-2 focus:ring-purple-500">
                        <p class="text-[10px] text-slate-500 mt-1">e.g. business@okhdfcbank</p>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold uppercase text-slate-300 mb-1">Beneficiary / Account Name</label>
                        <input type="text" name="payment_account_name" value="<?= e($systemSettings['payment_account_name'] ?? 'Shri Ram Open Source Technologies') ?>" class="w-full text-xs font-bold px-3 py-2 bg-slate-950 border border-slate-700 text-white rounded-xl focus:ring-2 focus:ring-purple-500">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold uppercase text-slate-300 mb-1">Bank Name</label>
                        <input type="text" name="payment_bank_name" value="<?= e($systemSettings['payment_bank_name'] ?? 'Saraswat Co-operative Bank Ltd.') ?>" class="w-full text-xs font-bold px-3 py-2 bg-slate-950 border border-slate-700 text-white rounded-xl focus:ring-2 focus:ring-purple-500">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold uppercase text-slate-300 mb-1">Bank Account Number</label>
                        <input type="text" name="payment_account_number" value="<?= e($systemSettings['payment_account_number'] ?? '610000000039273') ?>" class="w-full text-xs font-mono font-bold px-3 py-2 bg-slate-950 border border-slate-700 text-white rounded-xl focus:ring-2 focus:ring-purple-500">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold uppercase text-slate-300 mb-1">Bank IFSC Code</label>
                        <input type="text" name="payment_ifsc_code" value="<?= e($systemSettings['payment_ifsc_code'] ?? 'srcb0000370') ?>" class="w-full text-xs font-mono font-bold px-3 py-2 bg-slate-950 border border-slate-700 text-white rounded-xl focus:ring-2 focus:ring-purple-500">
                    </div>
                </div>
            </div>

            <div class="text-right pt-2">
                <button type="submit" class="px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-black shadow transition">
                    Save Global SaaS Settings
                </button>
            </div>
        </form>
    </div>

    <!-- Tenants Management Directory -->
    <div class="bg-slate-900 rounded-3xl border border-slate-800 p-6 md:p-8 shadow-sm space-y-4">
        <h2 class="text-lg font-black text-white flex items-center gap-2">
            <span>🥛 Registered Tenants (Dairies & Milkmen)</span>
        </h2>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-950 text-slate-400 font-bold uppercase text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="p-3">Tenant / Business</th>
                        <th class="p-3">Owner Contact</th>
                        <th class="p-3 text-center">Active Customers</th>
                        <th class="p-3 text-center">Quota Limit</th>
                        <th class="p-3 text-center">Plan Status</th>
                        <th class="p-3 text-center">Status</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php foreach ($tenants as $t): ?>
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="p-3">
                                <div class="font-bold text-white"><?= e($t['business_name']) ?></div>
                                <div class="text-[10px] text-slate-500">ID: #<?= $t['id'] ?> • Reg: <?= formatDate($t['created_at'], 'd M Y') ?></div>
                            </td>
                            <td class="p-3 font-mono">
                                <div class="text-slate-200"><?= e($t['owner_name']) ?></div>
                                <div class="text-slate-400"><?= e($t['phone']) ?></div>
                            </td>
                            <td class="p-3 text-center font-black text-white">
                                <?= $t['active_customers'] ?>
                            </td>
                            <td class="p-3 text-center font-bold">
                                <?= $t['max_customers_allowed'] ?? 5 ?>
                                <span class="text-[10px] text-slate-500 block">(<?= $t['purchased_blocks'] ?? 0 ?> blocks)</span>
                            </td>
                            <td class="p-3 text-center">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase <?= ($t['payment_status'] ?? 'free') === 'active' ? 'bg-emerald-900/60 text-emerald-300 border border-emerald-700' : 'bg-blue-900/60 text-blue-300 border border-blue-700' ?>">
                                    <?= e($t['payment_status'] ?? 'free') ?>
                                </span>
                            </td>
                            <td class="p-3 text-center">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase <?= $t['status'] === 'active' ? 'bg-emerald-950 text-emerald-400' : 'bg-rose-950 text-rose-400' ?>">
                                    <?= e($t['status']) ?>
                                </span>
                            </td>
                            <td class="p-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <!-- Suspend / Activate -->
                                    <form method="POST" action="<?= url("/admin/tenants/{$t['id']}/toggle-status") ?>">
                                        <?= \App\Core\Auth::csrfField() ?>
                                        <button type="submit" class="px-2.5 py-1 text-[11px] font-bold rounded-lg border <?= $t['status'] === 'active' ? 'border-rose-800 text-rose-400 hover:bg-rose-950' : 'border-emerald-800 text-emerald-400 hover:bg-emerald-950' ?>">
                                            <?= $t['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                                        </button>
                                    </form>

                                    <?php if (isDemoMode()): ?>
                                    <!-- Quick Switcher to this Tenant (Demo Only) -->
                                    <a href="<?= url("/demo/tenant{$t['id']}") ?>" class="px-2.5 py-1 text-[11px] font-bold rounded-lg bg-slate-800 text-blue-400 hover:bg-slate-700 border border-slate-700">
                                        Login As
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
