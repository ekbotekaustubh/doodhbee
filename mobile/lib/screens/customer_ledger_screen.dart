import 'package:flutter/material.dart';
import '../core/api/api_client.dart';
import '../core/constants/api_constants.dart';
import '../models/ledger_entry.dart';
import 'invoice_detail_screen.dart';

class CustomerLedgerScreen extends StatefulWidget {
  final int customerId;
  final String customerName;

  const CustomerLedgerScreen({
    super.key,
    required this.customerId,
    required this.customerName,
  });

  @override
  State<CustomerLedgerScreen> createState() => _CustomerLedgerScreenState();
}

class _CustomerLedgerScreenState extends State<CustomerLedgerScreen> {
  final ApiClient _api = ApiClient();

  Map<String, dynamic>? _customer;
  double _currentBalanceDue = 0.0;
  List<LedgerEntry> _ledger = [];
  bool _isLoading = true;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _loadLedger();
  }

  double _toDouble(dynamic val) {
    if (val == null) return 0.0;
    if (val is num) return val.toDouble();
    return double.tryParse(val.toString()) ?? 0.0;
  }

  Future<void> _loadLedger() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    final res = await _api.get('${ApiConstants.customers}/${widget.customerId}/ledger');

    if (res.success && res.data != null) {
      final list = ((res.data['ledger'] ?? []) as List)
          .map((m) => LedgerEntry.fromJson(m))
          .toList();

      setState(() {
        _customer = res.data['customer'];
        _currentBalanceDue = _toDouble(res.data['current_balance_due']);
        _ledger = list;
        _isLoading = false;
      });
    } else {
      setState(() {
        _errorMessage = res.error ?? 'Failed to load ledger';
        _isLoading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('${widget.customerName} - Ledger'),
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
                      ElevatedButton(onPressed: _loadLedger, child: const Text('Retry')),
                    ],
                  ),
                )
              : Column(
                  children: [
                    // Top Summary Card
                    Container(
                      padding: const EdgeInsets.all(16),
                      margin: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: _currentBalanceDue > 0 ? const Color(0xFFFEF2F2) : const Color(0xFFF0FDF4),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(
                          color: _currentBalanceDue > 0 ? const Color(0xFFFECACA) : const Color(0xFFBBF7D0),
                        ),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                _customer?['name'] ?? widget.customerName,
                                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900),
                              ),
                              const SizedBox(height: 2),
                              Text(
                                '${_customer?['phone'] ?? ''} • ${_customer?['route_name'] ?? ''}',
                                style: const TextStyle(fontSize: 12, color: Colors.black54),
                              ),
                            ],
                          ),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              const Text('Balance Due', style: TextStyle(fontSize: 11, color: Colors.black54)),
                              Text(
                                '₹${_currentBalanceDue.toStringAsFixed(2)}',
                                style: TextStyle(
                                  fontSize: 20,
                                  fontWeight: FontWeight.w900,
                                  color: _currentBalanceDue > 0 ? const Color(0xFFDC2626) : const Color(0xFF16A34A),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),

                    // Ledger entries list
                    Expanded(
                      child: _ledger.isEmpty
                          ? const Center(
                              child: Text('No ledger transactions recorded yet.'),
                            )
                          : RefreshIndicator(
                              onRefresh: _loadLedger,
                              child: ListView.builder(
                                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                                itemCount: _ledger.length,
                                itemBuilder: (ctx, idx) {
                                  // Reverse to show most recent transaction on top
                                  final entry = _ledger[_ledger.length - 1 - idx];
                                  final isDebit = entry.isInvoice;

                                  return Card(
                                    margin: const EdgeInsets.symmetric(vertical: 4),
                                    child: InkWell(
                                      borderRadius: BorderRadius.circular(12),
                                      onTap: isDebit && entry.id > 0
                                          ? () async {
                                              await Navigator.push(
                                                context,
                                                MaterialPageRoute(
                                                  builder: (_) => InvoiceDetailScreen(invoiceId: entry.id),
                                                ),
                                              );
                                              _loadLedger();
                                            }
                                          : null,
                                      child: Padding(
                                        padding: const EdgeInsets.all(12),
                                        child: Row(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          CircleAvatar(
                                            backgroundColor: isDebit ? const Color(0xFFFEE2E2) : const Color(0xFFDCFCE7),
                                            foregroundColor: isDebit ? const Color(0xFFDC2626) : const Color(0xFF16A34A),
                                            child: Icon(
                                              isDebit ? Icons.arrow_outward : Icons.arrow_downward,
                                              size: 18,
                                            ),
                                          ),
                                          const SizedBox(width: 12),
                                          Expanded(
                                            child: Column(
                                              crossAxisAlignment: CrossAxisAlignment.start,
                                              children: [
                                                Text(
                                                  entry.description,
                                                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                                ),
                                                const SizedBox(height: 2),
                                                Text(
                                                  entry.date,
                                                  style: const TextStyle(fontSize: 11, color: Colors.grey),
                                                ),
                                              ],
                                            ),
                                          ),
                                          Column(
                                            crossAxisAlignment: CrossAxisAlignment.end,
                                            children: [
                                              Text(
                                                isDebit ? '+₹${entry.debit.toStringAsFixed(2)}' : '-₹${entry.credit.toStringAsFixed(2)}',
                                                style: TextStyle(
                                                  fontWeight: FontWeight.w900,
                                                  fontSize: 14,
                                                  color: isDebit ? const Color(0xFFDC2626) : const Color(0xFF16A34A),
                                                ),
                                              ),
                                              const SizedBox(height: 2),
                                              Text(
                                                'Bal: ₹${entry.balanceAfter.toStringAsFixed(2)}',
                                                style: const TextStyle(fontSize: 11, color: Colors.black54),
                                              ),
                                            ],
                                          ),
                                        ],
                                      ),
                                    ),
                                  ),
                                );
                                },
                              ),
                            ),
                    ),
                  ],
                ),
    );
  }
}
