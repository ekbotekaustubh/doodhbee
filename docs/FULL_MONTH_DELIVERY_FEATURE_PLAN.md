# Feature Implementation Plan: Full-Month Deliveries Entry

**Date Saved:** 2026-11-22  
**Status:** Completed & Verified (All Tests Passing: 18/18 Unit Tests, 19/19 API Tests)  
**Target Systems:** Web Application (`src/`, `views/`) & Mobile Application (`mobile/lib/`)  

---

## 1. Executive Summary & Objective

Currently, DoodhBee records deliveries on a day-by-day and shift-by-shift basis. Monthly billing in `BillingService` aggregates recorded entries from `daily_deliveries` and `daily_delivery_items`. If days are unrecorded, the invoice calculates 0.00 for those days.

This feature allows dairy owners to:
1. **Bulk auto-fill an entire month** from customer recurring subscriptions in a single click (ideal for retroactively entering past months or bulk regular deliveries).
2. **Review and adjust individual customer milk cards (1–31 day interactive grid)** to handle days where customers took extra milk or skipped deliveries.

---

## 2. Core Workflows

### Mode A: Whole-Month Bulk Auto-Fill (1-Click Generation)
* **Goal:** Populate the full month (e.g., Nov 1 to Nov 30) for all or selected routes with active subscriptions.
* **Options:**
  * **Month & Year:** e.g., November 2026
  * **Route Filter:** All Routes or specific route
  * **Shift Filter:** Both Shifts, Morning Only, Evening Only
  * **Conflict Strategy:**
    * `Preserve Existing` (Default): Keeps existing logged deliveries; only fills blank days.
    * `Overwrite All`: Resets and recalculates the full month.
* **Rules & Guardrails:**
  * **Leave Preservation:** Checks `customer_leaves`. If a customer is on scheduled leave on that day, status is strictly set to `leave` and quantity to `0.00`.
  * **Zero-Charge Rule:** Skipped/leave days strictly record 0 quantity.
  * **Dual Products:** Subscriptions with multiple products (Cow & Buffalo) generate line items in `daily_delivery_items` with appropriate rates.

### Mode B: Customer Monthly Milk Card (Interactive 31-Day Grid)
* **Goal:** Digital replica of the physical monthly milk delivery card/notebook.
* **Display:** 31-day table per customer showing Day, Date, Shift, Product, Subscribed Qty, Actual Qty, Status (Delivered/Skipped/Leave), and Line Total.
* **Actions:**
  * Inline quantity edits (+ / - steppers or direct input).
  * Quick toggle buttons: `Delivered`, `Skipped` (0 qty), `Leave`.
  * Live summary footer: Total Delivered Days, Total Litres, Projected Invoice Amount.
  * "Save Month" button to batch-upsert all changes.

---

## 3. Architecture & API Design

### Service Layer (`src/Services/DeliveryService.php`)
1. `fillMonthDeliveries(int $tenantId, int $year, int $month, array $options): array`
   * Efficient batch insertions with transaction safety.
   * Auto-syncs `daily_deliveries` and `daily_delivery_items`.
2. `getCustomerMonthSheet(int $tenantId, int $customerId, int $year, int $month): array`
   * Returns merged 31-day array combining existing records, customer leaves, and subscriptions.
3. `saveCustomerMonthSheet(int $tenantId, int $customerId, int $year, int $month, array $daysData): array`
   * Validates and upserts daily records for the customer.

### REST API Endpoints (`routes_api.php`)
* `POST /api/deliveries/month-fill` (Auto-fill month for tenant)
* `GET /api/customers/{id}/month-deliveries?year=YYYY&month=MM` (Fetch monthly grid)
* `POST /api/customers/{id}/month-deliveries` (Save monthly grid edits)

---

## 4. UI/UX Placements

* **Web Application:**
  * Runsheet tab: **"Monthly Sheet"** button next to daily shift tabs.
  * Customer Detail: **"Monthly Milk Card"** tab.
  * Invoicing prompt: Notification if unrecorded days exist prior to invoice generation.
* **Mobile Application:**
  * Runsheet header: **"📅 Month Fill"** dialog button.
  * Customer Details: **"Monthly Card (1–31)"** review screen.

---

## 5. Implementation & Verification Summary

1. **Step 1 (Completed):** Implemented `fillMonthDeliveries()`, `getCustomerMonthSheet()`, and `saveCustomerMonthSheet()` in `src/Services/DeliveryService.php` with transactional safety, leave auto-detection, zero-charge rule enforcement, and dual-product line-item preservation.
2. **Step 2 (Completed):** Exposed REST endpoints in `routes_api.php`:
   - `POST /api/deliveries/month-fill`
   - `GET /api/customers/{id}/month-deliveries`
   - `POST /api/customers/{id}/month-deliveries`
   - Integrated into `DeliveryApiController.php` and `CustomerApiController.php`.
   - Added automated tests in `tests/test_suite.php` (TEST 17 & 18) and `tests/api_test_suite.php` (TEST 19). All 18 unit tests and 19 API tests pass!
3. **Step 3 (Completed):** Implemented Web UI:
   - Added `📅 Month Fill` button and modal to `views/deliveries/index.php`.
   - Created full interactive 1–31 day Customer Milk Card screen in `views/customers/milk_card.php`.
   - Added Milk Card navigation links in customer list (`views/customers/index.php`) and delivery rows (`views/deliveries/_row.php`).
   - Wired Web routes in `routes.php` and methods in `DeliveryController.php` & `CustomerController.php`.
4. **Step 4 (Completed):** Implemented Mobile Flutter UI:
   - Added API endpoint constants in `mobile/lib/core/constants/api_constants.dart`.
   - Added `_showMonthFillDialog()` and AppBar action in `mobile/lib/screens/delivery_screen.dart`.
   - Built interactive `CustomerMilkCardScreen` in `mobile/lib/screens/customer_milk_card_screen.dart` with month navigation, live summary KPI ribbon, day steppers, status dropdowns, and batch save.
   - Connected `CustomerMilkCardScreen` to customer roster popup menu in `customer_list_screen.dart` and delivery item card in `delivery_screen.dart`.
