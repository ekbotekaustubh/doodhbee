import 'package:flutter/material.dart';
import '../core/api/api_client.dart';
import '../core/constants/api_constants.dart';
import '../models/delivery_route.dart';

class RouteListScreen extends StatefulWidget {
  const RouteListScreen({super.key});

  @override
  State<RouteListScreen> createState() => _RouteListScreenState();
}

class _RouteListScreenState extends State<RouteListScreen> {
  final ApiClient _api = ApiClient();
  List<DeliveryRoute> _routes = [];
  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
    _loadRoutes();
  }

  Future<void> _loadRoutes() async {
    setState(() => _isLoading = true);

    final res = await _api.get(ApiConstants.deliveryRoutes);

    if (res.success && res.data != null) {
      final list = (res.data as List).map((m) => DeliveryRoute.fromJson(m)).toList();
      setState(() {
        _routes = list;
      });
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res.error ?? 'Failed to load routes')),
        );
      }
    }

    setState(() => _isLoading = false);
  }

  Future<void> _toggleRoute(DeliveryRoute route) async {
    final res = await _api.post('${ApiConstants.deliveryRoutes}/${route.id}/toggle');
    if (res.success) {
      _loadRoutes();
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res.error ?? 'Failed to update route status')),
        );
      }
    }
  }

  Future<void> _deleteRoute(DeliveryRoute route) async {
    if (route.isDefault) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text("The default 'Main Route' cannot be deleted. You can rename or deactivate it instead."),
          backgroundColor: Color(0xFFDC2626),
        ),
      );
      return;
    }

    if (route.customerCount > 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text("Cannot delete '${route.name}': ${route.customerCount} customer(s) are assigned to it. Reassign them first."),
          backgroundColor: const Color(0xFFDC2626),
        ),
      );
      return;
    }

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete Route'),
        content: Text("Are you sure you want to delete the route '${route.name}'?"),
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

    final res = await _api.delete('${ApiConstants.deliveryRoutes}/${route.id}');
    if (res.success) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text("Route '${route.name}' deleted successfully")),
        );
      }
      _loadRoutes();
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res.error ?? 'Failed to delete route'), backgroundColor: const Color(0xFFDC2626)),
        );
      }
    }
  }

  void _showRouteFormDialog({DeliveryRoute? route}) {
    final isEdit = route != null;
    final nameController = TextEditingController(text: route?.name ?? '');
    final descController = TextEditingController(text: route?.description ?? '');
    final sortController = TextEditingController(text: route != null ? '${route.sortOrder}' : '');
    String? localError;

    showDialog(
      context: context,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (dialogCtx, setDialogState) {
            return AlertDialog(
              title: Text(isEdit ? 'Edit Route' : 'Add New Delivery Route'),
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
                    TextField(
                      controller: nameController,
                      decoration: const InputDecoration(
                        labelText: 'Route / Zone Name *',
                        hintText: 'e.g. North Gate Line',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: descController,
                      maxLines: 2,
                      decoration: const InputDecoration(
                        labelText: 'Description (Optional)',
                        hintText: 'e.g. Towers 1 to 5, early morning delivery',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: sortController,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(
                        labelText: 'Sort Order / Sequence',
                        hintText: 'e.g. 1, 2, 3...',
                        border: OutlineInputBorder(),
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
                    final name = nameController.text.trim();
                    if (name.isEmpty) {
                      setDialogState(() => localError = 'Route name is required.');
                      return;
                    }

                    final payload = {
                      'name': name,
                      'description': descController.text.trim(),
                      'sort_order': int.tryParse(sortController.text.trim()) ?? 0,
                    };

                    ApiResponse res;
                    if (isEdit) {
                      res = await _api.put('${ApiConstants.deliveryRoutes}/${route.id}', data: payload);
                    } else {
                      res = await _api.post(ApiConstants.deliveryRoutes, data: payload);
                    }

                    if (res.success) {
                      if (ctx.mounted) {
                        Navigator.pop(ctx);
                      }
                      _loadRoutes();
                      if (mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(content: Text(isEdit ? 'Route updated successfully' : 'Route added successfully')),
                        );
                      }
                    } else {
                      setDialogState(() => localError = res.error ?? 'Failed to save route.');
                    }
                  },
                  child: Text(isEdit ? 'Update' : 'Create Route', style: const TextStyle(color: Colors.white)),
                ),
              ],
            );
          },
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('🗺️ Delivery Routes'),
        actions: [
          IconButton(
            icon: const Icon(Icons.add),
            tooltip: 'Add Route',
            onPressed: () => _showRouteFormDialog(),
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh',
            onPressed: _loadRoutes,
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _routes.isEmpty
              ? Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.alt_route, size: 64, color: Colors.grey),
                      const SizedBox(height: 12),
                      const Text('No routes configured yet.', style: TextStyle(fontSize: 16, color: Colors.black54)),
                      const SizedBox(height: 16),
                      ElevatedButton.icon(
                        onPressed: () => _showRouteFormDialog(),
                        icon: const Icon(Icons.add),
                        label: const Text('Add First Route'),
                      ),
                    ],
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _loadRoutes,
                  child: ListView.builder(
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    itemCount: _routes.length,
                    itemBuilder: (context, index) {
                      final r = _routes[index];
                      return Card(
                        margin: const EdgeInsets.only(bottom: 12),
                        elevation: 1.5,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        child: Padding(
                          padding: const EdgeInsets.all(14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  CircleAvatar(
                                    backgroundColor: r.isDefault ? const Color(0xFFFEF3C7) : const Color(0xFFE0F2FE),
                                    foregroundColor: r.isDefault ? const Color(0xFFD97706) : const Color(0xFF0284C7),
                                    radius: 20,
                                    child: Icon(r.isDefault ? Icons.star : Icons.alt_route, size: 22),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Row(
                                          children: [
                                            Flexible(
                                              child: Text(
                                                r.name,
                                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                                              ),
                                            ),
                                            if (r.isDefault) ...[
                                              const SizedBox(width: 8),
                                              Container(
                                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                                decoration: BoxDecoration(
                                                  color: const Color(0xFFFEF3C7),
                                                  borderRadius: BorderRadius.circular(6),
                                                  border: Border.all(color: const Color(0xFFFDE68A)),
                                                ),
                                                child: const Text(
                                                  'Default',
                                                  style: TextStyle(
                                                    fontSize: 11,
                                                    fontWeight: FontWeight.bold,
                                                    color: Color(0xFFB45309),
                                                  ),
                                                ),
                                              ),
                                            ],
                                          ],
                                        ),
                                        if (r.description != null && r.description!.isNotEmpty) ...[
                                          const SizedBox(height: 4),
                                          Text(
                                            r.description!,
                                            style: const TextStyle(fontSize: 13, color: Colors.black54),
                                          ),
                                        ],
                                      ],
                                    ),
                                  ),
                                  Switch(
                                    value: r.isActive,
                                    activeThumbColor: const Color(0xFF0284C7),
                                    onChanged: (_) => _toggleRoute(r),
                                  ),
                                ],
                              ),
                              const Divider(height: 20),
                              Row(
                                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                children: [
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                    decoration: BoxDecoration(
                                      color: const Color(0xFFF1F5F9),
                                      borderRadius: BorderRadius.circular(6),
                                    ),
                                    child: Row(
                                      children: [
                                        const Icon(Icons.people_outline, size: 14, color: Color(0xFF475569)),
                                        const SizedBox(width: 4),
                                        Text(
                                          '${r.customerCount} customers (${r.activeCustomerCount} active)',
                                          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFF475569)),
                                        ),
                                      ],
                                    ),
                                  ),
                                  Row(
                                    children: [
                                      IconButton(
                                        icon: const Icon(Icons.edit_outlined, size: 20, color: Color(0xFF0284C7)),
                                        tooltip: 'Edit Route',
                                        onPressed: () => _showRouteFormDialog(route: r),
                                      ),
                                      if (!r.isDefault)
                                        IconButton(
                                          icon: Icon(
                                            Icons.delete_outline,
                                            size: 20,
                                            color: r.customerCount > 0 ? Colors.grey : const Color(0xFFDC2626),
                                          ),
                                          tooltip: r.customerCount > 0 ? 'Cannot delete: customers assigned' : 'Delete Route',
                                          onPressed: () => _deleteRoute(r),
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
      floatingActionButton: FloatingActionButton(
        backgroundColor: const Color(0xFF0284C7),
        foregroundColor: Colors.white,
        tooltip: 'Add Route',
        onPressed: () => _showRouteFormDialog(),
        child: const Icon(Icons.add),
      ),
    );
  }
}
