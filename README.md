# Store Inventory Management System (SIMS)

A role-based enterprise inventory management platform built with Laravel 13, Livewire Volt, MySQL, and Spatie Permissions.

SIMS enables organizations to manage products, suppliers, inventory movements, purchase orders, predictive forecasting, reporting, and audit trails through a secure, concurrency-safe environment designed for warehouse and retail operations.

---

## Features

### Role-Based Access Control

The system supports three distinct user roles with tenant-isolated data boundaries:

- **Admin**
  - Full system control and administration
  - Manage users, suppliers, categories, products, inventory, purchase orders, forecasts, reports, and audit logs
  - Approve purchase orders and adjust system-wide settings

- **Staff**
  - Operational warehouse processing
  - Manage inventory transactions (Stock In, Stock Out, Adjustments)
  - Create and manage purchase orders and receive stock shipments
  - Monitor real-time stock levels, low-stock alerts, and replenishment forecasts

- **Supplier**
  - Dedicated external vendor portal access
  - Tenant-isolated view of assigned product catalogs and pricing
  - Monitor purchase orders assigned to their vendor account
  - Track delivery timelines and historical supplier performance scoring

---

### Core Inventory Management

- **Product Catalog**: Multi-attribute SKU management with cost price, retail price, minimum stock thresholds, and status toggles.
- **Category & Supplier Administration**: Relational organization with active/inactive status enforcement and soft deletion safeguards.
- **Stock Transactions**: Comprehensive stock movement tracking (Stock In, Stock Out, Adjustment, Damage/Loss) with mandatory reference numbers and operator attribution.
- **Real-Time Valuation**: Instantaneous O(1) inventory valuation and low-stock threshold monitoring across the entire catalog.

---

### Purchase Orders & Supply Chain (Phase 3.1)

- **End-to-End Lifecycle**: Workflow management supporting Draft, Submitted, Approved, Received, and Cancelled states.
- **Automated Replenishment**: One-click purchase order generation from low-stock recommendations and forecasting deficits.
- **Receiving & Reconciliation**: Seamless receiving workflows that automatically execute transactional stock adjustments upon delivery.
- **Approval Gateways**: Role-enforced approval requirements for purchase orders exceeding organizational thresholds.

---

### Predictive Stock Forecasting (Phase 3.2)

- **Replenishment Analytics**: Intelligent stock depletion calculations based on historical daily usage rates over 14, 30, 60, or 90-day windows.
- **Stockout Countdown**: Real-time "Days of Inventory Remaining" projections to prevent warehouse stockouts before they occur.
- **Smart Reorder Calculations**: Automated calculation of suggested reorder quantities to maintain optimal inventory buffer levels.

---

### Supplier Portal (Phase 3.3)

- **Tenant Isolation**: Strict query scoping ensuring vendors can only view and interact with their own assigned SKUs and purchase orders.
- **Real-Time Order Tracking**: Vendors can monitor open orders, approval statuses, and expected delivery dates.
- **Performance Metrics**: Transparent vendor scoring evaluating On-Time Delivery Rate (%) and Defect/Damage Rate (%) based on historical shipments.

---

### Notifications & Alerts (Phase 3.4)

- **Real-Time Alert Engine**: Automated database notifications alerting operators to critical inventory events.
- **Event Coverage**: Triggered alerts for Critical Stockouts, Purchase Order Approvals, Overdue Deliveries, and Stock Replenishment.
- **Interactive Alert Hub**: Filterable status tabs (All, Unread, Read, Critical) with quick mark-as-read and resolution workflows.

---

### Concurrency-Safe Stock Processing

To prevent race conditions, dirty reads, and inventory corruption during simultaneous multi-user operations:

- **Database Transactions** (`DB::transaction`) wrapping all multi-step modifications.
- **Pessimistic Row Locking** (`Product::lockForUpdate()`) serializing concurrent stock updates.
- **Negative Stock Prevention**: Exception-driven checks guaranteeing inventory levels never fall below zero.
- **Historical Pricing Snapshots**: Transaction records preserve exact unit costs at the time of movement.

Implemented through:
```php
App\Services\InventoryService
App\Services\PurchaseOrderService
```

---

### Reporting & Analytics

Generate exportable operational reports including:

- **Inventory Valuation Report**: Breakdown of stock quantities, cost values, and retail values.
- **Low Stock & Dead Stock Analysis**: Filterable reports highlighting items requiring immediate attention.
- **Supplier Performance & Distribution**: Comparative metrics evaluating vendor reliability and volume.
- **Transaction Summary**: Auditable logs of all historical warehouse movements.

Export formats supported:
- **CSV** (Spreadsheet analysis)
- **PDF** (Formatted print-ready documentation via DomPDF)

---

### Audit Trail & Security

Powered by Spatie Activity Log and Laravel Fortify:

- **Granular Activity Logging**: Tracks user actions, record creation, updates, and soft deletions.
- **Property-Level Diffing**: Records exact before-and-after attribute changes for full accountability.
- **Enterprise Security**: CSRF protection, Eloquent ORM SQL injection immunity, strict RBAC middleware, and client-side Livewire attribute locking (`#[Locked]`).

---

## Technology Stack

### Backend
- **Framework**: Laravel 13
- **Runtime**: PHP 8.5
- **Database**: MySQL 8+
- **Authentication**: Laravel Fortify
- **Authorization**: Spatie Laravel Permission

### Frontend
- **Reactivity**: Livewire 4 & Livewire Volt (Functional Components)
- **Styling**: Tailwind CSS v4
- **Client Interactivity**: Alpine.js
- **UI Component System**: Livewire Flux UI Components (Free / Open Source)

### Testing & Tooling
- **Testing Framework**: Pest PHP v4
- **Static Analysis**: Larastan / PHPStan (Level 6)
- **Code Formatter**: Laravel Pint
- **PDF Generation**: Barryvdh Laravel DomPDF

---

## Database Structure

### Core Tables

| Table | Purpose |
|---------|---------|
| `users` | System operators, administrators, and vendor representatives |
| `suppliers` | Supplier company profiles and contact details |
| `categories` | Product classification hierarchy |
| `products` | Master product catalog with pricing and thresholds |
| `inventory_transactions` | Historical stock movement logs |
| `purchase_orders` | Header records for supplier orders |
| `purchase_order_items` | Line items, unit costs, and received quantities |
| `notifications` | In-app user alerts and system notifications |
| `activity_log` | System-wide audit trail and property change diffs |
| `roles` / `permissions` | Spatie RBAC access control definitions |

---

## System Modules

- **Dashboard**: Role-tailored analytics, O(1) inventory valuation, and critical alerts.
- **Purchase Orders**: Multi-step ordering, approval workflows, and automated stock receiving.
- **Forecasting**: Predictive stockout countdowns and suggested reorder modeling.
- **Supplier Portal**: Vendor-scoped catalog views, order monitoring, and performance scorecards.
- **Product Catalog**: SKU management, supplier/category assignment, and low-stock tracking.
- **Transactions**: Stock In, Stock Out, and Adjustment processing with immutable history.
- **Reports**: Valuation, low stock, dead stock, and supplier distribution reporting (PDF/CSV).
- **User & Role Management**: Staff provisioning, role assignment, and supplier account linking.
- **Audit Logs**: Full system transparency with user attribution and timestamped diffs.

---

## Installation

### 1. Clone Repository

```bash
git clone https://github.com/jamesmikkorecario-dev/store-inventory-management-system.git
cd store-inventory-management-system
```

### 2. Install Dependencies

```bash
composer install
npm install
```

### 3. Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

Update your database connection parameters inside `.env`:
```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_management_system
DB_USERNAME=root
DB_PASSWORD=
```

### 4. Database Setup & Seeding

Run fresh migrations and populate demo data:
```bash
php artisan migrate:fresh --seed
```

### 5. Build Frontend Assets

For production bundle compilation:
```bash
npm run build
```

For live development server with hot module replacement:
```bash
npm run dev
```

### 6. Start Application Server

```bash
php artisan serve
```
Access the application at `http://127.0.0.1:8000`.

---

## Demo Accounts

The `DatabaseSeeder` provisions clean, portfolio-ready demo accounts across all user roles:

| Role | Email | Password | Description |
|--------|--------|--------|--------|
| **Admin** | `admin@sims.demo` (or `admin@sims.com`) | `password` | Full system access and approval authority |
| **Staff** | `staff@sims.com` | `password` | Warehouse operator for transactions and orders |
| **Supplier** | `supplier@sims.com` | `password` | Vendor portal account linked to Apex Supplies |
| **Vendor Admin 1** | `elena.admin@sims.demo` | `password` | Dual-role Admin assigned to Metro Freight & Logistics |
| **Vendor Admin 2** | `david.admin@sims.demo` | `password` | Dual-role Admin assigned to Pacific Rim Supply Co. |

> If demo accounts are missing or reset is required, re-run:
> ```bash
> php artisan migrate:fresh --seed
> ```

---

## Testing & Quality Assurance

The project enforces strict automated testing and static analysis standards:

### Execute Test Suite
Run the complete Pest feature and unit test suite:
```bash
php artisan test --compact
```
*(Covers 345+ tests and 1,418+ assertions across concurrency services, RBAC, workflows, and UI components).*

### Static Analysis & Code Formatting
```bash
# Run PHPStan static analysis
vendor/bin/phpstan analyse --memory-limit=2G

# Run Laravel Pint code formatter
vendor/bin/pint --format agent
```

---

## Project Architecture Highlights

### Concurrency & Transaction Safety
The core inventory engine (`App\Services\InventoryService`) guarantees consistency under load:
```php
DB::transaction(function () use ($product, $quantity) {
    $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();
    // Negative stock prevention and atomic balance updating
});
```

### Livewire Attribute Locking
To protect against client-side state manipulation in Livewire components, all sensitive role flags, vendor IDs, and authorization evaluation properties are decorated with `#[Locked]`:
```php
#[Locked]
public bool $isSupplier = false;

#[Locked]
public ?int $supplierId = null;
```

---

## Future Improvements

Remaining roadmap enhancements for future versions:

- **Email & SMS Notifications**: Integration with external notification providers (AWS SES / Twilio) for out-of-app stockout alerts.
- **REST API & Webhooks**: Headless API endpoints for third-party ERP and e-commerce platform syncing.
- **Barcode & QR Scanning**: Browser-based camera scanning for instant SKU lookup during receiving and stock counts.
- **Multi-Warehouse Support**: Tracking stock distribution and inter-warehouse transfers across multiple physical locations.
- **Batch & Expiry Tracking**: Lot number tagging and expiration date alerts for perishable inventory.

---

## License

This project was developed for educational, professional portfolio, and enterprise architecture demonstration purposes.