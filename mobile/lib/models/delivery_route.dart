class DeliveryRoute {
  final int id;
  final int tenantId;
  final String name;
  final String? description;
  final int sortOrder;
  final bool isActive;
  final int customerCount;
  final int activeCustomerCount;
  final bool isDefault;

  DeliveryRoute({
    required this.id,
    required this.tenantId,
    required this.name,
    this.description,
    this.sortOrder = 0,
    this.isActive = true,
    this.customerCount = 0,
    this.activeCustomerCount = 0,
    this.isDefault = false,
  });

  factory DeliveryRoute.fromJson(Map<String, dynamic> json) {
    return DeliveryRoute(
      id: json['id'] ?? 0,
      tenantId: json['tenant_id'] ?? 0,
      name: json['name'] ?? '',
      description: json['description'],
      sortOrder: json['sort_order'] ?? 0,
      isActive: json['is_active'] == true || json['is_active'] == 1,
      customerCount: (json['customer_count'] as num?)?.toInt() ?? 0,
      activeCustomerCount: (json['active_customer_count'] as num?)?.toInt() ?? 0,
      isDefault: json['is_default'] == true || json['name'] == 'Main Route',
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'name': name,
      'description': description,
      'sort_order': sortOrder,
      'is_active': isActive,
      'is_default': isDefault,
    };
  }
}
