import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../core/api/api_client.dart';
import '../core/constants/api_constants.dart';
import '../models/delivery_route.dart';
import '../models/staff_member.dart';

class StaffListScreen extends StatefulWidget {
  const StaffListScreen({super.key});

  @override
  State<StaffListScreen> createState() => _StaffListScreenState();
}

class _StaffListScreenState extends State<StaffListScreen> {
  final ApiClient _api = ApiClient();
  List<StaffMember> _staff = [];
  List<DeliveryRoute> _routes = [];
  bool _isLoading = false;
  String _searchQuery = '';
  String _roleFilter = 'all'; // 'all', 'delivery', 'admin'

  final TextEditingController _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadStaffAndRoutes();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _loadStaffAndRoutes() async {
    setState(() => _isLoading = true);

    final staffRes = await _api.get(ApiConstants.staff);
    final routeRes = await _api.get(ApiConstants.deliveryRoutes);

    if (staffRes.success && staffRes.data != null) {
      final list = (staffRes.data as List).map((m) => StaffMember.fromJson(m)).toList();
      setState(() => _staff = list);
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(staffRes.error ?? 'Failed to load staff roster'),
            backgroundColor: const Color(0xFFDC2626),
          ),
        );
      }
    }

    if (routeRes.success && routeRes.data != null) {
      final routes = (routeRes.data as List).map((m) => DeliveryRoute.fromJson(m)).toList();
      setState(() => _routes = routes);
    }

    setState(() => _isLoading = false);
  }

  Future<void> _toggleStaff(StaffMember member) async {
    final res = await _api.post('${ApiConstants.staff}/${member.id}/toggle');
    if (res.success) {
      _loadStaffAndRoutes();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('${member.name} status updated to ${member.isActive ? "Inactive" : "Active"}'),
            duration: const Duration(seconds: 2),
          ),
        );
      }
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res.error ?? 'Failed to update staff status'),
            backgroundColor: const Color(0xFFDC2626),
          ),
        );
      }
    }
  }

  Future<void> _deleteStaff(StaffMember member) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete Staff Member'),
        content: Text('Are you sure you want to remove "${member.name}"? They will no longer be able to log in to the mobile app.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFDC2626)),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Delete', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    final res = await _api.delete('${ApiConstants.staff}/${member.id}');
    if (res.success) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Staff member "${member.name}" removed successfully.')),
        );
      }
      _loadStaffAndRoutes();
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res.error ?? 'Failed to delete staff member'),
            backgroundColor: const Color(0xFFDC2626),
          ),
        );
      }
    }
  }

  Future<void> _callPhone(String phone) async {
    final cleanPhone = phone.replaceAll(RegExp(r'[^0-9]'), '');
    final uri = Uri.parse('tel:$cleanPhone');
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri);
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not dial $phone')),
        );
      }
    }
  }

  void _showStaffFormDialog({StaffMember? staff}) {
    final isEdit = staff != null;
    final nameCtrl = TextEditingController(text: staff?.name ?? '');
    final phoneCtrl = TextEditingController(text: staff?.phone ?? '');
    final emailCtrl = TextEditingController(text: staff?.email ?? '');
    final passwordCtrl = TextEditingController();

    String selectedRole = staff?.role ?? 'delivery';
    int? selectedRouteId = staff?.assignedRouteId;
    bool obscurePassword = true;
    String? localError;

    showDialog(
      context: context,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (dialogCtx, setDialogState) {
            return AlertDialog(
              title: Text(isEdit ? 'Edit Staff Member' : 'Add New Staff / Rider'),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (localError != null) ...[
                      Container(
                        padding: const EdgeInsets.all(8),
                        margin: const EdgeInsets.only(bottom: 12),
                        decoration: BoxDecoration(
                          color: const Color(0xFFFEE2E2),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          localError!,
                          style: const TextStyle(color: Color(0xFF991B1B), fontSize: 12),
                        ),
                      ),
                    ],
                    // Full Name
                    TextField(
                      controller: nameCtrl,
                      decoration: const InputDecoration(
                        labelText: 'Full Name *',
                        hintText: 'e.g. Ramesh Kumar',
                        border: OutlineInputBorder(),
                        prefixIcon: Icon(Icons.person_outline),
                      ),
                    ),
                    const SizedBox(height: 12),

                    // Phone Number
                    TextField(
                      controller: phoneCtrl,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(
                        labelText: 'Mobile Phone Number *',
                        hintText: '10-digit mobile number',
                        border: OutlineInputBorder(),
                        prefixIcon: Icon(Icons.phone_outlined),
                      ),
                    ),
                    const SizedBox(height: 12),

                    // Password
                    TextField(
                      controller: passwordCtrl,
                      obscureText: obscurePassword,
                      decoration: InputDecoration(
                        labelText: isEdit ? 'Change Password (Optional)' : 'Login Password *',
                        hintText: isEdit ? 'Leave blank to keep unchanged' : 'Min 4 characters',
                        border: const OutlineInputBorder(),
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          icon: Icon(obscurePassword ? Icons.visibility_off : Icons.visibility),
                          onPressed: () {
                            setDialogState(() => obscurePassword = !obscurePassword);
                          },
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),

                    // Role Selector
                    const Text('Role & Permissions:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                    const SizedBox(height: 6),
                    Row(
                      children: [
                        Expanded(
                          child: InkWell(
                            onTap: () => setDialogState(() => selectedRole = 'delivery'),
                            borderRadius: BorderRadius.circular(8),
                            child: Container(
                              padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
                              decoration: BoxDecoration(
                                color: selectedRole == 'delivery' ? const Color(0xFFE0F2FE) : const Color(0xFFF1F5F9),
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(
                                  color: selectedRole == 'delivery' ? const Color(0xFF0284C7) : Colors.transparent,
                                  width: 1.5,
                                ),
                              ),
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(
                                    Icons.two_wheeler,
                                    size: 18,
                                    color: selectedRole == 'delivery' ? const Color(0xFF0284C7) : Colors.black54,
                                  ),
                                  const SizedBox(width: 6),
                                  Text(
                                    'Delivery Rider',
                                    style: TextStyle(
                                      fontSize: 12,
                                      fontWeight: FontWeight.bold,
                                      color: selectedRole == 'delivery' ? const Color(0xFF0284C7) : Colors.black54,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: InkWell(
                            onTap: () => setDialogState(() => selectedRole = 'admin'),
                            borderRadius: BorderRadius.circular(8),
                            child: Container(
                              padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
                              decoration: BoxDecoration(
                                color: selectedRole == 'admin' ? const Color(0xFFFEF3C7) : const Color(0xFFF1F5F9),
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(
                                  color: selectedRole == 'admin' ? const Color(0xFFD97706) : Colors.transparent,
                                  width: 1.5,
                                ),
                              ),
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(
                                    Icons.admin_panel_settings_outlined,
                                    size: 18,
                                    color: selectedRole == 'admin' ? const Color(0xFFD97706) : Colors.black54,
                                  ),
                                  const SizedBox(width: 6),
                                  Text(
                                    'Tenant Admin',
                                    style: TextStyle(
                                      fontSize: 12,
                                      fontWeight: FontWeight.bold,
                                      color: selectedRole == 'admin' ? const Color(0xFFD97706) : Colors.black54,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),

                    // Assigned Route Selector
                    DropdownButtonFormField<int?>(
                      initialValue: selectedRouteId,
                      decoration: const InputDecoration(
                        labelText: 'Assigned Route',
                        border: OutlineInputBorder(),
                        prefixIcon: Icon(Icons.alt_route),
                      ),
                      items: [
                        const DropdownMenuItem<int?>(
                          value: null,
                          child: Text('All Routes / Unassigned'),
                        ),
                        ..._routes.map(
                          (r) => DropdownMenuItem<int?>(
                            value: r.id,
                            child: Text(r.name),
                          ),
                        ),
                      ],
                      onChanged: (val) {
                        setDialogState(() => selectedRouteId = val);
                      },
                    ),
                    const SizedBox(height: 12),

                    // Email (Optional)
                    TextField(
                      controller: emailCtrl,
                      keyboardType: TextInputType.emailAddress,
                      decoration: const InputDecoration(
                        labelText: 'Email Address (Optional)',
                        hintText: 'e.g. rider@doodhbee.com',
                        border: OutlineInputBorder(),
                        prefixIcon: Icon(Icons.email_outlined),
                      ),
                    ),
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: const Text('Cancel'),
                ),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF0284C7)),
                  onPressed: () async {
                    final name = nameCtrl.text.trim();
                    final phone = phoneCtrl.text.trim();
                    final password = passwordCtrl.text;
                    final email = emailCtrl.text.trim();

                    if (name.isEmpty) {
                      setDialogState(() => localError = 'Full name is required.');
                      return;
                    }
                    if (phone.isEmpty || phone.replaceAll(RegExp(r'[^0-9]'), '').length < 10) {
                      setDialogState(() => localError = 'A valid 10-digit mobile phone number is required.');
                      return;
                    }
                    if (!isEdit && password.length < 4) {
                      setDialogState(() => localError = 'Password must be at least 4 characters long.');
                      return;
                    }
                    if (isEdit && password.isNotEmpty && password.length < 4) {
                      setDialogState(() => localError = 'New password must be at least 4 characters long.');
                      return;
                    }

                    final payload = <String, dynamic>{
                      'name': name,
                      'phone': phone,
                      'role': selectedRole,
                      'assigned_route_id': selectedRouteId,
                      'email': email.isNotEmpty ? email : null,
                    };
                    if (password.isNotEmpty) {
                      payload['password'] = password;
                    }

                    ApiResponse res;
                    if (isEdit) {
                      res = await _api.put('${ApiConstants.staff}/${staff.id}', data: payload);
                    } else {
                      res = await _api.post(ApiConstants.staff, data: payload);
                    }

                    if (res.success) {
                      if (ctx.mounted) {
                        Navigator.pop(ctx);
                      }
                      _loadStaffAndRoutes();
                      if (mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text(isEdit ? 'Staff member updated successfully' : 'New staff member added successfully'),
                          ),
                        );
                      }
                    } else {
                      setDialogState(() => localError = res.error ?? 'Failed to save staff member.');
                    }
                  },
                  child: Text(isEdit ? 'Update' : 'Add Rider', style: const TextStyle(color: Colors.white)),
                ),
              ],
            );
          },
        );
      },
    );
  }

  List<StaffMember> get _filteredStaff {
    return _staff.where((s) {
      if (_roleFilter != 'all' && s.role != _roleFilter) {
        return false;
      }
      if (_searchQuery.isNotEmpty) {
        final q = _searchQuery.toLowerCase();
        final nameMatch = s.name.toLowerCase().contains(q);
        final phoneMatch = s.phone.contains(q);
        final routeMatch = (s.assignedRouteName ?? '').toLowerCase().contains(q);
        if (!nameMatch && !phoneMatch && !routeMatch) {
          return false;
        }
      }
      return true;
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    final filtered = _filteredStaff;
    final deliveryCount = _staff.where((s) => s.isDelivery).length;
    final adminCount = _staff.where((s) => s.isAdmin).length;

    return Scaffold(
      appBar: AppBar(
        title: const Text('👥 Staff & Delivery Riders'),
        actions: [
          IconButton(
            icon: const Icon(Icons.person_add_alt_1),
            tooltip: 'Add Rider / Staff',
            onPressed: () => _showStaffFormDialog(),
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh',
            onPressed: _loadStaffAndRoutes,
          ),
        ],
      ),
      body: Column(
        children: [
          // Search & Filter Header
          Container(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            color: Colors.white,
            child: Column(
              children: [
                // Search TextField
                TextField(
                  controller: _searchController,
                  decoration: InputDecoration(
                    hintText: 'Search by name, phone or route...',
                    prefixIcon: const Icon(Icons.search, size: 20),
                    suffixIcon: _searchQuery.isNotEmpty
                        ? IconButton(
                            icon: const Icon(Icons.clear, size: 18),
                            onPressed: () {
                              _searchController.clear();
                              setState(() => _searchQuery = '');
                            },
                          )
                        : null,
                    contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                  ),
                  onChanged: (val) => setState(() => _searchQuery = val.trim()),
                ),
                const SizedBox(height: 10),

                // Role Filter Chips
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: [
                      FilterChip(
                        label: Text('All (${_staff.length})'),
                        selected: _roleFilter == 'all',
                        onSelected: (_) => setState(() => _roleFilter = 'all'),
                        selectedColor: const Color(0xFFE0F2FE),
                        checkmarkColor: const Color(0xFF0284C7),
                      ),
                      const SizedBox(width: 8),
                      FilterChip(
                        label: Text('🛵 Riders ($deliveryCount)'),
                        selected: _roleFilter == 'delivery',
                        onSelected: (_) => setState(() => _roleFilter = 'delivery'),
                        selectedColor: const Color(0xFFE0F2FE),
                        checkmarkColor: const Color(0xFF0284C7),
                      ),
                      const SizedBox(width: 8),
                      FilterChip(
                        label: Text('🛡️ Admins ($adminCount)'),
                        selected: _roleFilter == 'admin',
                        onSelected: (_) => setState(() => _roleFilter = 'admin'),
                        selectedColor: const Color(0xFFFEF3C7),
                        checkmarkColor: const Color(0xFFD97706),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const Divider(height: 1),

          // Main List or Loading
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : filtered.isEmpty
                    ? Center(
                        child: SingleChildScrollView(
                          padding: const EdgeInsets.all(24),
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              const CircleAvatar(
                                radius: 36,
                                backgroundColor: Color(0xFFF1F5F9),
                                child: Icon(Icons.people_outline, size: 40, color: Colors.grey),
                              ),
                              const SizedBox(height: 16),
                              Text(
                                _staff.isEmpty ? 'No staff or delivery riders registered yet.' : 'No staff found matching filters.',
                                style: const TextStyle(fontSize: 15, color: Colors.black54, fontWeight: FontWeight.w600),
                                textAlign: TextAlign.center,
                              ),
                              const SizedBox(height: 8),
                              const Text(
                                'Add your delivery riders here. They can log in to the mobile app with their phone and password to manage their daily delivery runsheet.',
                                style: TextStyle(fontSize: 12, color: Colors.black45),
                                textAlign: TextAlign.center,
                              ),
                              const SizedBox(height: 20),
                              ElevatedButton.icon(
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: const Color(0xFF0284C7),
                                  padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                                ),
                                onPressed: () => _showStaffFormDialog(),
                                icon: const Icon(Icons.add, color: Colors.white),
                                label: const Text('Add First Delivery Rider', style: TextStyle(color: Colors.white)),
                              ),
                            ],
                          ),
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: _loadStaffAndRoutes,
                        child: ListView.builder(
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                          itemCount: filtered.length,
                          itemBuilder: (context, index) {
                            final member = filtered[index];
                            final isRider = member.isDelivery;

                            return Card(
                              margin: const EdgeInsets.only(bottom: 12),
                              elevation: 1.5,
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                              child: Padding(
                                padding: const EdgeInsets.all(14),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    // Row 1: Avatar, Name & Phone, Active Switch
                                    Row(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        CircleAvatar(
                                          backgroundColor: isRider ? const Color(0xFFE0F2FE) : const Color(0xFFFEF3C7),
                                          foregroundColor: isRider ? const Color(0xFF0284C7) : const Color(0xFFD97706),
                                          radius: 22,
                                          child: Icon(
                                            isRider ? Icons.two_wheeler : Icons.admin_panel_settings,
                                            size: 24,
                                          ),
                                        ),
                                        const SizedBox(width: 12),
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            children: [
                                              Text(
                                                member.name,
                                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                                              ),
                                              const SizedBox(height: 2),
                                              InkWell(
                                                onTap: () => _callPhone(member.phone),
                                                child: Row(
                                                  mainAxisSize: MainAxisSize.min,
                                                  children: [
                                                    const Icon(Icons.phone, size: 13, color: Color(0xFF0284C7)),
                                                    const SizedBox(width: 4),
                                                    Text(
                                                      member.phone,
                                                      style: const TextStyle(
                                                        fontSize: 13,
                                                        color: Color(0xFF0284C7),
                                                        fontWeight: FontWeight.w600,
                                                        decoration: TextDecoration.underline,
                                                      ),
                                                    ),
                                                  ],
                                                ),
                                              ),
                                              if (member.email != null && member.email!.isNotEmpty) ...[
                                                const SizedBox(height: 2),
                                                Text(
                                                  member.email!,
                                                  style: const TextStyle(fontSize: 12, color: Colors.black54),
                                                ),
                                              ],
                                            ],
                                          ),
                                        ),
                                        Column(
                                          crossAxisAlignment: CrossAxisAlignment.end,
                                          children: [
                                            Switch(
                                              value: member.isActive,
                                              activeThumbColor: const Color(0xFF0284C7),
                                              onChanged: (_) => _toggleStaff(member),
                                            ),
                                            Text(
                                              member.isActive ? 'Active' : 'Inactive',
                                              style: TextStyle(
                                                fontSize: 10,
                                                fontWeight: FontWeight.bold,
                                                color: member.isActive ? const Color(0xFF16A34A) : Colors.grey,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ],
                                    ),

                                    const Divider(height: 18),

                                    // Row 2: Badges (Role, Route) and Action Buttons
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        // Badges
                                        Expanded(
                                          child: Wrap(
                                            spacing: 6,
                                            runSpacing: 4,
                                            children: [
                                              // Role Badge
                                              Container(
                                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                                decoration: BoxDecoration(
                                                  color: isRider ? const Color(0xFFE0F2FE) : const Color(0xFFFEF3C7),
                                                  borderRadius: BorderRadius.circular(6),
                                                ),
                                                child: Text(
                                                  isRider ? '🛵 Delivery Rider' : '🛡️ Tenant Admin',
                                                  style: TextStyle(
                                                    fontSize: 11,
                                                    fontWeight: FontWeight.bold,
                                                    color: isRider ? const Color(0xFF0284C7) : const Color(0xFFB45309),
                                                  ),
                                                ),
                                              ),

                                              // Route Badge
                                              Container(
                                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                                decoration: BoxDecoration(
                                                  color: const Color(0xFFF3E8FF),
                                                  borderRadius: BorderRadius.circular(6),
                                                ),
                                                child: Row(
                                                  mainAxisSize: MainAxisSize.min,
                                                  children: [
                                                    const Icon(Icons.alt_route, size: 12, color: Color(0xFF7E22CE)),
                                                    const SizedBox(width: 4),
                                                    Text(
                                                      member.assignedRouteName != null
                                                          ? 'Route: ${member.assignedRouteName}'
                                                          : 'All Routes',
                                                      style: const TextStyle(
                                                        fontSize: 11,
                                                        fontWeight: FontWeight.w600,
                                                        color: Color(0xFF7E22CE),
                                                      ),
                                                    ),
                                                  ],
                                                ),
                                              ),
                                            ],
                                          ),
                                        ),

                                        // Actions
                                        Row(
                                          mainAxisSize: MainAxisSize.min,
                                          children: [
                                            IconButton(
                                              icon: const Icon(Icons.phone_outlined, size: 20, color: Color(0xFF0284C7)),
                                              tooltip: 'Call Rider',
                                              onPressed: () => _callPhone(member.phone),
                                            ),
                                            IconButton(
                                              icon: const Icon(Icons.edit_outlined, size: 20, color: Colors.black54),
                                              tooltip: 'Edit Staff Member',
                                              onPressed: () => _showStaffFormDialog(staff: member),
                                            ),
                                            IconButton(
                                              icon: const Icon(Icons.delete_outline, size: 20, color: Color(0xFFDC2626)),
                                              tooltip: 'Delete Staff Member',
                                              onPressed: () => _deleteStaff(member),
                                            ),
                                          ],
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
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: const Color(0xFF0284C7),
        icon: const Icon(Icons.person_add, color: Colors.white),
        label: const Text('Add Rider', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        onPressed: () => _showStaffFormDialog(),
      ),
    );
  }
}
