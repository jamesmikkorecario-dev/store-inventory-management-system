<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants the inventory forecasting permissions to existing installations.
 *
 * Safe to run repeatedly: permissions and role assignments are created only
 * when missing. Run with `php artisan db:seed --class=ForecastPermissionsSeeder`.
 *
 * Admin and Staff both receive full forecasting access; Suppliers get none.
 */
class ForecastPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('auth.defaults.guard');

        $permissions = [];

        foreach (['view forecasts', 'export forecasts'] as $name) {
            $permissions[] = Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        foreach (['Admin', 'Staff'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', $guard)->first();

            $role?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
