import 'package:flutter/material.dart';
import '../core/api/api_client.dart';
import '../core/constants/api_constants.dart';
import '../models/additional_charge.dart';

class ChargesScreen extends StatefulWidget {
  const ChargesScreen({super.key});

  @override
  State<ChargesScreen> createState() => _ChargesScreenState();
}

class _ChargesScreenState extends State<ChargesScreen> {
  final ApiClient _api = ApiClient();
  List<AdditionalCharge> _charges = [];
  bool _isLoading = true;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _loadCharges();
  }

  Future<void> _loadCharges() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    final res = await _api.get('${ApiConstants.settings}/charges');

    if (res.success && res.data != null) {
      final list = (res.data as List).map((m) => AdditionalCharge.fromJson(m)).toList();
      setState(() {
        _charges = list;
        _isLoading = false;
      });
    } else {
      setState(() {
        _errorMessage = res.error ?? 'Failed to load additional charges';
        _isLoading = false;
      });
    }
  }

  Future<void> _toggleCharge(AdditionalCharge charge) async {
    final res = await _api.post('${ApiConstants.settings}/charges/${charge.id}/toggle');
    if (res.success) {
      _loadCharges();
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res.error ?? 'Failed to update charge status')),
        );
      }
    }
  }

  Future<void> _deleteCharge(AdditionalCharge charge) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete Charge'),
        content: Text('Are you sure you want to remove "${charge.chargeName}"?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
            child: const Text('Delete'),
          ),
        ],
      ),
    );

    if (confirmed == true) {
      final res = await _api.delete('${ApiConstants.settings}/charges/${charge.id}');
      if (res.success) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Charge deleted successfully')),
          );
        }
        _loadCharges();
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(res.error ?? 'Failed to delete charge')),
          );
        }
      }
    }
  }

  void _showAddChargeDialog() {
    final nameCtrl = TextEditingController();
    final amountCtrl = TextEditingController();
    String chargeType = 'flat_monthly';
    bool isTaxable = false;
    String? dialogError;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Add Additional Charge'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (dialogError != null) ...[
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFEE2E2),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      dialogError!,
                      style: const TextStyle(color: Color(0xFF991B1B), fontSize: 12),
                    ),
                  ),
                  const SizedBox(height: 10),
                ],
                TextField(
                  controller: nameCtrl,
                  decoration: const InputDecoration(
                    labelText: 'Charge Name *',
                    hintText: 'e.g. Monthly Delivery Fee, Bottle Sanitization',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: chargeType,
                  decoration: const InputDecoration(
                    labelText: 'Charge Type *',
                    border: OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(value: 'flat_monthly', child: Text('Flat Monthly Fee')),
                    DropdownMenuItem(value: 'per_liter', child: Text('Per Liter')),
                    DropdownMenuItem(value: 'per_delivery_day', child: Text('Per Delivery Day')),
                  ],
                  onChanged: (val) => setDialogState(() => chargeType = val ?? 'flat_monthly'),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: amountCtrl,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(
                    labelText: 'Amount (₹) *',
                    hintText: '50.00',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 8),
                CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Apply GST / Tax on this charge', style: TextStyle(fontSize: 13)),
                  value: isTaxable,
                  onChanged: (val) => setDialogState(() => isTaxable = val ?? false),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
            ElevatedButton(
              onPressed: () async {
                final name = nameCtrl.text.trim();
                final amount = double.tryParse(amountCtrl.text) ?? 0.0;

                if (name.isEmpty) {
                  setDialogState(() => dialogError = 'Charge name is required.');
                  return;
                }
                if (amount <= 0) {
                  setDialogState(() => dialogError = 'Amount must be greater than 0.');
                  return;
                }

                final res = await _api.post('${ApiConstants.settings}/charges', data: {
                  'charge_name': name,
                  'charge_type': chargeType,
                  'amount': amount,
                  'is_taxable': isTaxable ? 1 : 0,
                });

                if (res.success) {
                  if (ctx.mounted) Navigator.pop(ctx);
                  _loadCharges();
                } else {
                  setDialogState(() => dialogError = res.error ?? 'Failed to add charge');
                }
              },
              child: const Text('Add Charge'),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('🏷️ Surcharges & Fees'),
        actions: [
          IconButton(
            icon: const Icon(Icons.add),
            tooltip: 'Add Charge',
            onPressed: _showAddChargeDialog,
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
                      ElevatedButton(onPressed: _loadCharges, child: const Text('Retry')),
                    ],
                  ),
                )
              : _charges.isEmpty
                  ? Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const Icon(Icons.local_offer_outlined, size: 48, color: Colors.grey),
                          const SizedBox(height: 12),
                          const Text('No additional charges configured.'),
                          const SizedBox(height: 6),
                          const Text(
                            'Add monthly delivery fees or packaging surcharges to billing.',
                            style: TextStyle(fontSize: 12, color: Colors.grey),
                            textAlign: TextAlign.center,
                          ),
                          const SizedBox(height: 12),
                          ElevatedButton.icon(
                            onPressed: _showAddChargeDialog,
                            icon: const Icon(Icons.add),
                            label: const Text('Add First Charge'),
                          ),
                        ],
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _loadCharges,
                      child: ListView.builder(
                        padding: const EdgeInsets.all(12),
                        itemCount: _charges.length,
                        itemBuilder: (ctx, idx) {
                          final c = _charges[idx];
                          return Card(
                            margin: const EdgeInsets.symmetric(vertical: 6),
                            child: ListTile(
                              leading: CircleAvatar(
                                backgroundColor: c.isActive ? const Color(0xFFFEF3C7) : const Color(0xFFF1F5F9),
                                foregroundColor: c.isActive ? const Color(0xFFD97706) : Colors.grey,
                                child: const Icon(Icons.receipt_outlined, size: 20),
                              ),
                              title: Row(
                                children: [
                                  Expanded(
                                    child: Text(
                                      c.chargeName,
                                      style: TextStyle(
                                        fontWeight: FontWeight.bold,
                                        decoration: c.isActive ? null : TextDecoration.lineThrough,
                                        color: c.isActive ? null : Colors.grey,
                                      ),
                                    ),
                                  ),
                                  if (c.isTaxable)
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                      margin: const EdgeInsets.only(left: 6),
                                      decoration: BoxDecoration(
                                        color: const Color(0xFFE0E7FF),
                                        borderRadius: BorderRadius.circular(8),
                                      ),
                                      child: const Text(
                                        'GST Taxable',
                                        style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Color(0xFF4338CA)),
                                      ),
                                    ),
                                ],
                              ),
                              subtitle: Padding(
                                padding: const EdgeInsets.only(top: 4),
                                child: Text(
                                  '₹${c.amount.toStringAsFixed(2)} • ${c.formattedChargeType}',
                                  style: const TextStyle(fontWeight: FontWeight.w600, color: Color(0xFF0284C7)),
                                ),
                              ),
                              trailing: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Switch(
                                    value: c.isActive,
                                    onChanged: (_) => _toggleCharge(c),
                                  ),
                                  IconButton(
                                    icon: const Icon(Icons.delete_outline, color: Colors.red),
                                    tooltip: 'Delete Charge',
                                    onPressed: () => _deleteCharge(c),
                                  ),
                                ],
                              ),
                            ),
                          );
                        },
                      ),
                    ),
    );
  }
}
