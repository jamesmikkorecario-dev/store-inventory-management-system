<?php

use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('Admin');
    Permission::findOrCreate('view audit trail');
    $role = Role::findByName('Admin');
    $role->givePermissionTo('view audit trail');
});

it('escapes user controlled data in the audit trail to prevent xss', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $product = Product::factory()->create([
        'name' => 'Safe Product Name',
    ]);

    // Manually create an activity log with malicious payload
    activity()
        ->performedOn($product)
        ->causedBy($admin)
        ->withProperties([
            'attributes' => ['name' => '<script>alert("xss")</script>'],
            'old' => ['name' => '<img src=x onerror=alert("xss")>'],
        ])
        ->log('updated');

    Livewire::actingAs($admin)
        ->test('pages::audit')
        ->assertSeeHtml('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;')
        ->assertSeeHtml('&lt;img src=x onerror=alert(&quot;xss&quot;)&gt;')
        ->assertDontSeeHtml('<script>alert("xss")</script>')
        ->assertDontSeeHtml('<img src=x onerror=alert("xss")>');
});
