import 'package:flutter/material.dart';
import '../core/api/api_client.dart';
import '../core/constants/api_constants.dart';
import '../models/product.dart';

class ProductListScreen extends StatefulWidget {
  const ProductListScreen({super.key});

  @override
  State<ProductListScreen> createState() => _ProductListScreenState();
}

class _ProductListScreenState extends State<ProductListScreen> {
  final ApiClient _api = ApiClient();
  List<Product> _products = [];
  bool _isLoading = true;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _loadProducts();
  }

  Future<void> _loadProducts() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    final res = await _api.get(ApiConstants.products);

    if (res.success && res.data != null) {
      final list = (res.data as List).map((m) => Product.fromJson(m)).toList();
      setState(() {
        _products = list;
        _isLoading = false;
      });
    } else {
      setState(() {
        _errorMessage = res.error ?? 'Failed to load products';
        _isLoading = false;
      });
    }
  }

  Future<void> _toggleProduct(Product product) async {
    final res = await _api.post('${ApiConstants.products}/${product.id}/toggle');
    if (res.success) {
      _loadProducts();
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res.error ?? 'Failed to toggle product status')),
        );
      }
    }
  }

  Future<void> _deleteProduct(Product product) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete Product'),
        content: Text('Are you sure you want to delete "${product.name}"? This cannot be undone.'),
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
      final res = await _api.delete('${ApiConstants.products}/${product.id}');
      if (res.success) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Product deleted successfully')),
          );
        }
        _loadProducts();
      } else {
        if (mounted) {
          showDialog(
            context: context,
            builder: (ctx) => AlertDialog(
              title: const Text('Cannot Delete Product'),
              content: Text(res.error ?? 'Unable to delete product.'),
              actions: [
                TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK')),
              ],
            ),
          );
        }
      }
    }
  }

  void _showAddEditDialog({Product? product}) {
    final isEdit = product != null;
    final nameCtrl = TextEditingController(text: product?.name ?? '');
    final codeCtrl = TextEditingController(text: product?.code ?? '');
    final rateCtrl = TextEditingController(text: product != null ? product.defaultRate.toString() : '');
    String unit = product?.unit ?? 'L';
    String? dialogError;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: Text(isEdit ? 'Edit Product' : 'Add New Product'),
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
                    labelText: 'Product Name *',
                    hintText: 'e.g. Cow Milk, Paneer, Curd',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                if (!isEdit) ...[
                  TextField(
                    controller: codeCtrl,
                    decoration: const InputDecoration(
                      labelText: 'Product Code (Optional)',
                      hintText: 'e.g. cow, buffalo, paneer',
                      border: OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 12),
                ],
                Row(
                  children: [
                    Expanded(
                      flex: 3,
                      child: TextField(
                        controller: rateCtrl,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: const InputDecoration(
                          labelText: 'Default Rate (₹) *',
                          hintText: '60.00',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      flex: 2,
                      child: DropdownButtonFormField<String>(
                        initialValue: unit,
                        decoration: const InputDecoration(
                          labelText: 'Unit',
                          border: OutlineInputBorder(),
                        ),
                        items: const [
                          DropdownMenuItem(value: 'L', child: Text('Liter (L)')),
                          DropdownMenuItem(value: 'kg', child: Text('Kg')),
                          DropdownMenuItem(value: 'pkt', child: Text('Packet')),
                          DropdownMenuItem(value: 'unit', child: Text('Unit')),
                        ],
                        onChanged: (val) => setDialogState(() => unit = val ?? 'L'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
            ElevatedButton(
              onPressed: () async {
                final name = nameCtrl.text.trim();
                final code = codeCtrl.text.trim();
                final rate = double.tryParse(rateCtrl.text) ?? 0.0;

                if (name.isEmpty) {
                  setDialogState(() => dialogError = 'Product name is required.');
                  return;
                }
                if (rate <= 0) {
                  setDialogState(() => dialogError = 'Rate must be greater than 0.');
                  return;
                }

                ApiResponse res;
                if (isEdit) {
                  res = await _api.put('${ApiConstants.products}/${product.id}', data: {
                    'name': name,
                    'unit': unit,
                    'default_rate': rate,
                    'sort_order': product.sortOrder,
                  });
                } else {
                  res = await _api.post(ApiConstants.products, data: {
                    'name': name,
                    'code': code.isEmpty ? name.toLowerCase().replaceAll(RegExp(r'[^a-z0-9]'), '') : code,
                    'unit': unit,
                    'default_rate': rate,
                  });
                }

                if (res.success) {
                  if (ctx.mounted) Navigator.pop(ctx);
                  _loadProducts();
                } else {
                  setDialogState(() => dialogError = res.error ?? 'Failed to save product');
                }
              },
              child: Text(isEdit ? 'Update' : 'Add Product'),
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
        title: const Text('📦 Product Catalog'),
        actions: [
          IconButton(
            icon: const Icon(Icons.add),
            tooltip: 'Add Product',
            onPressed: () => _showAddEditDialog(),
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
                      ElevatedButton(onPressed: _loadProducts, child: const Text('Retry')),
                    ],
                  ),
                )
              : _products.isEmpty
                  ? Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const Icon(Icons.inventory_2_outlined, size: 48, color: Colors.grey),
                          const SizedBox(height: 12),
                          const Text('No products configured yet.'),
                          const SizedBox(height: 12),
                          ElevatedButton.icon(
                            onPressed: () => _showAddEditDialog(),
                            icon: const Icon(Icons.add),
                            label: const Text('Add First Product'),
                          ),
                        ],
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _loadProducts,
                      child: ListView.builder(
                        padding: const EdgeInsets.all(12),
                        itemCount: _products.length,
                        itemBuilder: (ctx, idx) {
                          final p = _products[idx];
                          final isDefault = ['cow', 'buffalo'].contains(p.code);

                          return Card(
                            margin: const EdgeInsets.symmetric(vertical: 6),
                            child: ListTile(
                              leading: CircleAvatar(
                                backgroundColor: p.isActive ? const Color(0xFFE0F2FE) : const Color(0xFFF1F5F9),
                                foregroundColor: p.isActive ? const Color(0xFF0284C7) : Colors.grey,
                                child: Text(p.name.isNotEmpty ? p.name[0].toUpperCase() : 'P'),
                              ),
                              title: Row(
                                children: [
                                  Expanded(
                                    child: Text(
                                      p.name,
                                      style: TextStyle(
                                        fontWeight: FontWeight.bold,
                                        decoration: p.isActive ? null : TextDecoration.lineThrough,
                                        color: p.isActive ? null : Colors.grey,
                                      ),
                                    ),
                                  ),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                    decoration: BoxDecoration(
                                      color: p.isActive ? const Color(0xFFDCFCE7) : const Color(0xFFF1F5F9),
                                      borderRadius: BorderRadius.circular(12),
                                    ),
                                    child: Text(
                                      p.isActive ? 'Active' : 'Inactive',
                                      style: TextStyle(
                                        fontSize: 10,
                                        fontWeight: FontWeight.bold,
                                        color: p.isActive ? const Color(0xFF15803D) : Colors.grey,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                              subtitle: Padding(
                                padding: const EdgeInsets.only(top: 4),
                                child: Text(
                                  '₹${p.defaultRate.toStringAsFixed(2)} / ${p.unit} • Code: ${p.code}',
                                  style: const TextStyle(fontWeight: FontWeight.w600, color: Color(0xFF0284C7)),
                                ),
                              ),
                              trailing: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Switch(
                                    value: p.isActive,
                                    onChanged: (_) => _toggleProduct(p),
                                  ),
                                  PopupMenuButton<String>(
                                    onSelected: (val) {
                                      if (val == 'edit') {
                                        _showAddEditDialog(product: p);
                                      } else if (val == 'delete') {
                                        _deleteProduct(p);
                                      }
                                    },
                                    itemBuilder: (ctx) => [
                                      const PopupMenuItem(value: 'edit', child: Text('✏️ Edit Rate')),
                                      if (!isDefault)
                                        const PopupMenuItem(
                                          value: 'delete',
                                          child: Text('🗑️ Delete', style: TextStyle(color: Colors.red)),
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
    );
  }
}
