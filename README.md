# Store Inventory Management System (SIMS)

A role-based inventory management platform built with Laravel 13, Livewire Volt, MySQL, and Spatie Permissions.

SIMS enables organizations to manage products, suppliers, inventory movements, reporting, and audit trails through a secure role-based environment designed for warehouse and store operations.

---

## Features

### Role-Based Access Control

The system supports three user roles:

- **Admin**
  - Full system access
  - Manage users, suppliers, categories, products, inventory, reports, and audit logs

- **Staff**
  - Manage inventory transactions
  - View products, suppliers, and categories
  - Monitor stock levels and movements

- **Supplier**
  - View only their assigned products
  - View stock deliveries related to their catalog
  - Access supplier-specific dashboard metrics

---

### Inventory Management

- Product catalog management
- Category management
- Supplier management
- Stock In transactions
- Stock Out transactions
- Inventory adjustments
- Low stock monitoring
- Inventory valuation tracking

---

### Concurrency-Safe Stock Processing

To prevent inventory corruption during simultaneous operations:

- Database transactions (`DB::transaction`)
- Pessimistic row locking (`lockForUpdate()`)
- Negative stock prevention
- Historical pricing snapshots

Implemented through:

```php
App\Services\InventoryService
```

---

### Reporting

Generate operational reports including:

- Inventory Summary
- Stock Movement Reports
- Low Stock Reports
- Supplier Distribution Reports

Export formats:

- CSV
- PDF

---

### Audit Trail

Powered by Spatie Activity Log.

Tracks:

- User actions
- Record creation
- Updates
- Deletions
- Property-level change history

---

### Security Features

- Authentication via Laravel Fortify
- Role and permission management via Spatie Permissions
- CSRF protection
- SQL Injection protection through Eloquent ORM
- Soft deletes for data recovery
- Authorization policies and middleware

---

## Technology Stack

### Backend

- Laravel 13
- PHP 8.5
- MySQL 8+
- Laravel Fortify

### Frontend

- Livewire Volt
- Tailwind CSS
- Alpine.js
- Flux UI Components

### Packages

- Spatie Laravel Permission
- Spatie Activity Log
- Barryvdh Laravel DomPDF
- Pest PHP

---

## Database Structure

### Core Tables

| Table | Purpose |
|---------|---------|
| users | System users |
| suppliers | Supplier records |
| categories | Product categories |
| products | Product catalog |
| inventory_transactions | Stock movement history |
| activity_log | Audit trail |
| roles / permissions | Access control |

---

## System Modules

### Dashboard

Role-specific statistics including:

- Total Products
- Inventory Value
- Supplier Count
- Low Stock Alerts
- Recent Transactions

---

### User Management

- Create users
- Edit users
- Soft delete users
- Assign roles
- Link supplier accounts

---

### Supplier Management

- Create suppliers
- Edit suppliers
- Track supplier status
- Supplier-product relationships

---

### Category Management

- Create categories
- Edit categories
- Product-category relationships

---

### Product Catalog

- Product CRUD
- SKU management
- Stock monitoring
- Supplier assignment
- Category assignment

---

### Inventory Transactions

- Stock In
- Stock Out
- Inventory Adjustments
- Transaction history
- Transaction filtering

---

### Reports

- Inventory valuation
- Low stock monitoring
- Supplier analysis
- Export functionality

---

### Audit Trail

- Activity monitoring
- Change history
- User accountability

---

## Installation

### Clone Repository

```bash
git clone https://github.com/YOUR_USERNAME/store-inventory-management-system.git
cd store-inventory-management-system
```

### Install Dependencies

```bash
composer install
npm install
```

### Configure Environment

```bash
cp .env.example .env
```

Update database credentials inside `.env`.

Generate the application key:

```bash
php artisan key:generate
```

### Database Setup

```bash
php artisan migrate:fresh --seed
```

### Build Assets

```bash
npm run build
```

For development:

```bash
npm run dev
```

### Start Application

```bash
php artisan serve
```

---

## Demo Accounts

After running the seeders:

| Role | Email | Password |
|--------|--------|--------|
| Admin | admin@sims.com | password |
| Staff | staff@sims.com | password |
| Supplier | supplier@sims.com | password |

> If demo accounts are missing, re-run:
>
> ```bash
> php artisan migrate:fresh --seed
> ```

---

## Testing

Run the complete test suite:

```bash
php artisan test
```

Verified components include:

- InventoryService
- Authentication
- User Management
- Product Management
- Inventory Transactions
- Dashboard functionality
- Runtime verification tests

---

## Project Architecture Highlights

### Inventory Service

The inventory engine uses:

```php
DB::transaction(...)
Product::lockForUpdate()
```

to guarantee data consistency during concurrent stock operations.

### Audit Logging

All major entities are tracked through:

```php
Spatie Activity Log
```

providing complete traceability of changes.

### Soft Delete Support

The following records support soft deletion:

- Users
- Suppliers
- Categories
- Products

---

## Future Improvements

Potential enhancements include:

- Email notifications
- Low stock alerts
- REST API
- Barcode scanning
- Purchase order management
- Dashboard analytics charts
- Multi-warehouse support

---

## Screenshots

Add screenshots here after deployment.

### Dashboard

![Dashboard](docs/screenshots/dashboard.png)

### Products

![Products](docs/screenshots/products.png)

### Transactions

![Transactions](docs/screenshots/transactions.png)

---

## License

This project was developed for educational, portfolio, and learning purposes.