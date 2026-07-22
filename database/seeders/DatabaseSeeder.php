<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Create Permissions
        $permissions = [
            'manage users',
            'manage suppliers',
            'view suppliers',
            'manage categories',
            'view categories',
            'manage products',
            'view products',
            'manage inventory',
            'view transactions',
            'view reports',
            'view audit trail',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // 2. Create Roles and Assign Permissions
        $adminRole = Role::create(['name' => 'Admin']);
        $adminRole->givePermissionTo(Permission::all());

        $staffRole = Role::create(['name' => 'Staff']);
        $staffRole->givePermissionTo([
            'view suppliers',
            'view categories',
            'view products',
            'manage inventory',
            'view transactions',
        ]);

        $supplierRole = Role::create(['name' => 'Supplier']);
        // Supplier uses policy-level checks based on their linked supplier_id, no broad administrative permissions

        // 3. Seed Suppliers
        $supplier1 = Supplier::create([
            'name' => 'Apex Electronics Corp',
            'contact_person' => 'John Doe',
            'email' => 'contact@apex.com',
            'phone' => '+15550192',
            'address' => '123 Tech Blvd, Silicon Valley, CA',
            'status' => 'active',
        ]);

        $supplier2 = Supplier::create([
            'name' => 'Global Logistics Spares',
            'contact_person' => 'Jane Smith',
            'email' => 'sales@globalspares.com',
            'phone' => '+15559876',
            'address' => '456 Freight Rd, Chicago, IL',
            'status' => 'active',
        ]);

        $supplier3 = Supplier::create([
            'name' => 'Prime Packaging Solutions',
            'contact_person' => 'Bob Johnson',
            'email' => 'info@primepack.com',
            'phone' => '+15554321',
            'address' => '789 Industrial Pkwy, Dallas, TX',
            'status' => 'inactive',
        ]);

        // 4. Seed Users
        $admin = User::create([
            'name' => 'System Administrator',
            'email' => 'admin@sims.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $admin->assignRole($adminRole);

        $staff = User::create([
            'name' => 'Warehouse Operator Staff',
            'email' => 'staff@sims.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $staff->assignRole($staffRole);

        $supplierUser = User::create([
            'name' => 'Apex Rep (Supplier)',
            'email' => 'supplier@sims.com',
            'password' => bcrypt('password'),
            'supplier_id' => $supplier1->id,
            'status' => 'active',
        ]);
        $supplierUser->assignRole($supplierRole);

        // 5. Seed Categories
        $catElectronics = Category::create([
            'name' => 'Electronics',
            'description' => 'Gadgets, components, and hardware devices.',
        ]);

        $catAccessories = Category::create([
            'name' => 'Computer Accessories',
            'description' => 'Keyboards, mice, cables, and adapters.',
        ]);

        $catOffice = Category::create([
            'name' => 'Office Stationery',
            'description' => 'Pens, notebooks, desk organizers.',
        ]);

        // 6. Seed Products
        $p1 = Product::create([
            'supplier_id' => $supplier1->id,
            'category_id' => $catElectronics->id,
            'sku' => 'SKU-LAP-COREI7',
            'name' => 'ProBook Laptop Core i7',
            'description' => 'High performance work laptop with 16GB RAM, 512GB SSD.',
            'cost_price' => 750.00,
            'selling_price' => 999.99,
            'current_stock' => 25,
            'minimum_stock' => 5,
            'status' => 'active',
        ]);

        $p2 = Product::create([
            'supplier_id' => $supplier1->id,
            'category_id' => $catElectronics->id,
            'sku' => 'SKU-MON-4K27',
            'name' => 'UltraSharp 27" 4K Monitor',
            'description' => 'IPS Panel display with USB-C Hub connectivity.',
            'cost_price' => 250.00,
            'selling_price' => 349.99,
            'current_stock' => 12,
            'minimum_stock' => 3,
            'status' => 'active',
        ]);

        $p3 = Product::create([
            'supplier_id' => $supplier2->id,
            'category_id' => $catAccessories->id,
            'sku' => 'SKU-MOU-WIRELESS',
            'name' => 'Ergonomic Wireless Mouse',
            'description' => 'Multi-device bluetooth mouse with rechargeable battery.',
            'cost_price' => 18.50,
            'selling_price' => 35.00,
            'current_stock' => 80,
            'minimum_stock' => 15,
            'status' => 'active',
        ]);

        $p4 = Product::create([
            'supplier_id' => $supplier2->id,
            'category_id' => $catAccessories->id,
            'sku' => 'SKU-KEY-MECHANICAL',
            'name' => 'Mechanical Gaming Keyboard',
            'description' => 'Tactile blue switches with customizable RGB lighting.',
            'cost_price' => 45.00,
            'selling_price' => 79.99,
            'current_stock' => 4, // LOW STOCK (minimum is 10)
            'minimum_stock' => 10,
            'status' => 'active',
        ]);

        // 7. Seed Transactions
        // Seeding initial inventory levels via stock_in transactions
        InventoryTransaction::create([
            'product_id' => $p1->id,
            'user_id' => $admin->id,
            'type' => 'stock_in',
            'quantity' => 30,
            'unit_cost' => 750.00,
            'unit_price' => 999.99,
            'remarks' => 'Initial bulk warehouse import.',
            'transaction_date' => now()->subDays(5),
        ]);

        InventoryTransaction::create([
            'product_id' => $p1->id,
            'user_id' => $staff->id,
            'type' => 'stock_out',
            'quantity' => 5,
            'unit_cost' => 750.00,
            'unit_price' => 999.99,
            'remarks' => 'Dispatched to retail display branch.',
            'transaction_date' => now()->subDays(2),
        ]);

        InventoryTransaction::create([
            'product_id' => $p2->id,
            'user_id' => $admin->id,
            'type' => 'stock_in',
            'quantity' => 15,
            'unit_cost' => 250.00,
            'unit_price' => 349.99,
            'remarks' => 'Procured from supplier invoice #APX-9821.',
            'transaction_date' => now()->subDays(4),
        ]);

        InventoryTransaction::create([
            'product_id' => $p2->id,
            'user_id' => $staff->id,
            'type' => 'stock_out',
            'quantity' => 3,
            'unit_cost' => 250.00,
            'unit_price' => 349.99,
            'remarks' => 'Sold to client online order #902.',
            'transaction_date' => now()->subDay(),
        ]);

        InventoryTransaction::create([
            'product_id' => $p3->id,
            'user_id' => $staff->id,
            'type' => 'stock_in',
            'quantity' => 80,
            'unit_cost' => 18.50,
            'unit_price' => 35.00,
            'remarks' => 'Incoming shipment ref #GLS-7392.',
            'transaction_date' => now()->subDays(3),
        ]);

        InventoryTransaction::create([
            'product_id' => $p4->id,
            'user_id' => $staff->id,
            'type' => 'stock_in',
            'quantity' => 5,
            'unit_cost' => 45.00,
            'unit_price' => 79.99,
            'remarks' => 'Sample shipment.',
            'transaction_date' => now()->subDays(6),
        ]);

        InventoryTransaction::create([
            'product_id' => $p4->id,
            'user_id' => $staff->id,
            'type' => 'adjustment',
            'quantity' => -1, // Adjust down (damaged keyboard found in inspection)
            'unit_cost' => 45.00,
            'unit_price' => 79.99,
            'remarks' => 'Damaged unit found in bin; written off.',
            'transaction_date' => now()->subDays(1),
        ]);
    }
}
