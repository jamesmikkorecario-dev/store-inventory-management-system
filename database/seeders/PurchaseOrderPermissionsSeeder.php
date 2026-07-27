<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants the purchase order module permissions to existing installations.
 *
 * Safe to run repeatedly: permissions and role assignments are created only
 * when missing. Run with `php artisan db:seed --class=PurchaseOrderPermissionsSeeder`.
 *
 * Admin receives the full set (including approval); Staff can raise, edit and
 * receive orders but not approve them. Suppliers get no purchase order access.
 */
class PurchaseOrderPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('auth.defaults.guard');

        $permissions = [];

        foreach ([
            'view purchase orders',
            'manage purchase orders',
            'approve purchase orders',
            'receive purchase orders',
        ] as $name) {
            $permissions[$name] = Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        $grants = [
            'Admin' => array_keys($permissions),
            'Staff' => ['view purchase orders', 'manage purchase orders', 'receive purchase orders'],
        ];

        foreach ($grants as $roleName => $granted) {
            $role = Role::where('name', $roleName)->where('guard_name', $guard)->first();

            $role?->givePermissionTo(array_map(fn (string $name) => $permissions[$name], $granted));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
