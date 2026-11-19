import 'dart:math';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import '../core/api/api_client.dart';
import '../core/api/auth_service.dart';
import '../core/constants/api_constants.dart';
import '../core/sync/sync_engine.dart';
import '../models/delivery_item.dart';
import 'login_screen.dart';

class DeliveryScreen extends StatefulWidget {
  const DeliveryScreen({super.key});

  @override
  State<DeliveryScreen> createState() => _DeliveryScreenState();
}

class _DeliveryScreenState extends State<DeliveryScreen> {
  final SyncEngine _syncEngine = SyncEngine();
  final ApiClient _api = ApiClient();
  final TextEditingController _searchController = TextEditingController();

  DateTime _selectedDate = DateTime.now();
  String _selectedShift = DateTime.now().hour >= 14 ? 'evening' : 'morning';
  String? _selectedRoute;
  Map<String, dynamic>? _currentUser;

  List<DeliveryItem> _items = [];
  List<String> _routes = [];
  bool _isLoading = false;
  int _pendingSyncCount = 0;
  int _currentPage = 1;
  final int _perPage = 25;

  @override
  void initState() {
    super.initState();
    _initUserAndDeliveries();

    _syncEngine.outboxCountStream.listen((count) {
      if (mounted) setState(() => _pendingSyncCount = count);
    });

    _syncEngine.connectivityStream.listen((isOnline) {
      if (mounted) setState(() {});
    });
  }

  Future<void> _initUserAndDeliveries() async {
    final user = await AuthService().getSavedUser();
    if (mounted && user != null) {
      setState(() {
        _currentUser = user;
        if (user['assigned_route_name'] != null && user['assigned_route_name'].toString().isNotEmpty) {
          _selectedRoute = user['assigned_route_name'].toString();
        }
      });
    }
    await _loadRoutes();
    await _loadDeliveries();
  }

  Future<void> _loadRoutes() async {
    final res = await _api.get(ApiConstants.deliveryRoutes);
    if (res.success && res.data != null) {
      final list = (res.data as List).map((m) => m['name']?.toString() ?? '').where((n) => n.isNotEmpty).toList();
      if (mounted && list.isNotEmpty) {
        setState(() {
          _routes = list;
        });
      }
    }
  }

  String get _dateStr => DateFormat('yyyy-MM-dd').format(_selectedDate);

  Future<void> _loadDeliveries() async {
    setState(() => _isLoading = true);

    try {
      final rawList = await _syncEngine.syncDeliveries(
        _dateStr,
        _selectedShift,
        route: _selectedRoute,
        search: _searchController.text.trim(),
      );

      final items = rawList.map((m) => DeliveryItem.fromMap(m)).toList();
      if (_routes.isEmpty) {
        final routesSet = items.map((e) => e.routeName).where((r) => r.isNotEmpty).toSet();
        _routes = routesSet.toList();
      }

      setState(() {
        _items = items;
        final totalPages = (_items.length / _perPage).ceil();
        if (_currentPage > totalPages && totalPages > 0) {
          _currentPage = totalPages;
        }
      });
    } catch (_) {
    } finally {
      setState(() => _isLoading = false);
    }
  }

  Future<void> _toggleDelivery(DeliveryItem item, String newStatus, double quantity) async {
    // 1. Optimistic Local UI update
    setState(() {
      final index = _items.indexWhere((it) => it.customerId == item.customerId);
      if (index != -1) {
        _items[index] = item.copyWith(
          status: newStatus,
          quantity: quantity,
          synced: false,
        );
      }
    });

    // 2. Queue in offline Outbox & background flush
    await _syncEngine.recordDeliveryOptimistic(
      customerId: item.customerId,
      date: _dateStr,
      shift: _selectedShift,
      status: newStatus,
      quantity: quantity,
      notes: item.notes,
    );
  }

  void _showAdjustDialog(DeliveryItem item) {
    double tempQty = item.quantity > 0 ? item.quantity : 1.0;
    final noteController = TextEditingController(text: item.notes);

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            return Padding(
              padding: EdgeInsets.only(
                left: 20,
                right: 20,
                top: 20,
                bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        'Adjust: ${item.customerName}',
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                      IconButton(
                        icon: const Icon(Icons.close),
                        onPressed: () => Navigator.pop(ctx),
                      ),
                    ],
                  ),
                  const Divider(),
                  const SizedBox(height: 12),
                  const Text(
                    'Delivered Quantity (Liters)',
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.black54),
                  ),
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      IconButton.filledTonal(
                        icon: const Icon(Icons.remove),
                        onPressed: () {
                          if (tempQty >= 0.5) {
                            setModalState(() => tempQty = double.parse((tempQty - 0.5).toStringAsFixed(1)));
                          }
                        },
                      ),
                      const SizedBox(width: 24),
                      Text(
                        '${tempQty.toStringAsFixed(1)} L',
                        style: const TextStyle(fontSize: 32, fontWeight: FontWeight.w900),
                      ),
                      const SizedBox(width: 24),
                      IconButton.filledTonal(
                        icon: const Icon(Icons.add),
                        onPressed: () {
                          setModalState(() => tempQty = double.parse((tempQty + 0.5).toStringAsFixed(1)));
                        },
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: noteController,
                    decoration: InputDecoration(
                      labelText: 'Note / Reason',
                      hintText: 'e.g. Extra milk requested',
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                    ),
                  ),
                  const SizedBox(height: 20),
                  ElevatedButton(
                    onPressed: () {
                      Navigator.pop(ctx);
                      _toggleDelivery(item, tempQty > 0 ? 'delivered' : 'skipped', tempQty);
                    },
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF0284C7),
                      padding: const EdgeInsets.symmetric(vertical: 14),
                    ),
                    child: const Text('Save Volume Adjustment', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  Future<void> _makePhoneCall(String phone) async {
    final clean = phone.replaceAll(RegExp(r'[^0-9]'), '');
    final uri = Uri.parse('tel:$clean');
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri);
    }
  }

  bool get _isDeliveryBoy => _currentUser != null && _currentUser!['role'] == 'delivery';

  Future<void> _confirmLogout() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Sign Out'),
        content: const Text('Are you sure you want to log out of DoodhBee?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Logout', style: TextStyle(color: Colors.red, fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );

    if (confirm == true) {
      await AuthService().logout();
      if (mounted) {
        Navigator.of(context).pushAndRemoveUntil(
          MaterialPageRoute(builder: (_) => const LoginScreen()),
          (route) => false,
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final totalDelivered = _items.where((i) => i.status == 'delivered').length;
    final totalLiters = _items
        .where((i) => i.status == 'delivered')
        .fold(0.0, (sum, i) => sum + i.quantity);

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(_isDeliveryBoy && _currentUser?['name'] != null
                ? '🛵 ${_currentUser!['name']}'
                : '📋 Daily Delivery Run-Sheet'),
            if (_isDeliveryBoy && _currentUser?['assigned_route_name'] != null)
              Text(
                'Route: ${_currentUser!['assigned_route_name']}',
                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.normal),
              ),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.sync),
            tooltip: 'Sync Offline Changes',
            onPressed: () {
              _syncEngine.flushOutbox();
              _loadDeliveries();
            },
          ),
          if (_isDeliveryBoy)
            IconButton(
              icon: const Icon(Icons.logout),
              tooltip: 'Logout',
              onPressed: _confirmLogout,
            ),
        ],
      ),
      body: Column(
        children: [
          // Offline Banner Indicator
          if (!_syncEngine.isOnline || _pendingSyncCount > 0)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              color: !_syncEngine.isOnline ? const Color(0xFFFEF3C7) : const Color(0xFFEFF6FF),
              child: Row(
                children: [
                  Icon(
                    !_syncEngine.isOnline ? Icons.wifi_off : Icons.cloud_upload_outlined,
                    size: 16,
                    color: !_syncEngine.isOnline ? const Color(0xFFD97706) : const Color(0xFF2563EB),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      !_syncEngine.isOnline
                          ? '⚡ Offline Mode Active ($_pendingSyncCount pending sync)'
                          : 'Syncing $_pendingSyncCount update(s) to cloud...',
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.bold,
                        color: !_syncEngine.isOnline ? const Color(0xFF92400E) : const Color(0xFF1E40AF),
                      ),
                    ),
                  ),
                  if (_syncEngine.isOnline)
                    TextButton(
                      onPressed: () => _syncEngine.flushOutbox(),
                      child: const Text('Sync Now', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                    ),
                ],
              ),
            ),

          // Date & Shift Bar
          Container(
            padding: const EdgeInsets.all(12),
            color: Colors.white,
            child: Column(
              children: [
                Row(
                  children: [
                    // Date Navigation
                    IconButton(
                      icon: const Icon(Icons.chevron_left),
                      onPressed: () {
                        setState(() {
                          _selectedDate = _selectedDate.subtract(const Duration(days: 1));
                          _currentPage = 1;
                        });
                        _loadDeliveries();
                      },
                    ),
                    Expanded(
                      child: InkWell(
                        onTap: () async {
                          final picked = await showDatePicker(
                            context: context,
                            initialDate: _selectedDate,
                            firstDate: DateTime(2020),
                            lastDate: DateTime(2030),
                          );
                          if (picked != null) {
                            setState(() {
                              _selectedDate = picked;
                              _currentPage = 1;
                            });
                            _loadDeliveries();
                          }
                        },
                        child: Text(
                          DateFormat('EEE, dd MMM yyyy').format(_selectedDate),
                          textAlign: TextAlign.center,
                          style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15),
                        ),
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.chevron_right),
                      onPressed: () {
                        setState(() {
                          _selectedDate = _selectedDate.add(const Duration(days: 1));
                          _currentPage = 1;
                        });
                        _loadDeliveries();
                      },
                    ),
                  ],
                ),
                const SizedBox(height: 8),

                // Shift Toggle (Morning vs Evening)
                Row(
                  children: [
                    Expanded(
                      child: ChoiceChip(
                        label: const Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Text('🌅 Morning'),
                          ],
                        ),
                        selected: _selectedShift == 'morning',
                        selectedColor: const Color(0xFFFEF3C7),
                        onSelected: (val) {
                          if (val) {
                            setState(() {
                              _selectedShift = 'morning';
                              _currentPage = 1;
                            });
                            _loadDeliveries();
                          }
                        },
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: ChoiceChip(
                        label: const Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Text('🌆 Evening'),
                          ],
                        ),
                        selected: _selectedShift == 'evening',
                        selectedColor: const Color(0xFFE0E7FF),
                        onSelected: (val) {
                          if (val) {
                            setState(() {
                              _selectedShift = 'evening';
                              _currentPage = 1;
                            });
                            _loadDeliveries();
                          }
                        },
                      ),
                    ),
                  ],
                ),
                if (_routes.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        Padding(
                          padding: const EdgeInsets.only(right: 6),
                          child: ChoiceChip(
                            label: const Text('All Routes'),
                            selected: _selectedRoute == null,
                            onSelected: (val) {
                              if (val) {
                                setState(() {
                                  _selectedRoute = null;
                                  _currentPage = 1;
                                });
                                _loadDeliveries();
                              }
                            },
                          ),
                        ),
                        ..._routes.map(
                          (r) => Padding(
                            padding: const EdgeInsets.only(right: 6),
                            child: ChoiceChip(
                              label: Text(r),
                              selected: _selectedRoute == r,
                              onSelected: (val) {
                                setState(() {
                                  _selectedRoute = val ? r : null;
                                  _currentPage = 1;
                                });
                                _loadDeliveries();
                              },
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],

                // Search Input Field
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: TextField(
                    controller: _searchController,
                    onChanged: (val) {
                      setState(() => _currentPage = 1);
                      _loadDeliveries();
                    },
                    decoration: InputDecoration(
                      hintText: 'Search customer name, phone, address...',
                      hintStyle: const TextStyle(fontSize: 12),
                      prefixIcon: const Icon(Icons.search, size: 18),
                      suffixIcon: _searchController.text.isNotEmpty
                          ? IconButton(
                              icon: const Icon(Icons.clear, size: 16),
                              onPressed: () {
                                _searchController.clear();
                                setState(() => _currentPage = 1);
                                _loadDeliveries();
                              },
                            )
                          : null,
                      contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                      isDense: true,
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                      filled: true,
                      fillColor: const Color(0xFFF8FAFC),
                    ),
                  ),
                ),
              ],
            ),
          ),

          // Shift Metrics Strip
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            color: const Color(0xFFF1F5F9),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    'Delivered: $totalDelivered/${_items.length} Houses${_selectedRoute != null ? ' in $_selectedRoute' : ''}',
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Color(0xFF334155)),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Text(
                  'Volume: ${totalLiters.toStringAsFixed(1)} Liters',
                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w900, color: Color(0xFF0284C7)),
                ),
              ],
            ),
          ),

          // Run-Sheet Cards List
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _items.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Text('🔍', style: TextStyle(fontSize: 40)),
                            const SizedBox(height: 8),
                            Text(
                              _searchController.text.isNotEmpty || _selectedRoute != null
                                  ? 'No matching customers found'
                                  : 'No deliveries scheduled for this shift',
                              style: const TextStyle(fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(height: 12),
                            if (_searchController.text.isNotEmpty || _selectedRoute != null)
                              ElevatedButton(
                                onPressed: () {
                                  _searchController.clear();
                                  setState(() {
                                    _selectedRoute = null;
                                    _currentPage = 1;
                                  });
                                  _loadDeliveries();
                                },
                                child: const Text('Reset Filters'),
                              )
                            else
                              ElevatedButton(
                                onPressed: _loadDeliveries,
                                style: ElevatedButton.styleFrom(minimumSize: const Size(120, 36)),
                                child: const Text('Refresh'),
                              ),
                          ],
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: _loadDeliveries,
                        child: ListView.builder(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          itemCount: min(_perPage, _items.length - (_currentPage - 1) * _perPage),
                          itemBuilder: (context, idx) {
                            final itemIndex = (_currentPage - 1) * _perPage + idx;
                            if (itemIndex >= _items.length) return const SizedBox.shrink();
                            final item = _items[itemIndex];
                            return _buildDeliveryCard(item);
                          },
                        ),
                      ),
          ),

          // Pagination Controls
          if ((_items.length / _perPage).ceil() > 1)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              decoration: const BoxDecoration(
                color: Colors.white,
                border: Border(top: BorderSide(color: Color(0xFFE2E8F0))),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Page $_currentPage of ${(_items.length / _perPage).ceil()} (${(_currentPage - 1) * _perPage + 1}-${min(_items.length, _currentPage * _perPage)} of ${_items.length})',
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFF475569)),
                  ),
                  Row(
                    children: [
                      IconButton.outlined(
                        visualDensity: VisualDensity.compact,
                        icon: const Icon(Icons.chevron_left, size: 20),
                        onPressed: _currentPage > 1
                            ? () => setState(() => _currentPage--)
                            : null,
                      ),
                      const SizedBox(width: 6),
                      IconButton.outlined(
                        visualDensity: VisualDensity.compact,
                        icon: const Icon(Icons.chevron_right, size: 20),
                        onPressed: _currentPage < (_items.length / _perPage).ceil()
                            ? () => setState(() => _currentPage++)
                            : null,
                      ),
                    ],
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildDeliveryCard(DeliveryItem item) {
    final isDelivered = item.status == 'delivered';
    final isSkipped = item.status == 'skipped';
    final isOnLeave = item.isOnLeave || item.status == 'leave';

    return Card(
      margin: const EdgeInsets.symmetric(vertical: 6),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Top Row: Customer Name & Phone
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item.customerName,
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900, color: Color(0xFF0F172A)),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        '${item.routeName} • ${item.address}',
                        style: const TextStyle(fontSize: 11, color: Color(0xFF64748B)),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
                if (item.customerPhone.isNotEmpty)
                  IconButton.filledTonal(
                    icon: const Icon(Icons.phone, size: 18),
                    onPressed: () => _makePhoneCall(item.customerPhone),
                  ),
              ],
            ),
            const SizedBox(height: 10),

            // Products & Quantity Info
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF1F5F9),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    '${item.productName}: ${item.quantity.toStringAsFixed(1)} ${item.unit}',
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Color(0xFF334155)),
                  ),
                ),
                if (!item.synced)
                  const Row(
                    children: [
                      Icon(Icons.cloud_upload_outlined, size: 14, color: Colors.orange),
                      SizedBox(width: 4),
                      Text('Queued offline', style: TextStyle(fontSize: 10, color: Colors.orange, fontWeight: FontWeight.bold)),
                    ],
                  ),
              ],
            ),
            const SizedBox(height: 12),

            // Field Action Buttons (High contrast, 48px thumb targets)
            if (isOnLeave)
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(vertical: 10),
                decoration: BoxDecoration(
                  color: const Color(0xFFF1F5F9),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Center(
                  child: Text('🏖 Customer On Vacation (0.00L Billed)', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.black54)),
                ),
              )
            else
              Row(
                children: [
                  // Delivered Button
                  Expanded(
                    child: ElevatedButton.icon(
                      onPressed: () => _toggleDelivery(item, 'delivered', item.quantity > 0 ? item.quantity : 1.0),
                      icon: const Icon(Icons.check, size: 18),
                      label: const Text('Delivered'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: isDelivered ? const Color(0xFF16A34A) : Colors.white,
                        foregroundColor: isDelivered ? Colors.white : const Color(0xFF16A34A),
                        side: BorderSide(color: const Color(0xFF16A34A), width: isDelivered ? 0 : 1.5),
                        elevation: isDelivered ? 2 : 0,
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),

                  // Skipped Button
                  Expanded(
                    child: ElevatedButton.icon(
                      onPressed: () => _toggleDelivery(item, 'skipped', 0.0),
                      icon: const Icon(Icons.close, size: 18),
                      label: const Text('Skipped'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: isSkipped ? const Color(0xFFDC2626) : Colors.white,
                        foregroundColor: isSkipped ? Colors.white : const Color(0xFFDC2626),
                        side: BorderSide(color: const Color(0xFFDC2626), width: isSkipped ? 0 : 1.5),
                        elevation: isSkipped ? 2 : 0,
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),

                  // Edit Volume Button
                  OutlinedButton(
                    onPressed: () => _showAdjustDialog(item),
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                    ),
                    child: const Text('✏️ Edit'),
                  ),
                ],
              ),
          ],
        ),
      ),
    );
  }
}
