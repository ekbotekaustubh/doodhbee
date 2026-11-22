class StaffMember {
  final int id;
  final int tenantId;
  final String name;
  final String phone;
  final String? email;
  final String role; // 'admin' or 'delivery'
  final int? assignedRouteId;
  final String? assignedRouteName;
  final bool isActive;
  final String? createdAt;
  final String? updatedAt;

  StaffMember({
    required this.id,
    required this.tenantId,
    required this.name,
    required this.phone,
    this.email,
    this.role = 'delivery',
    this.assignedRouteId,
    this.assignedRouteName,
    this.isActive = true,
    this.createdAt,
    this.updatedAt,
  });

  bool get isDelivery => role == 'delivery';
  bool get isAdmin => role == 'admin';

  factory StaffMember.fromJson(Map<String, dynamic> json) {
    return StaffMember(
      id: json['id'] is int ? json['id'] : int.tryParse('${json['id']}') ?? 0,
      tenantId: json['tenant_id'] is int ? json['tenant_id'] : int.tryParse('${json['tenant_id']}') ?? 0,
      name: json['name'] ?? '',
      phone: json['phone'] ?? '',
      email: json['email'],
      role: json['role'] ?? 'delivery',
      assignedRouteId: json['assigned_route_id'] != null ? int.tryParse('${json['assigned_route_id']}') : null,
      assignedRouteName: json['assigned_route_name'],
      isActive: json['is_active'] == 1 || json['is_active'] == true,
      createdAt: json['created_at'],
      updatedAt: json['updated_at'],
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'name': name,
      'phone': phone,
      'email': email,
      'role': role,
      'assigned_route_id': assignedRouteId,
      'assigned_route_name': assignedRouteName,
      'is_active': isActive ? 1 : 0,
      'created_at': createdAt,
      'updated_at': updatedAt,
    };
  }
}
