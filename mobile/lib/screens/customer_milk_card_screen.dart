import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../core/api/api_client.dart';
import '../core/constants/api_constants.dart';

class CustomerMilkCardScreen extends StatefulWidget {
  final int customerId;
  final String customerName;

  const CustomerMilkCardScreen({
    super.key,
    required this.customerId,
    required this.customerName,
  });

  @override
  State<CustomerMilkCardScreen> createState() => _CustomerMilkCardScreenState();
}

class _CustomerMilkCardScreenState extends State<CustomerMilkCardScreen> {
  final ApiClient _api = ApiClient();

  int _selectedYear = DateTime.now().year;
  int _selectedMonth = DateTime.now().month;

  bool _isLoading = false;
  bool _isSaving = false;

  List<Map<String, dynamic>> _days = [];
  Map<String, dynamic> _summary = {};

  @override
  void initState() {
    super.initState();
    _loadSheet();
  }

  String get _monthName {
    final d = DateTime(_selectedYear, _selectedMonth, 1);
    return DateFormat('MMMM yyyy').format(d);
  }

  void _prevMonth() {
    setState(() {
      if (_selectedMonth == 1) {
        _selectedMonth = 12;
        _selectedYear--;
      } else {
        _selectedMonth--;
      }
    });
    _loadSheet();
  }

  void _nextMonth() {
    setState(() {
      if (_selectedMonth == 12) {
        _selectedMonth = 1;
        _selectedYear++;
      } else {
        _selectedMonth++;
      }
    });
    _loadSheet();
  }

  Future<void> _loadSheet() async {
    setState(() => _isLoading = true);

    final res = await _api.get(
      ApiConstants.customerMonthDeliveries(widget.customerId),
      queryParameters: {
        'year': _selectedYear,
        'month': _selectedMonth,
      },
    );

    if (res.success && res.data != null) {
      final data = Map<String, dynamic>.from(res.data);
      final rawDays = (data['days'] as List? ?? []);

      final parsedDays = rawDays.map((d) {
        final dMap = Map<String, dynamic>.from(d as Map);
        final rawShifts = Map<String, dynamic>.from(dMap['shifts'] as Map? ?? {});

        final morning = Map<String, dynamic>.from(rawShifts['morning'] as Map? ?? {
          'status': 'delivered',
          'quantity': 1.0,
          'notes': '',
        });
        final evening = Map<String, dynamic>.from(rawShifts['evening'] as Map? ?? {
          'status': 'delivered',
          'quantity': 0.0,
          'notes': '',
        });

        // Ensure default fallback quantities are stored
        morning['quantity'] = (morning['quantity'] as num?)?.toDouble() ?? 1.0;
        evening['quantity'] = (evening['quantity'] as num?)?.toDouble() ?? 0.0;
        if (morning['status'] == 'pending') morning['status'] = 'delivered';
        if (evening['status'] == 'pending') evening['status'] = 'delivered';

        return {
          'day': dMap['day'],
          'date': dMap['date'],
          'day_of_week': dMap['day_of_week'],
          'is_on_leave': dMap['is_on_leave'] == true,
          'leave_reason': dMap['leave_reason'],
          'morning': morning,
          'evening': evening,
        };
      }).toList();

      setState(() {
        _days = parsedDays;
        _summary = Map<String, dynamic>.from(data['summary'] as Map? ?? {});
      });
      _recalculateSummary();
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res.error ?? 'Failed to load milk card.'),
            backgroundColor: Colors.red,
          ),
        );
      }
    }

    if (mounted) setState(() => _isLoading = false);
  }

  void _recalculateSummary() {
    double totalLitres = 0.0;
    double totalAmount = 0.0;
    int delShifts = 0;
    int skipShifts = 0;
    int leaveShifts = 0;

    for (final d in _days) {
      final m = d['morning'] as Map<String, dynamic>;
      final e = d['evening'] as Map<String, dynamic>;

      for (final sh in [m, e]) {
        final st = sh['status']?.toString() ?? 'delivered';
        if (st == 'delivered') {
          final q = (sh['quantity'] as num?)?.toDouble() ?? 0.0;
          totalLitres += q;
          totalAmount += q * 60.0; // standard fallback multiplier
          delShifts++;
        } else if (st == 'skipped') {
          skipShifts++;
        } else if (st == 'leave') {
          leaveShifts++;
        }
      }
    }

    setState(() {
      _summary['total_litres'] = totalLitres;
      _summary['total_amount'] = totalAmount;
      _summary['delivered_shifts'] = delShifts;
      _summary['skipped_shifts'] = skipShifts;
      _summary['leave_shifts'] = leaveShifts;
    });
  }

  Future<void> _saveSheet() async {
    setState(() => _isSaving = true);

    final List<Map<String, dynamic>> payloadDays = [];
    for (final d in _days) {
      final dateStr = d['date'].toString();
      final m = d['morning'] as Map<String, dynamic>;
      final e = d['evening'] as Map<String, dynamic>;

      payloadDays.add({
        'date': dateStr,
        'shift': 'morning',
        'status': m['status'],
        'quantity': m['status'] == 'delivered' ? m['quantity'] : 0.0,
        'notes': m['notes'] ?? '',
      });

      payloadDays.add({
        'date': dateStr,
        'shift': 'evening',
        'status': e['status'],
        'quantity': e['status'] == 'delivered' ? e['quantity'] : 0.0,
        'notes': e['notes'] ?? '',
      });
    }

    final res = await _api.post(
      ApiConstants.customerMonthDeliveries(widget.customerId),
      data: {
        'year': _selectedYear,
        'month': _selectedMonth,
        'days': payloadDays,
      },
    );

    if (mounted) {
      setState(() => _isSaving = false);
      if (res.success) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('✓ Monthly Milk Card saved successfully!'),
            backgroundColor: Color(0xFF059669),
          ),
        );
        _loadSheet();
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res.error ?? 'Failed to save milk card.'),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }

  void _markAllMonthDelivered() {
    for (final d in _days) {
      final m = d['morning'] as Map<String, dynamic>;
      final e = d['evening'] as Map<String, dynamic>;

      if (d['is_on_leave'] != true) {
        m['status'] = 'delivered';
        if ((m['quantity'] as num? ?? 0) <= 0) m['quantity'] = 1.0;

        if ((e['quantity'] as num? ?? 0) > 0) {
          e['status'] = 'delivered';
        }
      }
    }
    _recalculateSummary();
  }

  void _showAutoFillDialog() {
    String tempShift = 'both';
    bool tempOverwrite = false;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Text('⚡ Auto-Fill $_monthName'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Auto-fill deliveries for ${widget.customerName} for $_monthName using their recurring subscriptions.',
                style: const TextStyle(fontSize: 12, color: Colors.black54),
              ),
              const SizedBox(height: 16),
              DropdownButtonFormField<String>(
                initialValue: tempShift,
                decoration: const InputDecoration(labelText: 'Shift', border: OutlineInputBorder()),
                items: const [
                  DropdownMenuItem(value: 'both', child: Text('Both Shifts (Morning & Evening)')),
                  DropdownMenuItem(value: 'morning', child: Text('Morning Shift Only')),
                  DropdownMenuItem(value: 'evening', child: Text('Evening Shift Only')),
                ],
                onChanged: (v) {
                  if (v != null) setDialogState(() => tempShift = v);
                },
              ),
              const SizedBox(height: 12),
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Overwrite existing days', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                subtitle: const Text('Leave unchecked to preserve days already adjusted or skipped.', style: TextStyle(fontSize: 11)),
                value: tempOverwrite,
                onChanged: (v) => setDialogState(() => tempOverwrite = v ?? false),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF0284C7),
                foregroundColor: Colors.white,
              ),
              onPressed: () async {
                Navigator.pop(ctx);
                final messenger = ScaffoldMessenger.of(context);
                setState(() => _isLoading = true);
                final res = await _api.post(ApiConstants.deliveryMonthFill, data: {
                  'customer_id': widget.customerId,
                  'year': _selectedYear,
                  'month': _selectedMonth,
                  'shift': tempShift,
                  'overwrite': tempOverwrite,
                });
                if (mounted) {
                  setState(() => _isLoading = false);
                  if (res.success) {
                    final data = res.data as Map<String, dynamic>?;
                    final created = data?['records_created'] ?? 0;
                    final litres = data?['total_litres'] ?? 0.0;
                    messenger.showSnackBar(
                      SnackBar(
                        content: Text('✓ Month filled: $created deliveries logged ($litres L total)!'),
                        backgroundColor: const Color(0xFF059669),
                      ),
                    );
                    _loadSheet();
                  } else {
                    messenger.showSnackBar(
                      SnackBar(
                        content: Text(res.error ?? 'Failed to auto-fill month.'),
                        backgroundColor: Colors.red,
                      ),
                    );
                  }
                }
              },
              child: const Text('Auto-Fill'),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final totalLitres = ((_summary['total_litres'] as num?)?.toDouble() ?? 0.0);
    final totalAmount = ((_summary['total_amount'] as num?)?.toDouble() ?? 0.0);
    final delShifts = ((_summary['delivered_shifts'] as num?)?.toInt() ?? 0);
    final skipShifts = ((_summary['skipped_shifts'] as num?)?.toInt() ?? 0);
    final leaveShifts = ((_summary['leave_shifts'] as num?)?.toInt() ?? 0);

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(widget.customerName),
            const Text(
              '📅 Monthly Milk Card (1–31 Days)',
              style: TextStyle(fontSize: 11, fontWeight: FontWeight.normal),
            ),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.flash_on),
            tooltip: '⚡ Auto-Fill Month',
            onPressed: _showAutoFillDialog,
          ),
          IconButton(
            icon: const Icon(Icons.done_all),
            tooltip: 'All Delivered',
            onPressed: _markAllMonthDelivered,
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Reload',
            onPressed: _loadSheet,
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                // Month Navigation Header
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                  color: Colors.white,
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      IconButton.filledTonal(
                        icon: const Icon(Icons.chevron_left),
                        onPressed: _prevMonth,
                      ),
                      Text(
                        _monthName,
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900),
                      ),
                      IconButton.filledTonal(
                        icon: const Icon(Icons.chevron_right),
                        onPressed: _nextMonth,
                      ),
                    ],
                  ),
                ),

                // Summary KPI Ribbon
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  color: const Color(0xFFF1F5F9),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      _kpiItem('Total Litres', '${totalLitres.toStringAsFixed(1)} L', const Color(0xFF0284C7)),
                      _kpiItem('Delivered', '$delShifts shifts', const Color(0xFF059669)),
                      _kpiItem('Skipped', '$skipShifts shifts', const Color(0xFFDC2626)),
                      _kpiItem('Vacation', '$leaveShifts shifts', const Color(0xFFD97706)),
                      _kpiItem('Est. Total', '₹${totalAmount.toStringAsFixed(0)}', const Color(0xFF0F172A)),
                    ],
                  ),
                ),

                // Days List (1 to 31)
                Expanded(
                  child: ListView.builder(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    itemCount: _days.length,
                    itemBuilder: (context, idx) {
                      final day = _days[idx];
                      final isLeave = day['is_on_leave'] == true;
                      final m = day['morning'] as Map<String, dynamic>;
                      final e = day['evening'] as Map<String, dynamic>;

                      final dayQty = (m['status'] == 'delivered' ? (m['quantity'] as num).toDouble() : 0.0) +
                          (e['status'] == 'delivered' ? (e['quantity'] as num).toDouble() : 0.0);

                      return Card(
                        margin: const EdgeInsets.symmetric(vertical: 4),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                          side: BorderSide(
                            color: isLeave ? const Color(0xFFFDE68A) : const Color(0xFFE2E8F0),
                            width: 1,
                          ),
                        ),
                        color: isLeave ? const Color(0xFFFFFBEB) : Colors.white,
                        child: Padding(
                          padding: const EdgeInsets.all(10),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              // Row Top: Day & Date
                              Row(
                                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                children: [
                                  Row(
                                    children: [
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                        decoration: BoxDecoration(
                                          color: const Color(0xFFE2E8F0),
                                          borderRadius: BorderRadius.circular(6),
                                        ),
                                        child: Text(
                                          'Day ${day['day']}',
                                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 11),
                                        ),
                                      ),
                                      const SizedBox(width: 8),
                                      Text(
                                        '${day['day_of_week']} • ${day['date']}',
                                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
                                      ),
                                    ],
                                  ),
                                  Text(
                                    '${dayQty.toStringAsFixed(1)} L',
                                    style: const TextStyle(fontWeight: FontWeight.w900, color: Color(0xFF0284C7)),
                                  ),
                                ],
                              ),

                              if (isLeave)
                                Padding(
                                  padding: const EdgeInsets.only(top: 4),
                                  child: Text(
                                    '🏖 On Vacation: ${day['leave_reason'] ?? 'Leave'} (0.00L billed)',
                                    style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFFB45309)),
                                  ),
                                ),

                              const Divider(height: 12),

                              // Shifts Matrix
                              Row(
                                children: [
                                  // Morning Shift
                                  Expanded(
                                    child: _shiftEditor(
                                      title: '🌅 Morning',
                                      shiftMap: m,
                                      onChanged: () {
                                        _recalculateSummary();
                                      },
                                    ),
                                  ),
                                  const SizedBox(width: 8),
                                  // Evening Shift
                                  Expanded(
                                    child: _shiftEditor(
                                      title: '🌆 Evening',
                                      shiftMap: e,
                                      onChanged: () {
                                        _recalculateSummary();
                                      },
                                    ),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),

                // Bottom Save Bar
                SafeArea(
                  child: Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      boxShadow: [
                        BoxShadow(color: Colors.black.withValues(alpha: 0.06), blurRadius: 8, offset: const Offset(0, -2)),
                      ],
                    ),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                'Month Total: ${totalLitres.toStringAsFixed(1)} Litres',
                                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                              ),
                              Text(
                                'Projected: ₹${totalAmount.toStringAsFixed(2)}',
                                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Color(0xFF059669)),
                              ),
                            ],
                          ),
                        ),
                        ElevatedButton.icon(
                          onPressed: _isSaving ? null : _saveSheet,
                          icon: _isSaving
                              ? const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                              : const Icon(Icons.save, size: 18),
                          label: Text(_isSaving ? 'Saving...' : 'Save Card'),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF0284C7),
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
    );
  }

  Widget _kpiItem(String label, String value, Color color) {
    return Column(
      children: [
        Text(value, style: TextStyle(fontWeight: FontWeight.w900, fontSize: 13, color: color)),
        Text(label, style: const TextStyle(fontSize: 10, color: Colors.black54)),
      ],
    );
  }

  Widget _shiftEditor({
    required String title,
    required Map<String, dynamic> shiftMap,
    required VoidCallback onChanged,
  }) {
    final status = shiftMap['status']?.toString() ?? 'delivered';
    final qty = (shiftMap['quantity'] as num?)?.toDouble() ?? 0.0;

    Color bg;
    Color fg;
    if (status == 'delivered') {
      bg = const Color(0xFFD1FAE5);
      fg = const Color(0xFF065F46);
    } else if (status == 'skipped') {
      bg = const Color(0xFFFEE2E2);
      fg = const Color(0xFF991B1B);
    } else {
      bg = const Color(0xFFE2E8F0);
      fg = const Color(0xFF334155);
    }

    return Container(
      padding: const EdgeInsets.all(6),
      decoration: BoxDecoration(
        color: bg.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: bg),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(title, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11, color: fg)),
              DropdownButton<String>(
                value: status,
                isDense: true,
                underline: const SizedBox(),
                items: const [
                  DropdownMenuItem(value: 'delivered', child: Text('✓ Delivered', style: TextStyle(fontSize: 11))),
                  DropdownMenuItem(value: 'skipped', child: Text('✗ Skipped', style: TextStyle(fontSize: 11))),
                  DropdownMenuItem(value: 'leave', child: Text('🏖 Leave', style: TextStyle(fontSize: 11))),
                ],
                onChanged: (newVal) {
                  if (newVal != null) {
                    setState(() {
                      shiftMap['status'] = newVal;
                      if (newVal != 'delivered') {
                        shiftMap['quantity'] = 0.0;
                      } else if (qty <= 0) {
                        shiftMap['quantity'] = 1.0;
                      }
                    });
                    onChanged();
                  }
                },
              ),
            ],
          ),
          if (status == 'delivered')
            Row(
              children: [
                InkWell(
                  onTap: () {
                    if (qty >= 0.5) {
                      setState(() {
                        shiftMap['quantity'] = double.parse((qty - 0.5).toStringAsFixed(1));
                      });
                      onChanged();
                    }
                  },
                  child: const Padding(
                    padding: EdgeInsets.all(2.0),
                    child: Icon(Icons.remove_circle_outline, size: 18),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 4),
                  child: Text(
                    '${qty.toStringAsFixed(1)}L',
                    style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 12),
                  ),
                ),
                InkWell(
                  onTap: () {
                    setState(() {
                      shiftMap['quantity'] = double.parse((qty + 0.5).toStringAsFixed(1));
                    });
                    onChanged();
                  },
                  child: const Padding(
                    padding: EdgeInsets.all(2.0),
                    child: Icon(Icons.add_circle_outline, size: 18),
                  ),
                ),
              ],
            )
          else
            const Text(
              '0.0 Litres (Zero-Charge)',
              style: TextStyle(fontSize: 10, fontStyle: FontStyle.italic, color: Colors.black54),
            ),
        ],
      ),
    );
  }
}
