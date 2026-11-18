import 'package:flutter/material.dart';
import '../core/api/api_client.dart';
import '../core/constants/api_constants.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> with SingleTickerProviderStateMixin {
  final ApiClient _api = ApiClient();
  late TabController _tabController;

  bool _isLoading = true;
  String? _errorMessage;

  // Profile controllers
  final _businessNameCtrl = TextEditingController();
  final _ownerNameCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _invoiceFooterCtrl = TextEditingController();

  // Taxes controllers
  bool _taxEnabled = false;
  final _taxLabelCtrl = TextEditingController(text: 'GST');
  final _taxPercentageCtrl = TextEditingController(text: '5.0');
  final _taxNumberCtrl = TextEditingController();

  // UPI controllers
  final _upiIdCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    _loadSettings();
  }

  @override
  void dispose() {
    _tabController.dispose();
    _businessNameCtrl.dispose();
    _ownerNameCtrl.dispose();
    _phoneCtrl.dispose();
    _emailCtrl.dispose();
    _invoiceFooterCtrl.dispose();
    _taxLabelCtrl.dispose();
    _taxPercentageCtrl.dispose();
    _taxNumberCtrl.dispose();
    _upiIdCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadSettings() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    final res = await _api.get(ApiConstants.settings);

    if (res.success && res.data != null) {
      final profile = res.data['profile'] ?? {};
      final settings = res.data['settings'] ?? {};

      setState(() {
        _businessNameCtrl.text = profile['business_name'] ?? '';
        _ownerNameCtrl.text = profile['owner_name'] ?? '';
        _phoneCtrl.text = profile['phone'] ?? '';
        _emailCtrl.text = profile['email'] ?? '';
        _invoiceFooterCtrl.text = settings['invoice_footer_note'] ?? '';

        _taxEnabled = (settings['tax_enabled'] == 1 || settings['tax_enabled'] == true);
        _taxLabelCtrl.text = settings['tax_label'] ?? 'GST';
        _taxPercentageCtrl.text = ((settings['tax_percentage'] as num?)?.toDouble() ?? 5.0).toString();
        _taxNumberCtrl.text = settings['tax_number'] ?? '';

        _upiIdCtrl.text = settings['upi_id'] ?? '';
        _isLoading = false;
      });
    } else {
      setState(() {
        _errorMessage = res.error ?? 'Failed to load settings';
        _isLoading = false;
      });
    }
  }

  Future<void> _saveProfile() async {
    final businessName = _businessNameCtrl.text.trim();
    final ownerName = _ownerNameCtrl.text.trim();
    final phone = _phoneCtrl.text.trim();

    if (businessName.isEmpty || ownerName.isEmpty || phone.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Business name, owner name, and phone are required.')),
      );
      return;
    }

    final res = await _api.put('${ApiConstants.settings}/profile', data: {
      'business_name': businessName,
      'owner_name': ownerName,
      'phone': phone,
      'email': _emailCtrl.text.trim(),
      'invoice_footer_note': _invoiceFooterCtrl.text.trim(),
    });

    if (!mounted) return;
    if (res.success) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Business profile updated successfully!')),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res.error ?? 'Failed to update profile')),
      );
    }
  }

  Future<void> _saveTaxes() async {
    final taxPct = double.tryParse(_taxPercentageCtrl.text) ?? 0.0;
    final res = await _api.put('${ApiConstants.settings}/taxes', data: {
      'tax_enabled': _taxEnabled ? 1 : 0,
      'tax_label': _taxLabelCtrl.text.trim(),
      'tax_percentage': taxPct,
      'tax_number': _taxNumberCtrl.text.trim(),
    });

    if (!mounted) return;
    if (res.success) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Tax settings updated successfully!')),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res.error ?? 'Failed to update tax settings')),
      );
    }
  }

  Future<void> _saveUpi() async {
    final upiId = _upiIdCtrl.text.trim();
    final res = await _api.put('${ApiConstants.settings}/upi', data: {
      'upi_id': upiId,
    });

    if (!mounted) return;
    if (res.success) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('UPI ID updated successfully!')),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res.error ?? 'Failed to update UPI ID')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('⚙️ Dairy & Tax Settings'),
        bottom: TabBar(
          controller: _tabController,
          labelColor: const Color(0xFF0284C7),
          unselectedLabelColor: Colors.black54,
          indicatorColor: const Color(0xFF0284C7),
          tabs: const [
            Tab(icon: Icon(Icons.storefront_outlined), text: 'Profile'),
            Tab(icon: Icon(Icons.receipt_outlined), text: 'GST / Tax'),
            Tab(icon: Icon(Icons.qr_code_2), text: 'UPI Payments'),
          ],
        ),
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
                      ElevatedButton(onPressed: _loadSettings, child: const Text('Retry')),
                    ],
                  ),
                )
              : TabBarView(
                  controller: _tabController,
                  children: [
                    // Tab 1: Profile
                    SingleChildScrollView(
                      padding: const EdgeInsets.all(20),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          TextField(
                            controller: _businessNameCtrl,
                            decoration: const InputDecoration(labelText: 'Dairy / Farm Name *', border: OutlineInputBorder()),
                          ),
                          const SizedBox(height: 16),
                          TextField(
                            controller: _ownerNameCtrl,
                            decoration: const InputDecoration(labelText: 'Owner / Contact Person *', border: OutlineInputBorder()),
                          ),
                          const SizedBox(height: 16),
                          TextField(
                            controller: _phoneCtrl,
                            keyboardType: TextInputType.phone,
                            decoration: const InputDecoration(labelText: 'Phone Number *', border: OutlineInputBorder()),
                          ),
                          const SizedBox(height: 16),
                          TextField(
                            controller: _emailCtrl,
                            keyboardType: TextInputType.emailAddress,
                            decoration: const InputDecoration(labelText: 'Email Address (Optional)', border: OutlineInputBorder()),
                          ),
                          const SizedBox(height: 16),
                          TextField(
                            controller: _invoiceFooterCtrl,
                            maxLines: 2,
                            decoration: const InputDecoration(
                              labelText: 'Invoice Footer Note',
                              hintText: 'e.g. Pure milk fresh from farm. Thank you for your support!',
                              border: OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 24),
                          ElevatedButton.icon(
                            onPressed: _saveProfile,
                            icon: const Icon(Icons.save),
                            label: const Text('Save Profile Details'),
                            style: ElevatedButton.styleFrom(
                              backgroundColor: const Color(0xFF0284C7),
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 14),
                            ),
                          ),
                        ],
                      ),
                    ),

                    // Tab 2: Taxes
                    SingleChildScrollView(
                      padding: const EdgeInsets.all(20),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Card(
                            child: SwitchListTile(
                              title: const Text('Enable GST / Tax on Invoices', style: TextStyle(fontWeight: FontWeight.bold)),
                              subtitle: const Text('Calculate tax automatically on milk & surcharges'),
                              value: _taxEnabled,
                              onChanged: (val) => setState(() => _taxEnabled = val),
                            ),
                          ),
                          const SizedBox(height: 16),
                          TextField(
                            controller: _taxLabelCtrl,
                            decoration: const InputDecoration(labelText: 'Tax Label', hintText: 'GST or VAT', border: OutlineInputBorder()),
                          ),
                          const SizedBox(height: 16),
                          TextField(
                            controller: _taxPercentageCtrl,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            decoration: const InputDecoration(labelText: 'Tax Percentage (%)', hintText: '5.0', border: OutlineInputBorder()),
                          ),
                          const SizedBox(height: 16),
                          TextField(
                            controller: _taxNumberCtrl,
                            decoration: const InputDecoration(labelText: 'GSTIN / Tax Registration No.', hintText: '27AAAAA0000A1Z5', border: OutlineInputBorder()),
                          ),
                          const SizedBox(height: 24),
                          ElevatedButton.icon(
                            onPressed: _saveTaxes,
                            icon: const Icon(Icons.save),
                            label: const Text('Save Tax Configuration'),
                            style: ElevatedButton.styleFrom(
                              backgroundColor: const Color(0xFF0284C7),
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 14),
                            ),
                          ),
                        ],
                      ),
                    ),

                    // Tab 3: UPI
                    SingleChildScrollView(
                      padding: const EdgeInsets.all(20),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          const Card(
                            color: Color(0xFFEFF6FF),
                            child: Padding(
                              padding: EdgeInsets.all(16),
                              child: Row(
                                children: [
                                  Icon(Icons.qr_code_2, color: Color(0xFF1E40AF), size: 36),
                                  SizedBox(width: 14),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text('Automated UPI Collections', style: TextStyle(fontWeight: FontWeight.bold, color: Color(0xFF1E40AF))),
                                        SizedBox(height: 4),
                                        Text(
                                          'Setting your Virtual Payment Address (VPA) enables dynamic QR codes directly on invoices & WhatsApp bills.',
                                          style: TextStyle(fontSize: 12, color: Color(0xFF1E40AF)),
                                        ),
                                      ],
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                          const SizedBox(height: 20),
                          TextField(
                            controller: _upiIdCtrl,
                            decoration: const InputDecoration(
                              labelText: 'UPI ID / VPA *',
                              hintText: 'e.g. krushnadairy@oksbi or 9822000001@paytm',
                              border: OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 24),
                          ElevatedButton.icon(
                            onPressed: _saveUpi,
                            icon: const Icon(Icons.save),
                            label: const Text('Save UPI ID'),
                            style: ElevatedButton.styleFrom(
                              backgroundColor: const Color(0xFF0284C7),
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 14),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
    );
  }
}
