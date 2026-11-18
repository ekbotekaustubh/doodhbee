class AdditionalCharge {
  final int id;
  final String chargeName;
  final String chargeType; // 'flat_monthly', 'per_liter', 'per_delivery_day'
  final double amount;
  final bool isTaxable;
  final bool isActive;

  AdditionalCharge({
    required this.id,
    required this.chargeName,
    required this.chargeType,
    required this.amount,
    required this.isTaxable,
    required this.isActive,
  });

  factory AdditionalCharge.fromJson(Map<String, dynamic> json) {
    return AdditionalCharge(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      chargeName: json['charge_name'] ?? '',
      chargeType: json['charge_type'] ?? 'flat_monthly',
      amount: (json['amount'] as num?)?.toDouble() ?? 0.0,
      isTaxable: json['is_taxable'] == 1 || json['is_taxable'] == true,
      isActive: json['is_active'] == 1 || json['is_active'] == true,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'charge_name': chargeName,
      'charge_type': chargeType,
      'amount': amount,
      'is_taxable': isTaxable ? 1 : 0,
      'is_active': isActive ? 1 : 0,
    };
  }

  String get formattedChargeType {
    switch (chargeType) {
      case 'flat_monthly':
        return 'Flat Monthly';
      case 'per_liter':
        return 'Per Liter';
      case 'per_delivery_day':
        return 'Per Delivery Day';
      default:
        return chargeType;
    }
  }
}
