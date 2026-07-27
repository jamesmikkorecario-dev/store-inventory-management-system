<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('user can switch analytics period', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSet('analyticsPeriod', 30)
        ->call('switchPeriod', 7)
        ->assertSet('analyticsPeriod', 7)
        ->call('switchPeriod', 90)
        ->assertSet('analyticsPeriod', 90)
        ->call('switchPeriod', 30)
        ->assertSet('analyticsPeriod', 30);
});
