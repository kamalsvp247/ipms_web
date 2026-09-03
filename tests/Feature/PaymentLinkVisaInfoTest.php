<?php

use App\Models\Account;
use App\Models\AccountSession;
use App\Models\PaymentLink;
use App\Models\User;

it('lets a super admin update visa type and applicants for any payment link', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $owner = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $owner->id, 'phone' => '01711111111']);
    AccountSession::factory()->create(['phone' => $account->phone, 'reserved_visa_type' => 'MEDICAL', 'reserved_applicants' => 2]);
    $link = PaymentLink::factory()->create(['account_phone' => $account->phone]);

    $response = $this->actingAs($admin)->patchJson("/api/payment-links/{$link->id}/visa-info", [
        'visa_type' => 'TOURIST',
        'number_of_applicants' => 4,
    ]);

    $response->assertOk()->assertJson(['visa_type' => 'TOURIST', 'number_of_applicants' => 4]);
    $this->assertDatabaseHas('account_sessions', [
        'phone' => $account->phone,
        'reserved_visa_type' => 'TOURIST',
        'reserved_applicants' => 4,
    ]);
});

it('blocks a user from editing visa info on accounts they do not own', function () {
    $user = User::factory()->create(['role' => 'user']);
    $otherOwner = User::factory()->create(['role' => 'user']);
    $otherAccount = Account::factory()->create(['user_id' => $otherOwner->id, 'phone' => '01722222222']);
    $link = PaymentLink::factory()->create(['account_phone' => $otherAccount->phone]);

    $response = $this->actingAs($user)->patchJson("/api/payment-links/{$link->id}/visa-info", [
        'visa_type' => 'TOURIST',
        'number_of_applicants' => 4,
    ]);

    $response->assertForbidden();
});

it('blocks a user from editing visa info even for their own account', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id, 'phone' => '01733333333']);
    $link = PaymentLink::factory()->create(['account_phone' => $account->phone]);

    $response = $this->actingAs($user)->patchJson("/api/payment-links/{$link->id}/visa-info", [
        'visa_type' => 'BUSINESS',
        'number_of_applicants' => 1,
    ]);

    $response->assertForbidden();
});

it('lets a super admin update the review status of a payment link', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $link = PaymentLink::factory()->create(['review_status' => 'unread']);

    $response = $this->actingAs($admin)->patchJson("/api/payment-links/{$link->id}/review-status", [
        'review_status' => 'declined',
    ]);

    $response->assertOk()->assertJson(['review_status' => 'declined']);
    $this->assertDatabaseHas('payment_links', ['id' => $link->id, 'review_status' => 'declined']);
});

it('blocks a regular user from updating the review status, even on their own account', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id, 'phone' => '01744444444']);
    $link = PaymentLink::factory()->create(['account_phone' => $account->phone, 'review_status' => 'unread']);

    $response = $this->actingAs($user)->patchJson("/api/payment-links/{$link->id}/review-status", [
        'review_status' => 'succeeded',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseHas('payment_links', ['id' => $link->id, 'review_status' => 'unread']);
});

it('lets a manager update the review status of a payment link on their own account', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $account = Account::factory()->create(['user_id' => $manager->id, 'phone' => '01755555555']);
    $link = PaymentLink::factory()->create(['account_phone' => $account->phone, 'review_status' => 'unread']);

    $response = $this->actingAs($manager)->patchJson("/api/payment-links/{$link->id}/review-status", [
        'review_status' => 'succeeded',
    ]);

    $response->assertOk()->assertJson(['review_status' => 'succeeded']);
    $this->assertDatabaseHas('payment_links', ['id' => $link->id, 'review_status' => 'succeeded']);
});

it('blocks a manager from updating the review status on another manager\'s account', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $otherManager = User::factory()->create(['role' => 'manager']);
    $otherAccount = Account::factory()->create(['user_id' => $otherManager->id, 'phone' => '01766666666']);
    $link = PaymentLink::factory()->create(['account_phone' => $otherAccount->phone, 'review_status' => 'unread']);

    $response = $this->actingAs($manager)->patchJson("/api/payment-links/{$link->id}/review-status", [
        'review_status' => 'declined',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseHas('payment_links', ['id' => $link->id, 'review_status' => 'unread']);
});
