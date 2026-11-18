class Product {
  final int id;
  final String name;
  final String code;
  final String unit;
  final double defaultRate;
  final int sortOrder;
  final bool isActive;

  Product({
    required this.id,
    required this.name,
    required this.code,
    required this.unit,
    required this.defaultRate,
    this.sortOrder = 0,
    required this.isActive,
  });

  factory Product.fromJson(Map<String, dynamic> json) {
    return Product(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      name: json['name'] ?? '',
      code: json['code'] ?? '',
      unit: json['unit'] ?? 'L',
      defaultRate: (json['default_rate'] as num?)?.toDouble() ?? 0.0,
      sortOrder: (json['sort_order'] as num?)?.toInt() ?? 0,
      isActive: json['is_active'] == 1 || json['is_active'] == true,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'code': code,
      'unit': unit,
      'default_rate': defaultRate,
      'sort_order': sortOrder,
      'is_active': isActive ? 1 : 0,
    };
  }

  Product copyWith({
    int? id,
    String? name,
    String? code,
    String? unit,
    double? defaultRate,
    int? sortOrder,
    bool? isActive,
  }) {
    return Product(
      id: id ?? this.id,
      name: name ?? this.name,
      code: code ?? this.code,
      unit: unit ?? this.unit,
      defaultRate: defaultRate ?? this.defaultRate,
      sortOrder: sortOrder ?? this.sortOrder,
      isActive: isActive ?? this.isActive,
    );
  }
}
