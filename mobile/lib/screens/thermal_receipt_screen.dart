import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';
import '../core/api/api_client.dart';
import '../core/constants/api_constants.dart';

class ThermalReceiptScreen extends StatefulWidget {
  final int invoiceId;

  const ThermalReceiptScreen({super.key, required this.invoiceId});

  @override
  State<ThermalReceiptScreen> createState() => _ThermalReceiptScreenState();
}

class _ThermalReceiptScreenState extends State<ThermalReceiptScreen> {
  final ApiClient _api = ApiClient();

  Map<String, dynamic>? _invoice;
  Map<String, dynamic>? _settings;
  bool _isLoading = true;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _loadInvoice();
  }

  Future<void> _loadInvoice() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    final res = await _api.get('${ApiConstants.invoices}/${widget.invoiceId}');

    if (res.success && res.data != null) {
      setState(() {
        _invoice = res.data['invoice'];
        _settings = res.data['settings'];
        _isLoading = false;
      });
    } else {
      setState(() {
        _errorMessage = res.error ?? 'Failed to load receipt details';
        _isLoading = false;
      });
    }
  }

  String _generateReceiptText() {
    final inv = _invoice ?? {};
    final dairyName = _settings?['business_name'] ?? 'DOODHBEE DAIRY';
    final dairyPhone = _settings?['phone'] ?? '';
    final custName = inv['customer_name'] ?? 'Customer';
    final custPhone = inv['customer_phone'] ?? '';
    final route = inv['route_name'] ?? '';
    final invId = widget.invoiceId;
    final month = inv['billing_month'] ?? '';
    final year = inv['billing_year'] ?? '';
    final totalUnits = (inv['total_units'] as num?)?.toDouble() ?? 0.0;
    final milkSubtotal = (inv['milk_subtotal'] as num?)?.toDouble() ?? 0.0;
    final chargesTotal = (inv['additional_charges_total'] as num?)?.toDouble() ?? 0.0;
    final taxAmount = (inv['tax_amount'] as num?)?.toDouble() ?? 0.0;
    final finalAmount = (inv['final_amount'] as num?)?.toDouble() ?? 0.0;
    final upiId = _settings?['upi_id'] ?? '';

    final buffer = StringBuffer();
    buffer.writeln('================================');
    buffer.writeln('        $dairyName        ');
    if (dairyPhone.toString().isNotEmpty) buffer.writeln('      Phone: $dairyPhone      ');
    buffer.writeln('================================');
    buffer.writeln('INVOICE #$invId');
    buffer.writeln('Period: $month/$year');
    buffer.writeln('Customer: $custName');
    if (custPhone.toString().isNotEmpty) buffer.writeln('Phone: $custPhone');
    if (route.toString().isNotEmpty) buffer.writeln('Route: $route');
    buffer.writeln('--------------------------------');
    buffer.writeln('Item                 Qty   Total');
    buffer.writeln('--------------------------------');
    buffer.writeln('Milk Deliveries   ${totalUnits.toStringAsFixed(1)}L  ₹${milkSubtotal.toStringAsFixed(2)}');
    if (chargesTotal > 0) {
      buffer.writeln('Additional Fees           ₹${chargesTotal.toStringAsFixed(2)}');
    }
    if (taxAmount > 0) {
      buffer.writeln('GST / Taxes               ₹${taxAmount.toStringAsFixed(2)}');
    }
    buffer.writeln('--------------------------------');
    buffer.writeln('TOTAL PAYABLE:       ₹${finalAmount.toStringAsFixed(2)}');
    buffer.writeln('--------------------------------');
    if (upiId.toString().isNotEmpty) {
      buffer.writeln('Pay via UPI: $upiId');
    }
    buffer.writeln('Thank you for your business!');
    buffer.writeln('================================');

    return buffer.toString();
  }

  Future<void> _shareTextReceipt() async {
    final text = _generateReceiptText();
    final encoded = Uri.encodeComponent(text);
    final phone = _invoice?['customer_phone'] ?? '';
    final cleanPhone = phone.toString().replaceAll(RegExp(r'\D'), '');
    final url = cleanPhone.isNotEmpty ? 'https://wa.me/91$cleanPhone?text=$encoded' : 'https://wa.me/?text=$encoded';

    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } else {
      Clipboard.setData(ClipboardData(text: text));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Receipt copied to clipboard!')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Thermal Slip #${widget.invoiceId}'),
        actions: [
          IconButton(
            icon: const Icon(Icons.copy),
            tooltip: 'Copy Slip Text',
            onPressed: () {
              Clipboard.setData(ClipboardData(text: _generateReceiptText()));
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Receipt text copied to clipboard!')),
              );
            },
          ),
          IconButton(
            icon: const Icon(Icons.share),
            tooltip: 'Share Receipt',
            onPressed: _shareTextReceipt,
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _errorMessage != null
              ? Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(_errorMessage!, style: const TextStyle(color: Colors.red)),
                      const SizedBox(height: 12),
                      ElevatedButton(onPressed: _loadInvoice, child: const Text('Retry')),
                    ],
                  ),
                )
              : SingleChildScrollView(
                  padding: const EdgeInsets.all(16),
                  child: Center(
                    child: Container(
                      width: double.infinity,
                      constraints: const BoxConstraints(maxWidth: 380),
                      padding: const EdgeInsets.all(20),
                      decoration: BoxDecoration(
                        color: const Color(0xFFFFFBEB),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: const Color(0xFFFDE68A), width: 1.5),
                        boxShadow: const [
                          BoxShadow(
                            color: Color(0x0D000000),
                            blurRadius: 8,
                            offset: Offset(0, 4),
                          ),
                        ],
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Text(
                            _generateReceiptText(),
                            style: const TextStyle(
                              fontFamily: 'monospace',
                              fontSize: 13,
                              height: 1.4,
                              color: Color(0xFF1E293B),
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          const SizedBox(height: 20),
                          Row(
                            children: [
                              Expanded(
                                child: OutlinedButton.icon(
                                  onPressed: () {
                                    Clipboard.setData(ClipboardData(text: _generateReceiptText()));
                                    ScaffoldMessenger.of(context).showSnackBar(
                                      const SnackBar(content: Text('Copied for printer!')),
                                    );
                                  },
                                  icon: const Icon(Icons.print, size: 18),
                                  label: const Text('Copy POS Text'),
                                ),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: ElevatedButton.icon(
                                  onPressed: _shareTextReceipt,
                                  icon: const Icon(Icons.send, size: 18),
                                  label: const Text('WhatsApp Slip'),
                                  style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF25D366)),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
    );
  }
}
