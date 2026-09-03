<?php

use App\Models\PaymentLink;
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

test('payment_links_success_count excludes declined links (total - declined)', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    PaymentLink::factory()->create(['success_flag' => true, 'review_status' => 'succeeded']);
    PaymentLink::factory()->create(['success_flag' => true, 'review_status' => 'declined']);

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertOk();
    expect($response->viewData('page')['props']['stats']['payment_links_success_count'])->toBe(1);
});