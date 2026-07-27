# Store Inventory Management System (SIMS) - Installation Guide

This document provides step-by-step instructions for installing and configuring the Store Inventory Management System in a local development or staging environment.

---

## Prerequisites

Before beginning installation, ensure your environment meets the following system requirements:

- **Operating System**: Linux, macOS, or Windows (via WSL2)
- **PHP**: Version 8.5 or higher with the following required extensions installed and enabled:
  - `php-mysql`, `php-mbstring`, `php-xml`, `php-bcmath`, `php-curl`, `php-zip`, `php-intl`, `php-gd`
- **Database**: MySQL 8.0+ or MariaDB 10.6+
- **Composer**: PHP dependency manager (v2.x)
- **Node.js**: Version 20.x or higher with `npm` (v10.x)
- **Git**: For version control and cloning

---

## Installation Steps

### 1. Clone the Repository
Clone the project repository to your local machine and navigate into the project root:
```bash
git clone https://github.com/jamesmikkorecario-dev/store-inventory-management-system.git
cd store-inventory-management-system
```

### 2. Install PHP Dependencies
Run Composer to install all backend packages and dev dependencies:
```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
```

### 3. Install Node.js Dependencies
Install required frontend packages for Vite, Tailwind CSS v4, and Livewire Flux UI:
```bash
npm install
```

### 4. Environment Configuration
Copy the sample environment configuration file:
```bash
cp .env.example .env
```

Generate a unique 32-character application encryption key:
```bash
php artisan key:generate
```

Open `.env` in your preferred text editor and configure your database connection settings:
```ini
APP_NAME="Store Inventory Management System"
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_management_system
DB_USERNAME=root
DB_PASSWORD=your_secure_password
```

### 5. Create Database
Using your MySQL client or command line, create the database specified in your `.env` file:
```sql
CREATE DATABASE IF NOT EXISTS inventory_management_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 6. Run Migrations and Seeders
Execute database migrations to build the schema and populate initial roles, permissions, and demo portfolio data:
```bash
php artisan migrate:fresh --seed
```

### 7. Compile Frontend Assets
Build the production CSS and JavaScript bundles using Vite:
```bash
npm run build
```
*(For active frontend development with hot module replacement, run `npm run dev` instead).*

### 8. Start the Development Server
Launch the built-in Laravel PHP development server:
```bash
php artisan serve
```
The application will now be accessible in your browser at `http://127.0.0.1:8000`.

---

## Verifying the Installation

To verify that the system is functioning correctly, execute the automated verification test suite:
```bash
php artisan test --compact
```
All feature and unit tests should execute cleanly with 100% passing results.

---

## Troubleshooting & Common Issues

- **Vite Manifest Not Found**: If you encounter `Unable to locate file in Vite manifest`, ensure you have executed `npm run build` or have `npm run dev` running in a separate terminal window.
- **Permission Denied on Storage**: Ensure web server write permissions are granted to storage and cache directories:
  ```bash
  chmod -R 775 storage bootstrap/cache
  ```
- **Database Connection Error**: Verify MySQL is active on port 3306 and that credentials in `.env` match your database user permissions.
