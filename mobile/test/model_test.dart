import 'package:flutter_test/flutter_test.dart';
import 'package:doodhbee_mobile/models/delivery_item.dart';
import 'package:doodhbee_mobile/models/customer.dart';
import 'package:doodhbee_mobile/models/invoice.dart';
import 'package:doodhbee_mobile/core/theme/app_theme.dart';

void main() {
  group('DeliveryItem Model Tests', () {
    test('Zero-Charge Rule: Skipped or Leave status preserves zero quantity', () {
      final item = DeliveryItem(
        customerId: 10,
        customerName: 'Aarav Sharma',
        customerPhone: '9876543210',
        address: 'Flat 101',
        routeName: 'Sector 4',
        productId: 1,
        productName: 'Cow Milk',
        unit: 'L',
        deliveryDate: '2026-11-07',
        shift: 'morning',
        quantity: 0.0,
        status: 'skipped',
        notes: 'Door locked',
        isOnLeave: false,
        synced: true,
      );

      expect(item.quantity, 0.0);
      expect(item.status, 'skipped');

      final map = item.toMap();
      expect(map['quantity'], 0.0);
      expect(map['status'], 'skipped');
    });

    test('CopyWith updates fields accurately for offline sync', () {
      final original = DeliveryItem(
        customerId: 10,
        customerName: 'Aarav Sharma',
        customerPhone: '9876543210',
        address: 'Flat 101',
        routeName: 'Sector 4',
        productId: 1,
        productName: 'Cow Milk',
        unit: 'L',
        deliveryDate: '2026-11-07',
        shift: 'morning',
        quantity: 1.0,
        status: 'pending',
        notes: '',
        isOnLeave: false,
        synced: true,
      );

      final updated = original.copyWith(
        status: 'delivered',
        quantity: 2.5,
        synced: false,
      );

      expect(updated.status, 'delivered');
      expect(updated.quantity, 2.5);
      expect(updated.synced, false);
      expect(updated.customerName, 'Aarav Sharma');
    });
  });

  group('Customer Model Tests', () {
    test('Deserializes Customer with product subscriptions', () {
      final json = {
        'id': 1,
        'name': 'Rahul Verma',
        'phone': '9876512345',
        'address': 'B-12 Anand Vihar',
        'route_name': 'Route 1',
        'status': 'active',
        'subscriptions': [
          {
            'product_name': 'Cow Milk',
            'product_code': 'cow',
            'default_quantity': 2.0,
            'custom_rate': 65.0,
            'shift': 'morning',
          },
          {
            'product_name': 'Buffalo Milk',
            'product_code': 'buffalo',
            'default_quantity': 1.0,
            'custom_rate': null,
            'shift': 'evening',
          }
        ]
      };

      final customer = Customer.fromJson(json);
      expect(customer.id, 1);
      expect(customer.name, 'Rahul Verma');
      expect(customer.subscriptions.length, 2);
      expect(customer.subscriptions[0].productName, 'Cow Milk');
      expect(customer.subscriptions[0].defaultQuantity, 2.0);
      expect(customer.subscriptions[1].customRate, isNull);
    });
  });

  group('Invoice Model Tests', () {
    test('Deserializes Invoice and calculates payment status correctly', () {
      final json = {
        'id': 101,
        'customer_id': 1,
        'customer_name': 'Rahul Verma',
        'customer_phone': '9876512345',
        'billing_month': 11,
        'billing_year': 2026,
        'total_units': 60.0,
        'milk_subtotal': 3600.0,
        'additional_charges_total': 50.0,
        'tax_amount': 180.0,
        'final_amount': 3830.0,
        'paid_amount': 3830.0,
        'payment_status': 'paid',
      };

      final invoice = Invoice.fromJson(json);
      expect(invoice.id, 101);
      expect(invoice.isPaid, true);
      expect(invoice.finalAmount, 3830.0);
    });
  });

  group('AppTheme Tests', () {
    test('AppTheme light theme initializes with Material 3', () {
      final theme = AppTheme.lightTheme;
      expect(theme.useMaterial3, true);
      expect(theme.colorScheme.primary, AppTheme.primaryBlue);
    });
  });
}
