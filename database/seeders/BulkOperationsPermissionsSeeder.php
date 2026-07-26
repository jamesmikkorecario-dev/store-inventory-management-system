<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants the bulk import / bulk operations permissions to existing installations.
 *
 * Safe to run repeatedly: permissions and role assignments are created only
 * when missing. Run with `php artisan db:seed --class=BulkOperationsPermissionsSeeder`.
 */
class BulkOperationsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('auth.defaults.guard');

        $permissions = [];

        foreach (['import products', 'bulk manage products', 'export catalog'] as $name) {
            $permissions[] = Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        foreach (['Admin', 'Staff'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', $guard)->first();

            $role?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
