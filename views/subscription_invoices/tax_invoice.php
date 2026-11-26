<?php
/** @var array $invoice */
/** @var string $companyAddress */
/** @var string $supportEmail */
/** @var string $supportPhone */
$isPdf = !empty($isPdf);
$halfGstPct = round(((float)($invoice['gst_percentage'] ?? 18.0)) / 2, 2);
$gstPct = (float)($invoice['gst_percentage'] ?? 18.0);
$sellerName = (string)($invoice['seller_name'] ?? 'Shri Ram Open Source Technologies');
$sellerGstin = (string)($invoice['seller_gstin'] ?? '27AARPE1865G1ZJ');
$sacCode = (string)($invoice['sac_code'] ?? '998314');
?>
<?php if ($isPdf): ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($invoice['invoice_number']) ?> - <?= e($sellerName) ?></title>
<?php endif; ?>

<style>
    @page {
        margin: 10mm 12mm;
        size: A4 portrait;
    }
    * {
        box-sizing: border-box;
    }
    body {
        margin: 0;
        padding: 0;
        background: <?= $isPdf ? '#ffffff' : '#f1f5f9' ?>;
        font-family: 'DejaVu Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        color: #0f172a;
        font-size: 11px;
        line-height: 1.45;
    }
    .sub-inv-card {
        width: 100%;
        max-width: 760px;
        margin: 0 auto;
        background: #ffffff;
        <?= $isPdf ? '' : 'padding: 34px 38px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);' ?>
    }
    .header-box {
        border-bottom: 2px solid #0f172a;
        padding-bottom: 12px;
        margin-bottom: 16px;
    }
    .badge {
        display: inline-block;
        background: #0f172a;
        color: #ffffff;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.6px;
        text-transform: uppercase;
        padding: 4px 10px;
        border-radius: 4px;
    }
    .paid-pill {
        display: inline-block;
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #6ee7b7;
        font-size: 9px;
        font-weight: 800;
        text-transform: uppercase;
        padding: 2px 8px;
        border-radius: 999px;
    }
    .grid-table {
        width: 100%;
        border-collapse: collapse;
    }
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 16px;
        margin-bottom: 16px;
    }
    .items-table th {
        background: #0f172a;
        color: #ffffff;
        text-align: left;
        padding: 9px 10px;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .items-table td {
        padding: 10px;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: top;
    }
    .summary-table {
        width: 320px;
        margin-left: auto;
        border-collapse: collapse;
    }
    .summary-table td {
        padding: 6px 10px;
        border-bottom: 1px solid #e2e8f0;
        font-size: 11px;
    }
    .summary-total td {
        background: #0f172a;
        color: #ffffff;
        font-weight: 800;
        font-size: 13px;
        border-bottom: none;
    }
    .footer-rights {
        margin-top: 26px;
        padding-top: 12px;
        border-top: 1px solid #cbd5e1;
        text-align: center;
        font-size: 9.5px;
        color: #64748b;
    }
    @media print {
        body { background: #ffffff !important; padding: 0 !important; }
        .sub-inv-card { border: none !important; box-shadow: none !important; padding: 0 !important; }
        .no-print { display: none !important; }
    }
</style>
<?php if ($isPdf): ?>
</head>
<body>
<?php endif; ?>

<?php if (!$isPdf): ?>
<div class="no-print" style="max-width: 760px; margin: 0 auto 14px; display: flex; justify-content: space-between; align-items: center;">
    <a href="<?= url('/subscription') ?>" style="padding: 8px 14px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; text-decoration: none; color: #334155; font-size: 12px; font-weight: 700;">← Back to Subscription</a>
    <div style="display: flex; gap: 8px;">
        <a href="<?= url('/subscription/invoices/' . (int)$invoice['id'] . '/pdf') ?>" target="_blank" style="padding: 8px 14px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; text-decoration: none; color: #0f172a; font-size: 12px; font-weight: 700;">📥 Download PDF</a>
        <button onclick="window.print()" style="padding: 8px 16px; background: #2563eb; border: none; border-radius: 8px; color: #ffffff; font-size: 12px; font-weight: 700; cursor: pointer;">🖨️ Print Tax Invoice</button>
    </div>
</div>
<?php endif; ?>

<div class="sub-inv-card">
    <div class="header-box">
        <table class="grid-table">
            <tr>
                <td style="vertical-align: top;">
                    <div style="font-size: 18px; font-weight: 800; text-transform: uppercase; color: #0f172a;">
                        <?= e($sellerName) ?>
                    </div>
                    <div style="font-size: 10px; color: #475569; margin-top: 3px;">
                        <strong>GSTIN:</strong> <?= e($sellerGstin) ?> &nbsp;•&nbsp; <strong>SAC:</strong> <?= e($sacCode) ?><br>
                        <?= e($companyAddress ?: 'Maharashtra, India') ?><br>
                        Email: <?= e($supportEmail ?: 'hello@doodhbee.com') ?>
                        <?php if (!empty($supportPhone)): ?> • Phone: <?= e($supportPhone) ?><?php endif; ?>
                    </div>
                </td>
                <td style="vertical-align: top; text-align: right;">
                    <span class="badge">Tax Invoice</span>
                    <div style="margin-top: 8px; font-size: 10.5px;">
                        <strong>Invoice No:</strong> <?= e($invoice['invoice_number']) ?><br>
                        <strong>Date:</strong> <?= date('d M Y', strtotime((string)$invoice['created_at'])) ?><br>
                        <span class="paid-pill">Paid</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="grid-table" style="margin-bottom: 12px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                <div style="font-size: 9px; font-weight: 800; text-transform: uppercase; color: #64748b; margin-bottom: 4px;">Billed To (Subscriber)</div>
                <div style="font-size: 13px; font-weight: 800; color: #0f172a;"><?= e($invoice['buyer_business_name']) ?></div>
                <div style="font-size: 10.5px; color: #475569;">
                    Attn: <?= e($invoice['buyer_owner_name']) ?><br>
                    Phone: <?= e($invoice['buyer_phone']) ?><br>
                    <?php if (!empty($invoice['buyer_email'])): ?>
                        Email: <?= e($invoice['buyer_email']) ?>
                    <?php endif; ?>
                </div>
            </td>
            <td style="width: 45%; vertical-align: top; text-align: right;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: left; font-size: 10px;">
                    <div style="font-size: 9px; font-weight: 800; text-transform: uppercase; color: #64748b; margin-bottom: 4px;">Payment & Subscription Info</div>
                    <div><strong>Payment Mode:</strong> <?= e(strtoupper((string)$invoice['payment_method'])) ?></div>
                    <?php if (!empty($invoice['utr_number'])): ?>
                        <div><strong>UTR / Ref:</strong> <span style="font-family: 'DejaVu Sans Mono', monospace;"><?= e($invoice['utr_number']) ?></span></div>
                    <?php endif; ?>
                    <div><strong>Validity:</strong> <?= date('d M Y', strtotime((string)$invoice['period_start'])) ?> to <?= date('d M Y', strtotime((string)$invoice['period_end'])) ?></div>
                </div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th style="width: 85px;">SAC</th>
                <th style="width: 95px;">Cycle</th>
                <th style="width: 115px; text-align: right;">Taxable Value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div style="font-weight: 800; font-size: 11.5px; color: #0f172a;">
                        DoodhBee SaaS — <?= e($invoice['plan_name']) ?> Plan (<?= e(ucfirst((string)$invoice['action_type'])) ?>)
                    </div>
                    <div style="font-size: 10px; color: #475569; margin-top: 2px;">
                        Up to <?= (int)$invoice['customer_limit'] ?> active customers • Service period: <?= date('d M Y', strtotime((string)$invoice['period_start'])) ?> to <?= date('d M Y', strtotime((string)$invoice['period_end'])) ?>
                    </div>
                </td>
                <td><?= e($sacCode) ?></td>
                <td><?= e(ucfirst((string)$invoice['billing_cycle'])) ?></td>
                <td style="text-align: right; font-weight: 700;">₹<?= number_format((float)$invoice['base_amount'], 2) ?></td>
            </tr>
        </tbody>
    </table>

    <table class="summary-table">
        <tr>
            <td>Taxable Base Amount</td>
            <td style="text-align: right; font-weight: 700;">₹<?= number_format((float)$invoice['base_amount'], 2) ?></td>
        </tr>
        <?php if ($gstPct > 0): ?>
        <tr>
            <td>CGST (<?= number_format($halfGstPct, 1) ?>%)</td>
            <td style="text-align: right;">₹<?= number_format((float)$invoice['cgst_amount'], 2) ?></td>
        </tr>
        <tr>
            <td>SGST (<?= number_format($halfGstPct, 1) ?>%)</td>
            <td style="text-align: right;">₹<?= number_format((float)$invoice['sgst_amount'], 2) ?></td>
        </tr>
        <tr>
            <td><strong>Total GST (<?= number_format($gstPct, 0) ?>% Exclusive)</strong></td>
            <td style="text-align: right; font-weight: 700;">₹<?= number_format((float)$invoice['gst_amount'], 2) ?></td>
        </tr>
        <?php endif; ?>
        <tr class="summary-total">
            <td>Total Paid</td>
            <td style="text-align: right;">₹<?= number_format((float)$invoice['total_amount'], 2) ?></td>
        </tr>
    </table>

    <div style="margin-top: 18px; font-size: 9.5px; color: #475569;">
        <strong>Note:</strong> This is a computer-generated GST Tax Invoice issued by <strong><?= e($sellerName) ?></strong> (GSTIN: <?= e($sellerGstin) ?>).
    </div>

    <div class="footer-rights">
        © <?= date('Y') ?> <strong><?= e($sellerName) ?></strong>. All rights reserved.
    </div>
</div>

<?php if ($isPdf): ?>
</body>
</html>
<?php endif; ?>
