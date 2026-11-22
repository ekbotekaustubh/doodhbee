import 'package:flutter/material.dart';
import '../core/api/api_client.dart';
import '../core/api/auth_service.dart';
import '../core/constants/api_constants.dart';
import 'charges_screen.dart';
import 'login_screen.dart';
import 'product_list_screen.dart';
import 'route_list_screen.dart';
import 'settings_screen.dart';
import 'staff_list_screen.dart';
import 'subscription_screen.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  final ApiClient _api = ApiClient();
  final AuthService _authService = AuthService();

  Map<String, dynamic>? _stats;
  Map<String, dynamic>? _quota;
  Map<String, dynamic>? _user;
  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
    _loadDashboard();
  }

  Future<void> _loadDashboard() async {
    setState(() => _isLoading = true);

    final res = await _api.get(ApiConstants.dashboardStats);
    final user = await _authService.getSavedUser();

    if (res.success && res.data != null) {
      setState(() {
        _stats = res.data;
        _quota = res.data['quota'];
        _user = user;
      });
    }

    setState(() => _isLoading = false);
  }

  Future<void> _handleLogout() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Confirm Logout'),
        content: const Text('Are you sure you want to sign out?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Sign Out')),
        ],
      ),
    );

    if (confirm == true) {
      await _authService.logout();
      if (!mounted) return;
      Navigator.pushAndRemoveUntil(
        context,
        MaterialPageRoute(builder: (_) => const LoginScreen()),
        (route) => false,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return Scaffold(
        appBar: AppBar(title: const Text('⚙️ Dashboard & Settings')),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    final morningStats = _stats?['shifts']?['morning'] ?? {};
    final eveningStats = _stats?['shifts']?['evening'] ?? {};
    final billing = _stats?['billing_month'] ?? {};

    final activeCount = _quota?['active_customers'] ?? 0;
    final maxAllowed = _quota?['max_allowed'] ?? 5;

    return Scaffold(
      appBar: AppBar(
        title: const Text('⚙️ Dashboard & Settings'),
        actions: [
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'Sign Out',
            onPressed: _handleLogout,
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _loadDashboard,
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Dairy Profile Header
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Row(
                    children: [
                      CircleAvatar(
                        radius: 28,
                        backgroundColor: const Color(0xFFE8AB06),
                        child: ClipOval(
                          child: Image.asset(
                            'assets/images/logo_icon.png',
                            width: 56,
                            height: 56,
                            fit: BoxFit.cover,
                          ),
                        ),
                      ),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _user?['business_name'] ?? 'Krushna Dairy Farm',
                              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              'Owner: ${_user?['owner_name'] ?? 'Milkman'} • ${_user?['phone'] ?? ''}',
                              style: const TextStyle(fontSize: 12, color: Colors.black54),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // Quota Card
              Card(
                color: const Color(0xFFEFF6FF),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('⚡ Customer Quota Limit', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Color(0xFF1E40AF))),
                          Text('$activeCount / $maxAllowed slots', style: const TextStyle(fontWeight: FontWeight.w900, color: Color(0xFF0284C7))),
                        ],
                      ),
                      const SizedBox(height: 8),
                      LinearProgressIndicator(
                        value: maxAllowed > 0 ? (activeCount / maxAllowed).clamp(0.0, 1.0) : 0.0,
                        backgroundColor: Colors.blue.shade100,
                        color: const Color(0xFF0284C7),
                        minHeight: 8,
                        borderRadius: BorderRadius.circular(4),
                      ),
                      const SizedBox(height: 8),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            'Tier: ${_quota?['payment_status'] == 'active' ? 'Paid Extended Tier' : 'Free 5-Customer Tier'}',
                            style: const TextStyle(fontSize: 11, color: Color(0xFF1E40AF)),
                          ),
                          InkWell(
                            onTap: () async {
                              await Navigator.push(
                                context,
                                MaterialPageRoute(builder: (_) => const SubscriptionScreen()),
                              );
                              _loadDashboard();
                            },
                            child: const Padding(
                              padding: EdgeInsets.symmetric(vertical: 2, horizontal: 4),
                              child: Text(
                                'Upgrade Plan ⚡',
                                style: TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.bold,
                                  color: Color(0xFF1E40AF),
                                  decoration: TextDecoration.underline,
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // Shift Volumes Grid
              const Text('Today Delivery Volumes:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: Card(
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Column(
                          children: [
                            const Text('🌅 Morning Shift', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Color(0xFFD97706))),
                            const SizedBox(height: 6),
                            Text('${(morningStats['total_liters'] as num?)?.toDouble() ?? 0.0} L', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900)),
                            Text('${morningStats['delivered'] ?? 0} houses delivered', style: const TextStyle(fontSize: 10, color: Colors.black54)),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Card(
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Column(
                          children: [
                            const Text('🌆 Evening Shift', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Color(0xFF4F46E5))),
                            const SizedBox(height: 6),
                            Text('${(eveningStats['total_liters'] as num?)?.toDouble() ?? 0.0} L', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900)),
                            Text('${eveningStats['delivered'] ?? 0} houses delivered', style: const TextStyle(fontSize: 10, color: Colors.black54)),
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),

              // Month Financials Card
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Month-to-Date Financials', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                      const SizedBox(height: 12),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('Total Billed:'),
                          Text('₹${(billing['total_billed'] as num?)?.toDouble().toStringAsFixed(2) ?? '0.00'}', style: const TextStyle(fontWeight: FontWeight.bold)),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('Total Collected:'),
                          Text('₹${(billing['total_collected'] as num?)?.toDouble().toStringAsFixed(2) ?? '0.00'}', style: const TextStyle(fontWeight: FontWeight.bold, color: Color(0xFF16A34A))),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('Pending Balance:'),
                          Text('₹${(billing['pending_balance'] as num?)?.toDouble().toStringAsFixed(2) ?? '0.00'}', style: const TextStyle(fontWeight: FontWeight.w900, color: Color(0xFFDC2626))),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 20),

              // Dairy Administration & Catalog Hub
              const Text('Dairy Administration & Operations:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              const SizedBox(height: 10),

              Row(
                children: [
                  Expanded(
                    child: Card(
                      child: InkWell(
                        onTap: () => Navigator.push(
                          context,
                          MaterialPageRoute(builder: (_) => const ProductListScreen()),
                        ),
                        borderRadius: BorderRadius.circular(12),
                        child: const Padding(
                          padding: EdgeInsets.all(14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              CircleAvatar(
                                backgroundColor: Color(0xFFE0F2FE),
                                foregroundColor: Color(0xFF0284C7),
                                radius: 18,
                                child: Icon(Icons.inventory_2_outlined, size: 20),
                              ),
                              SizedBox(height: 10),
                              Text('Products Catalog', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                              SizedBox(height: 2),
                              Text('Rates & milk types', style: TextStyle(fontSize: 11, color: Colors.black54)),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Card(
                      child: InkWell(
                        onTap: () => Navigator.push(
                          context,
                          MaterialPageRoute(builder: (_) => const ChargesScreen()),
                        ),
                        borderRadius: BorderRadius.circular(12),
                        child: const Padding(
                          padding: EdgeInsets.all(14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              CircleAvatar(
                                backgroundColor: Color(0xFFFEF3C7),
                                foregroundColor: Color(0xFFD97706),
                                radius: 18,
                                child: Icon(Icons.local_offer_outlined, size: 20),
                              ),
                              SizedBox(height: 10),
                              Text('Surcharges & Fees', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                              SizedBox(height: 2),
                              Text('Delivery & bottles', style: TextStyle(fontSize: 11, color: Colors.black54)),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),

              Row(
                children: [
                  Expanded(
                    child: Card(
                      child: InkWell(
                        onTap: () => Navigator.push(
                          context,
                          MaterialPageRoute(builder: (_) => const RouteListScreen()),
                        ),
                        borderRadius: BorderRadius.circular(12),
                        child: const Padding(
                          padding: EdgeInsets.all(14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              CircleAvatar(
                                backgroundColor: Color(0xFFF3E8FF),
                                foregroundColor: Color(0xFF7E22CE),
                                radius: 18,
                                child: Icon(Icons.alt_route, size: 20),
                              ),
                              SizedBox(height: 10),
                              Text('Delivery Routes', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                              SizedBox(height: 2),
                              Text('Zones & distribution', style: TextStyle(fontSize: 11, color: Colors.black54)),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Card(
                      child: InkWell(
                        onTap: () => Navigator.push(
                          context,
                          MaterialPageRoute(builder: (_) => const SettingsScreen()),
                        ),
                        borderRadius: BorderRadius.circular(12),
                        child: const Padding(
                          padding: EdgeInsets.all(14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              CircleAvatar(
                                backgroundColor: Color(0xFFF1F5F9),
                                foregroundColor: Color(0xFF475569),
                                radius: 18,
                                child: Icon(Icons.storefront_outlined, size: 20),
                              ),
                              SizedBox(height: 10),
                              Text('Dairy & Tax Settings', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                              SizedBox(height: 2),
                              Text('GST & UPI payments', style: TextStyle(fontSize: 11, color: Colors.black54)),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),

              Row(
                children: [
                  Expanded(
                    child: Card(
                      child: InkWell(
                        onTap: () async {
                          await Navigator.push(
                            context,
                            MaterialPageRoute(builder: (_) => const SubscriptionScreen()),
                          );
                          _loadDashboard();
                        },
                        borderRadius: BorderRadius.circular(12),
                        child: const Padding(
                          padding: EdgeInsets.all(14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              CircleAvatar(
                                backgroundColor: Color(0xFFDCFCE7),
                                foregroundColor: Color(0xFF15803D),
                                radius: 18,
                                child: Icon(Icons.flash_on, size: 20),
                              ),
                              SizedBox(height: 10),
                              Text('Plan & Quota', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                              SizedBox(height: 2),
                              Text('Upgrade capacity', style: TextStyle(fontSize: 11, color: Colors.black54)),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Card(
                      child: InkWell(
                        onTap: () => Navigator.push(
                          context,
                          MaterialPageRoute(builder: (_) => const StaffListScreen()),
                        ),
                        borderRadius: BorderRadius.circular(12),
                        child: const Padding(
                          padding: EdgeInsets.all(14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              CircleAvatar(
                                backgroundColor: Color(0xFFFEF2F2),
                                foregroundColor: Color(0xFFDC2626),
                                radius: 18,
                                child: Icon(Icons.badge_outlined, size: 20),
                              ),
                              SizedBox(height: 10),
                              Text('Staff & Riders', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                              SizedBox(height: 2),
                              Text('Manage delivery team', style: TextStyle(fontSize: 11, color: Colors.black54)),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 24),

              OutlinedButton.icon(
                onPressed: _handleLogout,
                icon: const Icon(Icons.logout, color: Colors.red),
                label: const Text('Sign Out', style: TextStyle(color: Colors.red, fontWeight: FontWeight.bold)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
