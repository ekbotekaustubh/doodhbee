import 'package:flutter_test/flutter_test.dart';
import 'package:doodhbee_mobile/models/delivery_item.dart';
import 'package:doodhbee_mobile/models/customer.dart';
import 'package:doodhbee_mobile/models/delivery_route.dart';
import 'package:doodhbee_mobile/models/invoice.dart';
import 'package:doodhbee_mobile/models/product.dart';
import 'package:doodhbee_mobile/models/additional_charge.dart';
import 'package:doodhbee_mobile/models/ledger_entry.dart';
import 'package:doodhbee_mobile/core/constants/api_constants.dart';
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
    test('Deserializes Customer with product subscriptions and routeId', () {
      final json = {
        'id': 1,
        'route_id': 5,
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
      expect(customer.routeId, 5);
      expect(customer.routeName, 'Route 1');
      expect(customer.name, 'Rahul Verma');
      expect(customer.subscriptions.length, 2);
      expect(customer.subscriptions[0].productName, 'Cow Milk');
      expect(customer.subscriptions[0].defaultQuantity, 2.0);
      expect(customer.subscriptions[1].customRate, isNull);
    });
  });

  group('DeliveryRoute Model Tests', () {
    test('Deserializes DeliveryRoute and flags default route correctly', () {
      final json = {
        'id': 1,
        'tenant_id': 1,
        'name': 'Main Route',
        'description': 'Default primary delivery route',
        'sort_order': 1,
        'is_active': 1,
        'customer_count': 12,
        'active_customer_count': 10,
        'is_default': true,
      };

      final route = DeliveryRoute.fromJson(json);
      expect(route.id, 1);
      expect(route.name, 'Main Route');
      expect(route.isDefault, true);
      expect(route.isActive, true);
      expect(route.customerCount, 12);
      expect(route.activeCustomerCount, 10);
    });

    test('Custom non-default DeliveryRoute', () {
      final json = {
        'id': 8,
        'tenant_id': 1,
        'name': 'Sector 4 Express',
        'description': 'Towers 1-6 morning route',
        'sort_order': 2,
        'is_active': 0,
        'customer_count': 3,
        'active_customer_count': 3,
        'is_default': false,
      };

      final route = DeliveryRoute.fromJson(json);
      expect(route.id, 8);
      expect(route.name, 'Sector 4 Express');
      expect(route.isDefault, false);
      expect(route.isActive, false);
      expect(route.customerCount, 3);
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

  group('Product Model Tests', () {
    test('Deserializes and serializes Product correctly', () {
      final json = {
        'id': 5,
        'name': 'Paneer',
        'code': 'paneer',
        'unit': 'kg',
        'default_rate': 340.0,
        'sort_order': 3,
        'is_active': 1,
      };

      final product = Product.fromJson(json);
      expect(product.id, 5);
      expect(product.name, 'Paneer');
      expect(product.unit, 'kg');
      expect(product.defaultRate, 340.0);
      expect(product.isActive, true);

      final map = product.toJson();
      expect(map['code'], 'paneer');
      expect(map['is_active'], 1);
    });
  });

  group('AdditionalCharge Model Tests', () {
    test('Deserializes and formats charge types correctly', () {
      final json = {
        'id': 2,
        'charge_name': 'Monthly Delivery Fee',
        'charge_type': 'flat_monthly',
        'amount': 50.0,
        'is_taxable': 1,
        'is_active': 1,
      };

      final charge = AdditionalCharge.fromJson(json);
      expect(charge.id, 2);
      expect(charge.chargeName, 'Monthly Delivery Fee');
      expect(charge.amount, 50.0);
      expect(charge.isTaxable, true);
      expect(charge.formattedChargeType, 'Flat Monthly');
    });
  });

  group('LedgerEntry Model Tests', () {
    test('Deserializes invoice and payment entries with running balance', () {
      final invoiceJson = {
        'id': 10,
        'type': 'invoice',
        'date': '2026-11-01',
        'description': 'Milk Invoice for October 2026',
        'debit': 1800.0,
        'credit': 0.0,
        'status': 'unpaid',
        'balance_after': 1800.0,
      };

      final entry = LedgerEntry.fromJson(invoiceJson);
      expect(entry.isInvoice, true);
      expect(entry.isPayment, false);
      expect(entry.debit, 1800.0);
      expect(entry.balanceAfter, 1800.0);
    });
  });

  group('ApiConstants Configuration Tests', () {
    test('Production defaultBaseUrl matches https://www.doodhbee.com/api', () {
      expect(ApiConstants.defaultBaseUrl, 'https://www.doodhbee.com/api');
    });
  });
}
