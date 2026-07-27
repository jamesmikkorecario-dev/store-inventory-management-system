# Store Inventory Management System (SIMS) - User Guide

This guide is designed for warehouse operators, store managers, and system administrators navigating the daily operations of the Store Inventory Management System.

---

## Role Navigation & Access

SIMS adapts its interface based on your assigned user role:

| Role | Accessible Modules | Primary Responsibilities |
| :--- | :--- | :--- |
| **Admin** | Dashboard, Products, Categories, Suppliers, Transactions, Purchase Orders, Forecasting, Reports, User Management, Audit Logs | Full system configuration, user provisioning, order approvals, and financial reporting |
| **Staff** | Dashboard, Products, Categories, Suppliers, Transactions, Purchase Orders, Forecasting, Reports | Operational stock processing, receiving shipments, and monitoring inventory levels |

---

## Core Warehouse Workflows

### 1. Managing Product Catalogs
1. Navigate to **Products** from the primary sidebar.
2. Click **Add Product** to register a new SKU.
3. Input essential identifiers: SKU (must be unique), Name, Category, and assigned Supplier.
4. Set financial metrics: Cost Price and Selling/Retail Price.
5. Define the **Minimum Stock** threshold—when current inventory drops below this number, automated alerts and reorder suggestions will trigger.

### 2. Processing Inventory Transactions
All inventory adjustments require immutable transaction logging:
1. Navigate to **Transactions** in the navigation menu.
2. Select **Record Transaction** (or click **Stock In** / **Stock Out** directly on product cards).
3. Choose the transaction type:
   - **Stock In**: Incoming deliveries or returns.
   - **Stock Out**: Store sales, dispatch, or allocations.
   - **Adjustment**: Manual inventory corrections following physical stock counts.
   - **Damage / Loss**: Write-offs for damaged or expired goods.
4. Input the Quantity and mandatory Reference Number (e.g., invoice number or receipt ID).
5. Submit to execute atomic database balance updating.

---

## Supply Chain & Purchase Orders

### Creating Purchase Orders
1. Navigate to **Purchase Orders** and click **Create Order**.
2. Select the target Supplier and Expected Delivery Date.
3. Add line items from the supplier's catalog, specifying ordering quantities and agreed unit costs.
4. Save as a **Draft** or click **Submit for Approval**.

### Order Approvals (Admin Only)
- Orders exceeding organizational thresholds enter the **Submitted** state.
- Administrators can review order details and select **Approve** or **Reject**.
- Approved orders are immediately visible to external vendors in the Supplier Portal.

### Receiving Shipments
1. Locate an **Approved** purchase order and click **Receive Stock**.
2. Verify physical delivery counts against ordered quantities.
3. Enter received amounts per line item and submit.
4. *Automated Action*: SIMS automatically generates corresponding **Stock In** inventory transactions and updates SKU stock balances.

---

## Predictive Forecasting & Reordering

The **Forecasting** module helps prevent stockouts before they happen:
- **Usage Rate Calculation**: Analyzes daily consumption over 14 to 90-day historical windows.
- **Days Left Countdown**: Calculates exact estimated days of inventory remaining.
- **Automated Reordering**: Select forecasted stockout items and click **Generate Purchase Orders** to automatically draft vendor orders with suggested quantities.

---

## Notifications & Alerts

Click the **Bell Icon** in the top navigation bar to access real-time system alerts:
- Filter alerts by status: **All**, **Unread**, **Read**, or **Critical**.
- Click **Mark as Read** on resolved stockout warnings or delivery notifications.
- Use **Mark All as Read** to clear your active notification queue.

---

## Generating Reports

Navigate to **Reports** to export operational metrics:
- **Valuation Report**: View total inventory assets broken down by cost and retail value.
- **Low Stock Report**: Filter items requiring replenishment.
- Click **Export PDF** or **Export CSV** to download print-ready operational spreadsheets.
