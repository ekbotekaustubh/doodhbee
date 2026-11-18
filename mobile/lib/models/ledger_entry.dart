class LedgerEntry {
  final int id;
  final String type; // 'invoice' or 'payment'
  final String date;
  final String description;
  final double debit;
  final double credit;
  final String status;
  final double balanceAfter;

  LedgerEntry({
    required this.id,
    required this.type,
    required this.date,
    required this.description,
    required this.debit,
    required this.credit,
    required this.status,
    required this.balanceAfter,
  });

  factory LedgerEntry.fromJson(Map<String, dynamic> json) {
    return LedgerEntry(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      type: json['type'] ?? 'invoice',
      date: json['date'] ?? '',
      description: json['description'] ?? '',
      debit: (json['debit'] as num?)?.toDouble() ?? 0.0,
      credit: (json['credit'] as num?)?.toDouble() ?? 0.0,
      status: json['status'] ?? '',
      balanceAfter: (json['balance_after'] as num?)?.toDouble() ?? 0.0,
    );
  }

  bool get isInvoice => type == 'invoice';
  bool get isPayment => type == 'payment';
}
