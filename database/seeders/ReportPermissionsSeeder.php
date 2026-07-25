<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants the reporting module permissions to existing installations.
 *
 * Safe to run repeatedly: permissions and role assignments are created only
 * when missing. Run with `php artisan db:seed --class=ReportPermissionsSeeder`.
 */
class ReportPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('auth.defaults.guard');

        $viewReports = Permission::firstOrCreate(['name' => 'view reports', 'guard_name' => $guard]);
        $exportReports = Permission::firstOrCreate(['name' => 'export reports', 'guard_name' => $guard]);

        foreach (['Admin', 'Staff'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', $guard)->first();

            $role?->givePermissionTo([$viewReports, $exportReports]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
