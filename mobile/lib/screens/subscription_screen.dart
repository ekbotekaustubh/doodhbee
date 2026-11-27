import 'package:flutter/material.dart';
import 'package:razorpay_flutter/razorpay_flutter.dart';
import '../core/api/api_client.dart';
import '../core/constants/api_constants.dart';

class SubscriptionScreen extends StatefulWidget {
  const SubscriptionScreen({super.key});

  @override
  State<SubscriptionScreen> createState() => _SubscriptionScreenState();
}

class _SubscriptionScreenState extends State<SubscriptionScreen> {
  final ApiClient _api = ApiClient();
  late final Razorpay _razorpay;

  Map<String, dynamic>? _quota;
  Map<String, dynamic>? _razorpayConfig;
  List<dynamic> _plans = [];
  bool _isLoading = true;
  bool _isUpgrading = false;
  String? _errorMessage;
  int? _selectedPlanId;
  String _selectedCycle = 'monthly';

  @override
  void initState() {
    super.initState();
    _razorpay = Razorpay();
    _razorpay.on(Razorpay.EVENT_PAYMENT_SUCCESS, _handleRazorpaySuccess);
    _razorpay.on(Razorpay.EVENT_PAYMENT_ERROR, _handleRazorpayError);
    _razorpay.on(Razorpay.EVENT_EXTERNAL_WALLET, _handleExternalWallet);
    _loadSubscription();
  }

  @override
  void dispose() {
    _razorpay.clear();
    super.dispose();
  }

  Future<void> _loadSubscription() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    final res = await _api.get(ApiConstants.subscription);

    if (res.success && res.data != null) {
      final quota = res.data['quota'] as Map<String, dynamic>?;
      final allPlans = (res.data['plans'] as List?) ?? [];
      final paidPlans = allPlans.where((p) => p['is_free'] != true).toList();
      final currentCycle = quota?['billing_cycle']?.toString();
      final cycle = (currentCycle == 'yearly') ? 'yearly' : 'monthly';
      final currentPlanId = (quota?['plan_id'] as num?)?.toInt();

      int? defaultPid;
      for (final p in paidPlans) {
        final pid = (p['id'] as num?)?.toInt();
        final allowed = p['previews']?[cycle]?['allowed'] == true;
        if (pid == currentPlanId && allowed) {
          defaultPid = pid;
          break;
        }
      }
      if (defaultPid == null) {
        for (final p in paidPlans) {
          if (p['previews']?[cycle]?['allowed'] == true) {
            defaultPid = (p['id'] as num?)?.toInt();
            break;
          }
        }
      }
      defaultPid ??= paidPlans.isNotEmpty ? (paidPlans.first['id'] as num?)?.toInt() : null;

      setState(() {
        _quota = quota;
        _razorpayConfig = res.data['razorpay'] is Map ? Map<String, dynamic>.from(res.data['razorpay'] as Map) : null;
        _plans = paidPlans;
        _selectedCycle = cycle;
        _selectedPlanId = defaultPid;
        _isLoading = false;
      });
    } else {
      setState(() {
        _errorMessage = res.error ?? 'Failed to load subscription details';
        _isLoading = false;
      });
    }
  }

  Map<String, dynamic>? _selectedPlan() {
    if (_selectedPlanId == null) return null;
    for (final p in _plans) {
      if ((p['id'] as num?)?.toInt() == _selectedPlanId && p is Map<String, dynamic>) {
        return p;
      }
    }
    return null;
  }

  Map<String, dynamic>? _previewFor(dynamic plan, String cycle) {
    final previews = plan['previews'];
    if (previews is Map && previews[cycle] is Map) {
      return Map<String, dynamic>.from(previews[cycle] as Map);
    }
    return null;
  }

  void _selectCycle(String cycle) {
    int? nextPlanId = _selectedPlanId;
    final currentSel = _selectedPlan();
    if (currentSel == null || _previewFor(currentSel, cycle)?['allowed'] != true) {
      for (final p in _plans) {
        if (_previewFor(p, cycle)?['allowed'] == true) {
          nextPlanId = (p['id'] as num?)?.toInt();
          break;
        }
      }
    }
    setState(() {
      _selectedCycle = cycle;
      _selectedPlanId = nextPlanId;
    });
  }

  Future<void> _activatePlan() async {
    final plan = _selectedPlan();
    if (plan == null) return;
    final preview = _previewFor(plan, _selectedCycle);
    if (preview == null || preview['allowed'] != true) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(preview?['reason']?.toString() ?? 'This plan selection is unavailable.'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    final amount = (preview['amount'] as num?)?.toDouble() ?? 0.0;
    final baseAmount = (preview['base_amount'] as num?)?.toDouble() ?? amount;
    final gstAmount = (preview['gst_amount'] as num?)?.toDouble() ?? 0.0;
    final gstPct = (preview['gst_percentage'] as num?)?.toDouble() ?? 0.0;
    final planName = plan['name']?.toString() ?? 'Plan';
    final cycleLabel = _selectedCycle == 'yearly' ? 'Yearly' : 'Monthly';
    final note = preview['note']?.toString() ?? '';

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Confirm $planName ($cycleLabel)'),
        content: Text(
          gstPct > 0
              ? 'Base: ₹${baseAmount.toStringAsFixed(2)}\nGST (${gstPct.toStringAsFixed(0)}%): ₹${gstAmount.toStringAsFixed(2)}\nTotal Payable: ₹${amount.toStringAsFixed(2)}\n\n$note'
              : 'Pay ₹${amount.toStringAsFixed(2)}?\n\n$note',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF16A34A)),
            child: const Text('Confirm'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    final useRazorpay = _razorpayConfig?['configured'] == true;
    if (useRazorpay) {
      await _startRazorpayCheckout();
      return;
    }

    setState(() => _isUpgrading = true);

    final res = await _api.post('${ApiConstants.subscription}/upgrade', data: {
      'plan_id': _selectedPlanId,
      'billing_cycle': _selectedCycle,
    });

    setState(() => _isUpgrading = false);

    if (!mounted) return;

    if (res.success) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res.message ?? 'Plan updated successfully!'),
          backgroundColor: const Color(0xFF15803D),
        ),
      );
      _loadSubscription();
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res.error ?? 'Failed to update plan. Please try again.'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  Future<void> _startRazorpayCheckout() async {
    setState(() => _isUpgrading = true);

    final res = await _api.post('${ApiConstants.subscription}/razorpay/order', data: {
      'plan_id': _selectedPlanId,
      'billing_cycle': _selectedCycle,
    });

    if (!mounted) return;

    if (!res.success || res.data == null) {
      setState(() => _isUpgrading = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res.error ?? 'Could not create Razorpay order.'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    final order = res.data as Map<String, dynamic>;
    final prefill = order['prefill'] is Map ? Map<String, dynamic>.from(order['prefill'] as Map) : {};

    try {
      _razorpay.open({
        'key': order['key_id'],
        'amount': order['amount_paise'],
        'currency': order['currency'] ?? 'INR',
        'name': order['company_name'] ?? 'Shri Ram Open Source Technologies',
        'description': order['description'] ?? 'DoodhBee Subscription',
        'order_id': order['order_id'],
        'prefill': {
          'name': prefill['name'] ?? '',
          'email': prefill['email'] ?? '',
          'contact': prefill['contact'] ?? '',
        },
        'theme': {'color': '#4F46E5'},
      });
    } catch (e) {
      setState(() => _isUpgrading = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Failed to open Razorpay: $e'), backgroundColor: Colors.red),
      );
    }
  }

  Future<void> _handleRazorpaySuccess(PaymentSuccessResponse response) async {
    final res = await _api.post('${ApiConstants.subscription}/razorpay/verify', data: {
      'razorpay_order_id': response.orderId ?? '',
      'razorpay_payment_id': response.paymentId ?? '',
      'razorpay_signature': response.signature ?? '',
    });

    if (!mounted) return;
    setState(() => _isUpgrading = false);

    if (res.success) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res.message ?? 'Payment verified & GST invoice generated!'),
          backgroundColor: const Color(0xFF15803D),
        ),
      );
      _loadSubscription();
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res.error ?? 'Payment signature verification failed.'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  void _handleRazorpayError(PaymentFailureResponse response) {
    if (!mounted) return;
    setState(() => _isUpgrading = false);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(response.message ?? 'Payment cancelled or failed.'),
        backgroundColor: Colors.red,
      ),
    );
  }

  void _handleExternalWallet(ExternalWalletResponse response) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('External wallet selected: ${response.walletName ?? ''}')),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return Scaffold(
        appBar: AppBar(title: const Text('⚡ Plans & Subscription')),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    final activeCount = (_quota?['active_customers'] as num?)?.toInt() ?? 0;
    final maxAllowed = (_quota?['max_allowed'] as num?)?.toInt() ?? 5;
    final isExpired = _quota?['is_expired'] == true;
    final isInGrace = _quota?['is_in_grace'] == true;
    final isPaid = _quota?['is_paid_active'] == true;
    final planName = _quota?['plan_name']?.toString() ?? 'Free';
    final currentPlanId = (_quota?['plan_id'] as num?)?.toInt();
    final billingCycle = _quota?['billing_cycle']?.toString();
    final validUntil = _quota?['valid_until']?.toString();
    final graceDaysRemaining = (_quota?['grace_days_remaining'] as num?)?.toInt() ?? 0;
    final progress = maxAllowed > 0 ? (activeCount / maxAllowed).clamp(0.0, 1.0) : 0.0;

    final selectedPlan = _selectedPlan();
    final selectedPreview = selectedPlan != null ? _previewFor(selectedPlan, _selectedCycle) : null;
    final canSubmit = selectedPreview != null && selectedPreview['allowed'] == true;

    return Scaffold(
      appBar: AppBar(
        title: const Text('⚡ Plans & Subscription'),
      ),
      body: _errorMessage != null
          ? Center(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(_errorMessage!, style: const TextStyle(color: Colors.red)),
                  const SizedBox(height: 12),
                  ElevatedButton(onPressed: _loadSubscription, child: const Text('Retry')),
                ],
              ),
            )
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Card(
                    color: isExpired
                        ? const Color(0xFFFEF2F2)
                        : isInGrace
                            ? const Color(0xFFFFFBEB)
                            : isPaid
                                ? const Color(0xFFECFDF5)
                                : const Color(0xFFEFF6FF),
                    child: Padding(
                      padding: const EdgeInsets.all(18),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Expanded(
                                child: Text(
                                  isExpired
                                      ? '⚠️ $planName Expired'
                                      : isInGrace
                                          ? '⏳ $planName Grace ($graceDaysRemaining d left)'
                                          : isPaid
                                              ? '🌟 $planName (${(billingCycle ?? 'monthly').toUpperCase()})'
                                              : '🌱 Free Tier',
                                  style: TextStyle(
                                    fontWeight: FontWeight.w900,
                                    fontSize: 17,
                                    color: isExpired
                                        ? const Color(0xFF991B1B)
                                        : isInGrace
                                            ? const Color(0xFF92400E)
                                            : isPaid
                                                ? const Color(0xFF065F46)
                                                : const Color(0xFF1E40AF),
                                  ),
                                ),
                              ),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                decoration: BoxDecoration(
                                  color: isExpired
                                      ? const Color(0xFFEF4444)
                                      : isInGrace
                                          ? const Color(0xFFF59E0B)
                                          : isPaid
                                              ? const Color(0xFF10B981)
                                              : const Color(0xFF3B82F6),
                                  borderRadius: BorderRadius.circular(12),
                                ),
                                child: Text(
                                  isExpired ? 'EXPIRED' : (isInGrace ? 'GRACE' : (isPaid ? 'ACTIVE' : 'FREE')),
                                  style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 14),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              const Text('Capacity Usage:'),
                              Text(
                                '$activeCount / $maxAllowed Customers',
                                style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15),
                              ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          LinearProgressIndicator(
                            value: progress,
                            backgroundColor: Colors.grey.shade300,
                            color: progress > 0.9 ? Colors.red : const Color(0xFF0284C7),
                            minHeight: 10,
                            borderRadius: BorderRadius.circular(6),
                          ),
                          const SizedBox(height: 10),
                          Text(
                            '${(maxAllowed - activeCount).clamp(0, maxAllowed)} customer slots remaining.',
                            style: const TextStyle(fontSize: 12, color: Colors.black54),
                          ),
                          if (validUntil != null) ...[
                            const SizedBox(height: 6),
                            Text(
                              'Valid until: $validUntil',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: isExpired ? const Color(0xFF991B1B) : const Color(0xFF047857),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 20),

                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(18),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Choose Your Plan',
                            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w900),
                          ),
                          const SizedBox(height: 4),
                          const Text(
                            'Renewing early extends from your current expiry date. Switch to a smaller pack within 7 days of expiry.',
                            style: TextStyle(fontSize: 12, color: Colors.black54),
                          ),
                          const Divider(height: 24),
                          const Text('Billing Cycle:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                          const SizedBox(height: 8),
                          Row(
                            children: [
                              Expanded(
                                child: ChoiceChip(
                                  label: const Center(child: Text('Monthly')),
                                  selected: _selectedCycle == 'monthly',
                                  selectedColor: const Color(0xFF0284C7),
                                  labelStyle: TextStyle(
                                    color: _selectedCycle == 'monthly' ? Colors.white : Colors.black87,
                                    fontWeight: FontWeight.bold,
                                  ),
                                  onSelected: (_) => _selectCycle('monthly'),
                                ),
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: ChoiceChip(
                                  label: const Center(child: Text('Yearly (Save ~17%)')),
                                  selected: _selectedCycle == 'yearly',
                                  selectedColor: const Color(0xFF16A34A),
                                  labelStyle: TextStyle(
                                    color: _selectedCycle == 'yearly' ? Colors.white : Colors.black87,
                                    fontWeight: FontWeight.bold,
                                  ),
                                  onSelected: (_) => _selectCycle('yearly'),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 16),
                          const Text('Available Packs:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                          const SizedBox(height: 8),
                          ..._plans.map((p) {
                            final pid = (p['id'] as num?)?.toInt() ?? 0;
                            final preview = _previewFor(p, _selectedCycle);
                            final allowed = preview?['allowed'] == true;
                            final isSelected = _selectedPlanId == pid;
                            final isCurrent = pid == currentPlanId && isPaid;
                            final amount = (preview?['amount'] as num?)?.toDouble() ?? 0.0;
                            final actionType = preview?['action_type']?.toString() ?? '';
                            final reason = preview?['reason']?.toString();

                            return GestureDetector(
                              onTap: allowed ? () => setState(() => _selectedPlanId = pid) : null,
                              child: Container(
                                margin: const EdgeInsets.only(bottom: 10),
                                padding: const EdgeInsets.all(14),
                                decoration: BoxDecoration(
                                  color: !allowed
                                      ? Colors.grey.shade100
                                      : isSelected
                                          ? const Color(0xFFEFF6FF)
                                          : Colors.white,
                                  borderRadius: BorderRadius.circular(12),
                                  border: Border.all(
                                    color: isSelected
                                        ? const Color(0xFF0284C7)
                                        : Colors.grey.shade300,
                                    width: isSelected ? 2 : 1,
                                  ),
                                ),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Row(
                                          children: [
                                            Icon(
                                              isSelected ? Icons.radio_button_checked : Icons.radio_button_unchecked,
                                              size: 18,
                                              color: allowed ? const Color(0xFF0284C7) : Colors.grey,
                                            ),
                                            const SizedBox(width: 8),
                                            Column(
                                              crossAxisAlignment: CrossAxisAlignment.start,
                                              children: [
                                                Row(
                                                  children: [
                                                    Text(
                                                      p['name']?.toString() ?? '',
                                                      style: TextStyle(
                                                        fontWeight: FontWeight.w900,
                                                        fontSize: 15,
                                                        color: allowed ? Colors.black87 : Colors.grey,
                                                      ),
                                                    ),
                                                    if (isCurrent) ...[
                                                      const SizedBox(width: 6),
                                                      Container(
                                                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                                        decoration: BoxDecoration(
                                                          color: const Color(0xFFD1FAE5),
                                                          borderRadius: BorderRadius.circular(6),
                                                        ),
                                                        child: const Text(
                                                          'CURRENT',
                                                          style: TextStyle(fontSize: 9, fontWeight: FontWeight.w900, color: Color(0xFF065F46)),
                                                        ),
                                                      ),
                                                    ],
                                                  ],
                                                ),
                                                Text(
                                                  'Up to ${p['customer_limit']} active customers',
                                                  style: const TextStyle(fontSize: 12, color: Colors.black54),
                                                ),
                                              ],
                                            ),
                                          ],
                                        ),
                                        Column(
                                          crossAxisAlignment: CrossAxisAlignment.end,
                                          children: [
                                            Text(
                                              '₹${amount.toStringAsFixed(0)}',
                                              style: TextStyle(
                                                fontWeight: FontWeight.w900,
                                                fontSize: 16,
                                                color: allowed ? const Color(0xFF0284C7) : Colors.grey,
                                              ),
                                            ),
                                            Text(
                                              allowed ? actionType.toUpperCase() : 'UNAVAILABLE',
                                              style: TextStyle(
                                                fontSize: 9,
                                                fontWeight: FontWeight.bold,
                                                color: allowed ? Colors.black54 : Colors.red.shade400,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ],
                                    ),
                                    if (!allowed && reason != null) ...[
                                      const SizedBox(height: 6),
                                      Text(reason, style: TextStyle(fontSize: 11, color: Colors.red.shade700)),
                                    ],
                                  ],
                                ),
                              ),
                            );
                          }),

                          if (selectedPreview != null) ...[
                            const SizedBox(height: 10),
                            Container(
                              padding: const EdgeInsets.all(14),
                              decoration: BoxDecoration(
                                color: const Color(0xFFF8FAFC),
                                borderRadius: BorderRadius.circular(10),
                                border: Border.all(color: const Color(0xFFE2E8F0)),
                              ),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                    children: [
                                      const Text('Base Plan Price:', style: TextStyle(fontSize: 12, color: Colors.black54)),
                                      Text(
                                        '₹${((selectedPreview['base_amount'] as num?)?.toDouble() ?? 0).toStringAsFixed(2)}',
                                        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                                      ),
                                    ],
                                  ),
                                  if (((selectedPreview['gst_percentage'] as num?)?.toDouble() ?? 0) > 0) ...[
                                    const SizedBox(height: 4),
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Text(
                                          'GST (${((selectedPreview['gst_percentage'] as num?)?.toDouble() ?? 18).toStringAsFixed(0)}% Exclusive):',
                                          style: const TextStyle(fontSize: 12, color: Colors.black54),
                                        ),
                                        Text(
                                          '₹${((selectedPreview['gst_amount'] as num?)?.toDouble() ?? 0).toStringAsFixed(2)}',
                                          style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                                        ),
                                      ],
                                    ),
                                  ],
                                  const Divider(height: 14),
                                  Row(
                                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                    children: [
                                      const Text('Total Payable:', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                                      Text(
                                        '₹${((selectedPreview['amount'] as num?)?.toDouble() ?? 0).toStringAsFixed(2)}',
                                        style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900, color: Color(0xFF0284C7)),
                                      ),
                                    ],
                                  ),
                                  if (selectedPreview['note'] != null) ...[
                                    const SizedBox(height: 6),
                                    Text(
                                      selectedPreview['note'].toString(),
                                      style: const TextStyle(fontSize: 11, color: Colors.black87),
                                    ),
                                  ],
                                  const SizedBox(height: 6),
                                  const Text(
                                    'GST Tax Invoice by Shri Ram Open Source Technologies (27AARPE1865G1ZJ) will be sent via Email & WhatsApp.',
                                    style: TextStyle(fontSize: 10, color: Colors.black54),
                                  ),
                                ],
                              ),
                            ),
                          ],
                          const SizedBox(height: 18),
                          ElevatedButton.icon(
                            onPressed: (_isUpgrading || !canSubmit) ? null : _activatePlan,
                            icon: _isUpgrading
                                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                                : const Icon(Icons.flash_on),
                            label: Text(
                              _isUpgrading
                                  ? 'Processing...'
                                  : (_razorpayConfig?['configured'] == true
                                      ? 'Pay Online via Razorpay (${selectedPlan?['name'] ?? 'Plan'})'
                                      : 'Activate ${selectedPlan?['name'] ?? 'Plan'} (${_selectedCycle == 'yearly' ? 'Yearly' : 'Monthly'})'),
                              style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                            ),
                            style: ElevatedButton.styleFrom(
                              backgroundColor: _razorpayConfig?['configured'] == true
                                  ? const Color(0xFF4F46E5)
                                  : const Color(0xFF0284C7),
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}
