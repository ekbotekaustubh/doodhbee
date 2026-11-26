<div class="space-y-6 max-w-4xl mx-auto">
    <!-- Header -->
    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl md:text-2xl font-black text-slate-900 flex items-center gap-2">
                <span>⚙️ Business Settings, Taxes & Charges</span>
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Configure your dairy branding, tax rates, UPI payment QR, and custom delivery surcharges.
            </p>
        </div>

        <a href="<?= url('/subscription') ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs font-bold hover:bg-amber-100 transition">
            <span>⚡ Plan & Quota (<?= $quota['active_customers'] ?>/<?= $quota['max_allowed'] ?> Slots)</span>
        </a>
    </div>

    <!-- Section 1: Business Profile & Branding -->
    <div class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm">
        <h2 class="text-lg font-black text-slate-900 mb-1 flex items-center gap-2">
            <span>🏪 Dairy Profile & Contact Details</span>
        </h2>
        <p class="text-xs text-slate-500 mb-6">Printed at the top of your bills and run-sheets.</p>

        <form method="POST" action="<?= url('/settings/profile') ?>" class="space-y-4">
            <?= \App\Core\Auth::csrfField() ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-black uppercase text-slate-700 mb-1">Dairy / Business Name *</label>
                    <input type="text" name="business_name" required value="<?= e($tenant['business_name'] ?? '') ?>" class="w-full text-sm font-bold px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-black uppercase text-slate-700 mb-1">Owner Name *</label>
                    <input type="text" name="owner_name" required value="<?= e($tenant['owner_name'] ?? '') ?>" class="w-full text-sm font-semibold px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-black uppercase text-slate-700 mb-1">Contact Phone *</label>
                    <input type="tel" name="phone" required value="<?= e($tenant['phone'] ?? '') ?>" class="w-full text-sm font-semibold px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-black uppercase text-slate-700 mb-1">Email Address</label>
                    <input type="email" name="email" value="<?= e($tenant['email'] ?? '') ?>" class="w-full text-sm font-semibold px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            <div class="text-right pt-2">
                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow transition">
                    Save Profile
                </button>
            </div>
        </form>
    </div>

    <!-- Section 2: Tax Configuration & UPI Payment Info -->
    <div class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm">
        <h2 class="text-lg font-black text-slate-900 mb-1 flex items-center gap-2">
            <span>🏷️ Tax Configuration & UPI Payment Info</span>
        </h2>
        <p class="text-xs text-slate-500 mb-6">Manage GST / VAT calculation rules, GSTIN number, and your UPI payment QR code.</p>

        <form method="POST" action="<?= url('/settings/taxes') ?>" enctype="multipart/form-data" class="space-y-4">
            <?= \App\Core\Auth::csrfField() ?>

            <!-- Tax Enable Toggle -->
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 flex items-center justify-between">
                <div>
                    <div class="text-sm font-black text-slate-900">Enable Tax on Milk Invoices</div>
                    <div class="text-xs text-slate-500">Apply GST/VAT percentage to subtotal and taxable surcharges.</div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="tax_enabled" value="1" <?= !empty($settings['tax_enabled']) ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-black uppercase text-slate-700 mb-1">Tax Label</label>
                    <input type="text" name="tax_label" value="<?= e($settings['tax_label'] ?? 'GST') ?>" placeholder="e.g. GST, VAT" class="w-full text-sm font-bold px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-black uppercase text-slate-700 mb-1">Tax Rate (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="tax_percentage" value="<?= e($settings['tax_percentage'] ?? '5.00') ?>" class="w-full text-sm font-bold px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-black uppercase text-slate-700 mb-1">Tax / GSTIN Number</label>
                    <input type="text" name="tax_number" value="<?= e($settings['tax_number'] ?? '') ?>" placeholder="e.g. 24AAAAA0000A1Z5" class="w-full text-sm font-mono px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            <!-- UPI ID & QR Code Upload -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                <div>
                    <label class="block text-xs font-black uppercase text-slate-700 mb-1">UPI ID for Direct Bill Payments</label>
                    <input type="text" name="upi_id" value="<?= e($settings['upi_id'] ?? '') ?>" placeholder="e.g. yourname@okaxis" class="w-full text-sm font-mono px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                    <p class="text-[11px] text-slate-400 mt-1">If set, a dynamic UPI payment QR code is automatically generated on all printed bills.</p>
                </div>
                <div>
                    <label class="block text-xs font-black uppercase text-slate-700 mb-1">Or Upload Custom UPI QR Code Image</label>
                    <input type="file" name="upi_qr_file" accept="image/*" class="w-full text-xs file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border border-slate-300 rounded-xl py-1.5 px-2">
                    <?php if (!empty($settings['upi_qr_path'])): ?>
                        <div class="text-[11px] text-emerald-600 font-bold mt-1">✓ Custom QR code uploaded</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Invoice Footer Note -->
            <div>
                <label class="block text-xs font-black uppercase text-slate-700 mb-1">Invoice Footer Note / Payment Terms</label>
                <textarea name="invoice_footer_note" rows="2" placeholder="e.g. Pure milk delivered daily. Please pay dues before the 5th." class="w-full text-xs px-3.5 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500"><?= e($settings['invoice_footer_note'] ?? '') ?></textarea>
            </div>

            <div class="text-right pt-2">
                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow transition">
                    Save Tax & UPI Settings
                </button>
            </div>
        </form>
    </div>

    <!-- Section 2a: WhatsApp Gateway & Meta Cloud API Configuration -->
    <div class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm">
        <h2 class="text-lg font-black text-slate-900 mb-1 flex items-center gap-2">
            <span>📱 WhatsApp Gateway & Document Delivery</span>
        </h2>
        <p class="text-xs text-slate-500 mb-6">
            Configure how customer invoices are delivered via WhatsApp. Use Meta WhatsApp Cloud API to send bills directly as PDF document attachments with captions.
        </p>

        <form method="POST" action="<?= url('/settings/whatsapp') ?>" class="space-y-4" x-data="{ provider: '<?= e($settings['whatsapp_provider'] ?? 'browser') ?>' }">
            <?= \App\Core\Auth::csrfField() ?>

            <!-- Provider Selection -->
            <div>
                <label class="block text-xs font-black uppercase text-slate-700 mb-2">WhatsApp Sending Method *</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="p-4 rounded-2xl border cursor-pointer transition flex items-start gap-3" :class="provider === 'meta_cloud' ? 'border-emerald-500 bg-emerald-50/50 ring-2 ring-emerald-500/20' : 'border-slate-200 hover:bg-slate-50'">
                        <input type="radio" name="whatsapp_provider" value="meta_cloud" x-model="provider" class="mt-1 text-emerald-600 focus:ring-emerald-500">
                        <div>
                            <div class="text-sm font-black text-slate-900 flex items-center gap-1.5">
                                <span>📄 Meta Cloud API (Graph API)</span>
                                <span class="text-[10px] uppercase font-black px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800">PDF Attachment</span>
                            </div>
                            <div class="text-xs text-slate-500 mt-1">
                                Automatically sends the full invoice PDF as a document attachment directly to customer's WhatsApp chat with instant delivery.
                            </div>
                        </div>
                    </label>

                    <label class="p-4 rounded-2xl border cursor-pointer transition flex items-start gap-3" :class="provider === 'browser' ? 'border-blue-500 bg-blue-50/50 ring-2 ring-blue-500/20' : 'border-slate-200 hover:bg-slate-50'">
                        <input type="radio" name="whatsapp_provider" value="browser" x-model="provider" class="mt-1 text-blue-600 focus:ring-blue-500">
                        <div>
                            <div class="text-sm font-black text-slate-900 flex items-center gap-1.5">
                                <span>💬 Browser Click-to-Chat / Manual</span>
                            </div>
                            <div class="text-xs text-slate-500 mt-1">
                                Opens WhatsApp Web / App with pre-filled message text and a secure one-tap PDF download link. No API keys required.
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Meta Cloud API Fields (shown when meta_cloud selected) -->
            <div x-show="provider === 'meta_cloud'" x-cloak class="space-y-4 pt-2 border-t border-slate-100">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-black uppercase text-slate-700 mb-1">WhatsApp Phone Number ID *</label>
                        <input type="text" name="whatsapp_phone_number_id" value="<?= e($settings['whatsapp_phone_number_id'] ?? '') ?>" placeholder="e.g. 104928374928374" class="w-full text-sm font-mono px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-emerald-500">
                        <p class="text-[11px] text-slate-400 mt-1">Found in your Meta App Dashboard under WhatsApp > API Setup.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-black uppercase text-slate-700 mb-1">Meta WhatsApp API Token (System User Token) *</label>
                        <input type="password" name="whatsapp_api_token" value="<?= e($settings['whatsapp_api_token'] ?? '') ?>" placeholder="EAAG..." class="w-full text-sm font-mono px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-emerald-500">
                        <p class="text-[11px] text-slate-400 mt-1">Permanent access token generated in Meta Business Manager.</p>
                    </div>
                </div>
            </div>

            <div class="text-right pt-2">
                <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black shadow transition">
                    Save WhatsApp Settings
                </button>
            </div>
        </form>
    </div>

    <!-- Section 2b: Dairy Product Catalog & Default Pricing (Approach A: Multi-Product Support) -->
    <div class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h2 class="text-lg font-black text-slate-900 mb-1 flex items-center gap-2">
                    <span>📦 Dairy Product Catalog & Default Pricing</span>
                </h2>
                <p class="text-xs text-slate-500">
                    Manage all products offered by your dairy (Cow Milk, Buffalo Milk, Paneer, Curd, Ghee). Add new items with independent units and pricing.
                </p>
            </div>
        </div>

        <!-- Add New Product Form -->
        <form method="POST" action="<?= url('/settings/products') ?>" class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
            <?= \App\Core\Auth::csrfField() ?>
            <div class="font-black text-xs text-slate-900 uppercase tracking-wider">+ Add New Product to Catalog</div>

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Product Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Fresh Malai Paneer, Curd, A2 Milk" class="w-full text-xs font-bold px-3 py-2 border border-slate-300 rounded-xl bg-white">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Measurement Unit *</label>
                    <select name="unit" class="w-full text-xs font-bold px-3 py-2 border border-slate-300 rounded-xl bg-white">
                        <option value="L">Liters (L)</option>
                        <option value="kg">Kilograms (kg)</option>
                        <option value="packet">Packet / Pouch</option>
                        <option value="pc">Piece / Unit</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Default Rate (₹/unit) *</label>
                    <input type="number" step="0.5" min="1" max="5000" name="default_rate" required placeholder="e.g. 65.00" class="w-full text-xs font-black px-3 py-2 border border-slate-300 rounded-xl bg-white">
                </div>
            </div>

            <div class="flex justify-end pt-1">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow transition">
                    + Add Product
                </button>
            </div>
        </form>

        <!-- Current Product Catalog List -->
        <div class="divide-y divide-slate-100 border border-slate-200 rounded-2xl overflow-hidden">
            <?php if (empty($products)): ?>
                <div class="p-6 text-center text-xs text-slate-400">No products configured yet. Default Cow & Buffalo milk will be generated automatically.</div>
            <?php else: ?>
                <?php foreach ($products as $pr): 
                    $pIcon = $pr['code'] === 'buffalo' ? '🐃' : ($pr['code'] === 'cow' ? '🐄' : ($pr['code'] === 'paneer' ? '🧀' : '🥛'));
                ?>
                    <div class="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50 transition <?= !$pr['is_active'] ? 'bg-slate-50/70 opacity-60' : '' ?>">
                        <div class="flex items-center gap-3">
                            <span class="text-2xl"><?= $pIcon ?></span>
                            <div>
                                <div class="font-black text-sm text-slate-900 flex items-center gap-2">
                                    <span><?= e($pr['name']) ?></span>
                                    <span class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                                        <?= e($pr['code']) ?>
                                    </span>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $pr['is_active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' ?>">
                                        <?= $pr['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </div>
                                <div class="text-xs text-slate-500 mt-0.5">
                                    Unit: <strong><?= e($pr['unit']) ?></strong> • Base Rate: <strong><?= formatMoney($pr['default_rate']) ?> / <?= e($pr['unit']) ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 self-end sm:self-center">
                            <form method="POST" action="<?= url("/settings/products/{$pr['id']}/toggle") ?>">
                                <?= \App\Core\Auth::csrfField() ?>
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-bold border <?= $pr['is_active'] ? 'bg-amber-50 text-amber-800 border-amber-200' : 'bg-emerald-50 text-emerald-800 border-emerald-200' ?>">
                                    <?= $pr['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>

                            <?php if (!in_array($pr['code'], ['cow', 'buffalo'], true)): ?>
                                <form method="POST" action="<?= url("/settings/products/{$pr['id']}/delete") ?>" onsubmit="return confirm('Remove product \'<?= e($pr['name']) ?>\' from catalog?');">
                                    <?= \App\Core\Auth::csrfField() ?>
                                    <button type="submit" class="p-1.5 rounded-lg text-rose-600 hover:bg-rose-50 text-xs font-bold">
                                        🗑️
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section 3: Custom Additional Charges / Surcharges (Module Specification) -->
    <div class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm space-y-6">
        <div>
            <h2 class="text-lg font-black text-slate-900 mb-1 flex items-center gap-2">
                <span>➕ Custom Delivery Charges & Surcharges</span>
            </h2>
            <p class="text-xs text-slate-500">
                Configure custom line items (e.g., Monthly Delivery Fee, Glass Bottle Maintenance, Sanitization).
                Surcharges can be calculated as: <strong>Flat Monthly</strong>, <strong>Per Liter</strong>, or <strong>Per Delivery Day</strong>.
            </p>
        </div>

        <!-- Add New Charge Form -->
        <form method="POST" action="<?= url('/settings/charges') ?>" class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
            <?= \App\Core\Auth::csrfField() ?>
            <div class="font-black text-xs text-slate-900 uppercase tracking-wider">Add New Surcharge</div>

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Charge Name *</label>
                    <input type="text" name="charge_name" required placeholder="e.g. Monthly Bottle Maintenance" class="w-full text-xs font-bold px-3 py-2 border border-slate-300 rounded-xl bg-white">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Calculation Type *</label>
                    <select name="charge_type" class="w-full text-xs font-bold px-3 py-2 border border-slate-300 rounded-xl bg-white">
                        <option value="flat_monthly">Flat / Month (₹)</option>
                        <option value="per_liter">Per Liter Delivered (₹/L)</option>
                        <option value="per_delivery_day">Per Delivery Day (₹/Day)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Amount (₹) *</label>
                    <input type="number" step="0.5" min="0" name="amount" required placeholder="50.00" class="w-full text-xs font-black px-3 py-2 border border-slate-300 rounded-xl bg-white">
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <div class="flex items-center gap-4 text-xs font-bold text-slate-700">
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" name="is_taxable" value="1" class="rounded text-blue-600">
                        <span>Subject to Tax (GST)</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" checked class="rounded text-blue-600">
                        <span>Active</span>
                    </label>
                </div>

                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow transition">
                    + Add Charge
                </button>
            </div>
        </form>

        <!-- Current Custom Charges Table -->
        <div class="divide-y divide-slate-100 border border-slate-200 rounded-2xl overflow-hidden">
            <?php if (empty($charges)): ?>
                <div class="p-6 text-center text-xs text-slate-400 italic">No custom surcharges configured yet.</div>
            <?php else: ?>
                <?php foreach ($charges as $ch): ?>
                    <div class="p-4 bg-white flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50 transition">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-black text-sm text-slate-900"><?= e($ch['charge_name']) ?></span>
                                <span class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase <?= $ch['is_active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' ?>">
                                    <?= $ch['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                                <?php if (!empty($ch['is_taxable'])): ?>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-purple-100 text-purple-800">
                                        Taxable
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-slate-500 mt-0.5">
                                Type: <strong><?= \App\Enums\ChargeType::from($ch['charge_type'])->label() ?></strong>
                                <span class="text-slate-400"> (<?= \App\Enums\ChargeType::from($ch['charge_type'])->description() ?>)</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 self-end sm:self-center">
                            <div class="text-right">
                                <div class="text-sm font-black text-slate-900"><?= formatMoney($ch['amount']) ?></div>
                                <div class="text-[10px] text-slate-400 uppercase font-bold"><?= \App\Enums\ChargeType::from($ch['charge_type'])->shortLabel() ?></div>
                            </div>

                            <form method="POST" action="<?= url("/settings/charges/{$ch['id']}/toggle") ?>">
                                <?= \App\Core\Auth::csrfField() ?>
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-bold border <?= $ch['is_active'] ? 'bg-amber-50 text-amber-800 border-amber-200' : 'bg-emerald-50 text-emerald-800 border-emerald-200' ?>">
                                    <?= $ch['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>

                            <form method="POST" action="<?= url("/settings/charges/{$ch['id']}/delete") ?>" onsubmit="return confirm('Delete this surcharge?');">
                                <?= \App\Core\Auth::csrfField() ?>
                                <button type="submit" class="p-1.5 rounded-lg text-rose-600 hover:bg-rose-50 text-xs font-bold">
                                    🗑️
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section 4: Delivery Routes & Distribution Zones -->
    <div class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-lg font-black text-slate-900 mb-1 flex items-center gap-2">
                    <span>🗺️ Delivery Routes & Distribution Zones</span>
                </h2>
                <p class="text-xs text-slate-500">
                    Group your customers into territorial routes for organized run-sheets and field milk drops.
                </p>
            </div>
        </div>

        <!-- Add Route Form -->
        <form method="POST" action="<?= url('/settings/routes') ?>" class="p-4 bg-slate-50 rounded-2xl border border-slate-200 mb-6">
            <?= \App\Core\Auth::csrfField() ?>
            <div class="text-xs font-black text-slate-700 uppercase tracking-wider mb-3">Add New Delivery Route</div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-1">
                    <input type="text" name="name" required placeholder="Route Name (e.g. Sector 4, North Zone)" class="w-full text-xs font-bold px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="sm:col-span-1">
                    <input type="text" name="description" placeholder="Description / Landmark (Optional)" class="w-full text-xs px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="sm:col-span-1">
                    <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow transition">
                        + Add Route
                    </button>
                </div>
            </div>
        </form>

        <!-- Routes List -->
        <div class="space-y-3">
            <?php if (empty($routes)): ?>
                <p class="text-xs text-slate-400 italic text-center py-4">No routes found. The default Main Route will be created automatically.</p>
            <?php else: ?>
                <?php foreach ($routes as $rt): ?>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 rounded-2xl border <?= $rt['is_active'] ? 'border-slate-200 bg-white' : 'border-slate-100 bg-slate-50 opacity-75' ?> gap-3">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-black text-slate-900"><?= e($rt['name']) ?></span>
                                <?php if (!empty($rt['is_default']) || $rt['name'] === 'Main Route'): ?>
                                    <span class="text-[10px] uppercase font-black px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 border border-blue-200">
                                        Default
                                    </span>
                                <?php endif; ?>
                                <?php if (!$rt['is_active']): ?>
                                    <span class="text-[10px] uppercase font-black px-2 py-0.5 rounded-full bg-slate-200 text-slate-600">
                                        Inactive
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-slate-500 mt-0.5">
                                <?php if (!empty($rt['description'])): ?>
                                    <span><?= e($rt['description']) ?> • </span>
                                <?php endif; ?>
                                <span class="font-semibold text-slate-700"><?= (int)($rt['customer_count'] ?? 0) ?> customer(s) assigned</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 self-end sm:self-center">
                            <form method="POST" action="<?= url("/settings/routes/{$rt['id']}/toggle") ?>">
                                <?= \App\Core\Auth::csrfField() ?>
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-bold border <?= $rt['is_active'] ? 'bg-amber-50 text-amber-800 border-amber-200' : 'bg-emerald-50 text-emerald-800 border-emerald-200' ?>">
                                    <?= $rt['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>

                            <?php if (empty($rt['is_default']) && $rt['name'] !== 'Main Route'): ?>
                                <form method="POST" action="<?= url("/settings/routes/{$rt['id']}/delete") ?>" onsubmit="return confirm('Delete this route?');">
                                    <?= \App\Core\Auth::csrfField() ?>
                                    <button type="submit" class="p-1.5 rounded-lg text-rose-600 hover:bg-rose-50 text-xs font-bold" title="Delete Route">
                                        🗑️
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section 5: Delivery Staff & Route Riders -->
    <div id="staff-section" class="bg-white rounded-3xl border border-slate-200 p-6 md:p-8 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-6">
            <div>
                <h2 class="text-lg font-black text-slate-900 flex items-center gap-2">
                    <span>🛵 Delivery Staff & Route Riders</span>
                </h2>
                <p class="text-xs text-slate-500">
                    Create login credentials for your delivery riders. Riders can only access the daily delivery run-sheet and their assigned route on mobile/web.
                </p>
            </div>
        </div>

        <!-- Add Staff Form -->
        <form method="POST" action="<?= url('/settings/staff') ?>" class="p-4 bg-slate-50 rounded-2xl border border-slate-200 mb-6">
            <?= \App\Core\Auth::csrfField() ?>
            <div class="text-xs font-black text-slate-700 uppercase tracking-wider mb-3">Add New Delivery Rider / Staff Member</div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Rider Full Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Raju Rider" class="w-full text-xs font-bold px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Phone Number (Login ID) *</label>
                    <input type="tel" name="phone" required placeholder="10-digit Mobile Number" class="w-full text-xs font-bold px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Login Password *</label>
                    <input type="password" name="password" required placeholder="Minimum 4 characters" class="w-full text-xs font-bold px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Assigned Route (Optional)</label>
                    <select name="assigned_route_id" class="w-full text-xs font-semibold px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 bg-white">
                        <option value="">-- All Routes / Unassigned --</option>
                        <?php foreach ($routes as $rt): ?>
                            <option value="<?= $rt['id'] ?>"><?= e($rt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Role</label>
                    <select name="role" class="w-full text-xs font-semibold px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 bg-white">
                        <option value="delivery" selected>🛵 Delivery Staff (Run-Sheet Only)</option>
                        <option value="admin">🛡️ Tenant Admin (Full Access)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Email (Optional)</label>
                    <input type="email" name="email" placeholder="rider@dairy.com" class="w-full text-xs px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            <div class="text-right">
                <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black shadow transition">
                    + Add Staff Member
                </button>
            </div>
        </form>

        <!-- Staff List -->
        <div class="space-y-3">
            <?php if (empty($staff)): ?>
                <p class="text-xs text-slate-400 italic text-center py-4">No staff or delivery riders registered yet. Add your first rider above!</p>
            <?php else: ?>
                <?php foreach ($staff as $st): ?>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 rounded-2xl border <?= $st['is_active'] ? 'border-slate-200 bg-white' : 'border-slate-100 bg-slate-50 opacity-75' ?> gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl <?= $st['role'] === 'delivery' ? 'bg-amber-100 text-amber-800' : 'bg-purple-100 text-purple-800' ?> flex items-center justify-center text-sm font-black">
                                <?= $st['role'] === 'delivery' ? '🛵' : '🛡️' ?>
                            </div>
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-black text-slate-900"><?= e($st['name']) ?></span>
                                    <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-full <?= $st['role'] === 'delivery' ? 'bg-amber-100 text-amber-800 border border-amber-200' : 'bg-purple-100 text-purple-800 border border-purple-200' ?>">
                                        <?= $st['role'] === 'delivery' ? 'Delivery Rider' : 'Admin' ?>
                                    </span>
                                    <?php if (!$st['is_active']): ?>
                                        <span class="text-[10px] uppercase font-black px-2 py-0.5 rounded-full bg-slate-200 text-slate-600">
                                            Inactive
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-slate-500 mt-0.5 flex items-center gap-2 flex-wrap">
                                    <span class="font-mono text-slate-700 font-bold">📞 <?= e($st['phone']) ?></span>
                                    <?php if (!empty($st['assigned_route_name'])): ?>
                                        <span>•</span>
                                        <span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 font-bold border border-blue-200 text-[11px]">
                                            🗺️ <?= e($st['assigned_route_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span>•</span>
                                        <span class="text-slate-400">All Routes</span>
                                    <?php endif; ?>
                                    <?php if (!empty($st['email'])): ?>
                                        <span>•</span>
                                        <span><?= e($st['email']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 self-end sm:self-center">
                            <form method="POST" action="<?= url("/settings/staff/{$st['id']}/toggle") ?>">
                                <?= \App\Core\Auth::csrfField() ?>
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-bold border <?= $st['is_active'] ? 'bg-amber-50 text-amber-800 border-amber-200 hover:bg-amber-100' : 'bg-emerald-50 text-emerald-800 border-emerald-200 hover:bg-emerald-100' ?>">
                                    <?= $st['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>

                            <form method="POST" action="<?= url("/settings/staff/{$st['id']}/delete") ?>" onsubmit="return confirm('Delete this staff member?');">
                                <?= \App\Core\Auth::csrfField() ?>
                                <button type="submit" class="p-1.5 rounded-lg text-rose-600 hover:bg-rose-50 text-xs font-bold" title="Delete Staff Member">
                                    🗑️
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
