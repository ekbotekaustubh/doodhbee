import 'package:flutter/material.dart';
import '../core/api/api_client.dart';
import '../core/constants/api_constants.dart';

class SubscriptionScreen extends StatefulWidget {
  const SubscriptionScreen({super.key});

  @override
  State<SubscriptionScreen> createState() => _SubscriptionScreenState();
}

class _SubscriptionScreenState extends State<SubscriptionScreen> {
  final ApiClient _api = ApiClient();

  Map<String, dynamic>? _quota;
  Map<String, dynamic>? _pricing;
  bool _isLoading = true;
  bool _isUpgrading = false;
  String? _errorMessage;
  int _selectedBlocks = 1;

  @override
  void initState() {
    super.initState();
    _loadSubscription();
  }

  Future<void> _loadSubscription() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    final res = await _api.get(ApiConstants.subscription);

    if (res.success && res.data != null) {
      setState(() {
        _quota = res.data['quota'];
        _pricing = res.data['pricing'];
        _isLoading = false;
      });
    } else {
      setState(() {
        _errorMessage = res.error ?? 'Failed to load subscription details';
        _isLoading = false;
      });
    }
  }

  Future<void> _upgradeQuota() async {
    final unitPrice = (_pricing?['paid_tier_unit_price'] as num?)?.toDouble() ?? 199.0;
    final unitSize = (_pricing?['paid_tier_unit_size'] as num?)?.toInt() ?? 100;
    final totalAmount = _selectedBlocks * unitPrice;
    final totalSlots = _selectedBlocks * unitSize;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Confirm Quota Upgrade'),
        content: Text(
          'Upgrade your account by +$_selectedBlocks block(s) (+$totalSlots customer capacity) for ₹${totalAmount.toStringAsFixed(0)}?',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF16A34A)),
            child: const Text('Confirm Upgrade'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    setState(() => _isUpgrading = true);

    final res = await _api.post('${ApiConstants.subscription}/upgrade', data: {
      'blocks': _selectedBlocks,
    });

    setState(() => _isUpgrading = false);

    if (!mounted) return;

    if (res.success) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res.message ?? 'Quota upgraded successfully!'),
          backgroundColor: const Color(0xFF15803D),
        ),
      );
      _loadSubscription();
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res.error ?? 'Upgrade failed. Please try again.'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return Scaffold(
        appBar: AppBar(title: const Text('⚡ Quota & Subscription')),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    final activeCount = _quota?['active_customers'] ?? 0;
    final maxAllowed = _quota?['max_allowed'] ?? 5;
    final isPaid = _quota?['payment_status'] == 'active';
    final purchasedBlocks = _quota?['purchased_blocks'] ?? 0;
    final unitPrice = (_pricing?['paid_tier_unit_price'] as num?)?.toDouble() ?? 199.0;
    final unitSize = (_pricing?['paid_tier_unit_size'] as num?)?.toInt() ?? 100;
    final progress = maxAllowed > 0 ? (activeCount / maxAllowed).clamp(0.0, 1.0) : 0.0;

    return Scaffold(
      appBar: AppBar(
        title: const Text('⚡ Quota & Subscription'),
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
                  // Status Banner
                  Card(
                    color: isPaid ? const Color(0xFFECFDF5) : const Color(0xFFEFF6FF),
                    child: Padding(
                      padding: const EdgeInsets.all(18),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                isPaid ? '🌟 Paid Extended Tier' : '🌱 Free Tier',
                                style: TextStyle(
                                  fontWeight: FontWeight.w900,
                                  fontSize: 18,
                                  color: isPaid ? const Color(0xFF065F46) : const Color(0xFF1E40AF),
                                ),
                              ),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                decoration: BoxDecoration(
                                  color: isPaid ? const Color(0xFF10B981) : const Color(0xFF3B82F6),
                                  borderRadius: BorderRadius.circular(12),
                                ),
                                child: Text(
                                  isPaid ? 'ACTIVE' : 'FREE',
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
                            '${maxAllowed - activeCount} customer slots remaining in current plan.',
                            style: const TextStyle(fontSize: 12, color: Colors.black54),
                          ),
                          if (purchasedBlocks > 0) ...[
                            const SizedBox(height: 6),
                            Text(
                              'Purchased Capacity: $purchasedBlocks block(s) (+${purchasedBlocks * unitSize} customers)',
                              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Color(0xFF047857)),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 20),

                  // Upgrade Options Card
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(18),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Scale Your Milk Delivery Business',
                            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w900),
                          ),
                          const SizedBox(height: 6),
                          Text(
                            'Add blocks of $unitSize customer slots for just ₹${unitPrice.toStringAsFixed(0)}/month each.',
                            style: const TextStyle(fontSize: 13, color: Colors.black54),
                          ),
                          const Divider(height: 28),
                          const Text(
                            'Select Number of Blocks:',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                          ),
                          const SizedBox(height: 10),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceAround,
                            children: [1, 2, 5, 10].map((blocks) {
                              final isSelected = _selectedBlocks == blocks;
                              return ChoiceChip(
                                label: Text('+$blocks (+${blocks * unitSize})'),
                                selected: isSelected,
                                selectedColor: const Color(0xFF0284C7),
                                labelStyle: TextStyle(
                                  color: isSelected ? Colors.white : Colors.black87,
                                  fontWeight: FontWeight.bold,
                                ),
                                onSelected: (_) => setState(() => _selectedBlocks = blocks),
                              );
                            }).toList(),
                          ),
                          const SizedBox(height: 20),
                          Container(
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                              color: const Color(0xFFF8FAFC),
                              borderRadius: BorderRadius.circular(10),
                              border: Border.all(color: const Color(0xFFE2E8F0)),
                            ),
                            child: Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text('Total Plan Price:', style: TextStyle(fontSize: 12, color: Colors.black54)),
                                    Text(
                                      '₹${(_selectedBlocks * unitPrice).toStringAsFixed(0)}',
                                      style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900, color: Color(0xFF0284C7)),
                                    ),
                                  ],
                                ),
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    const Text('Added Capacity:', style: TextStyle(fontSize: 12, color: Colors.black54)),
                                    Text(
                                      '+${_selectedBlocks * unitSize} slots',
                                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFF16A34A)),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 20),
                          ElevatedButton.icon(
                            onPressed: _isUpgrading ? null : _upgradeQuota,
                            icon: _isUpgrading
                                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                                : const Icon(Icons.flash_on),
                            label: Text(
                              _isUpgrading ? 'Upgrading Quota...' : 'Upgrade Now (+${_selectedBlocks * unitSize} Customers)',
                              style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                            ),
                            style: ElevatedButton.styleFrom(
                              backgroundColor: const Color(0xFF0284C7),
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
