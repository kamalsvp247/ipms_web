<?php

use App\Models\AccountSession;
use App\Models\PaymentLink;
use App\Models\User;

it('flags a monthly report row as declined when any underlying link was declined', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    PaymentLink::factory()->create([
        'account_phone' => '01711111111',
        'created_at' => '2026-07-01 10:00:00',
        'success_flag' => true,
        'review_status' => 'declined',
    ]);
    PaymentLink::factory()->create([
        'account_phone' => '01722222222',
        'created_at' => '2026-07-02 10:00:00',
        'success_flag' => true,
        'review_status' => 'succeeded',
    ]);

    $response = $this->actingAs($admin)->get('/monthly-report?month=2026-07');

    $response->assertOk();
    $rows = collect($response->viewData('page')['props']['monthRows']);

    expect($rows->firstWhere('date', '2026-07-01')['has_declined'])->toBeTrue()
        ->and($rows->firstWhere('date', '2026-07-02')['has_declined'])->toBeFalse();
});

it('excludes declined links from the success and applicants totals (total - declined)', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    AccountSession::factory()->create(['phone' => '01711111111', 'reserved_visa_type' => 'MEDICAL', 'reserved_applicants' => 3]);
    AccountSession::factory()->create(['phone' => '01722222222', 'reserved_visa_type' => 'MEDICAL', 'reserved_applicants' => 2]);

    // Successful but manually declined — should not count.
    PaymentLink::factory()->create([
        'account_phone' => '01711111111',
        'created_at' => '2026-07-05 10:00:00',
        'success_flag' => true,
        'review_status' => 'declined',
    ]);
    // Genuinely successful — should count.
    PaymentLink::factory()->create([
        'account_phone' => '01722222222',
        'created_at' => '2026-07-05 11:00:00',
        'success_flag' => true,
        'review_status' => 'succeeded',
    ]);

    $response = $this->actingAs($admin)->get('/monthly-report?month=2026-07');

    $response->assertOk();
    $row = collect($response->viewData('page')['props']['monthRows'])->firstWhere('date', '2026-07-05');

    expect($row['success'])->toBe(1)
        ->and($row['applicants'])->toBe(2)
        ->and($row['has_declined'])->toBeTrue();
});

it('sums total links across visa-type rows for the same day (gross total, not net success)', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    AccountSession::factory()->create(['phone' => '01766480879', 'reserved_visa_type' => 'MISCELLANEOUS', 'reserved_applicants' => 2]);
    AccountSession::factory()->create(['phone' => '01951242172', 'reserved_visa_type' => 'MEDICAL', 'reserved_applicants' => 2]);
    AccountSession::factory()->create(['phone' => '01342918700', 'reserved_visa_type' => 'MISCELLANEOUS', 'reserved_applicants' => 2]);
    AccountSession::factory()->create(['phone' => '01301427582', 'reserved_visa_type' => 'MEDICAL', 'reserved_applicants' => 3]);

    PaymentLink::factory()->create(['account_phone' => '01766480879', 'created_at' => '2026-06-09 14:19:08', 'success_flag' => true, 'review_status' => 'succeeded']);
    PaymentLink::factory()->create(['account_phone' => '01951242172', 'created_at' => '2026-06-09 14:19:27', 'success_flag' => true, 'review_status' => 'succeeded']);
    PaymentLink::factory()->create(['account_phone' => '01342918700', 'created_at' => '2026-06-09 14:20:21', 'success_flag' => true, 'review_status' => 'declined']);
    PaymentLink::factory()->create(['account_phone' => '01301427582', 'created_at' => '2026-06-09 14:22:52', 'success_flag' => true, 'review_status' => 'declined']);

    $response = $this->actingAs($admin)->get('/monthly-report?month=2026-06');

    $response->assertOk();
    $rows = collect($response->viewData('page')['props']['monthRows'])->where('date', '2026-06-09');

    expect($rows->sum('total'))->toBe(4)
        ->and($rows->sum('declined'))->toBe(2)
        ->and($rows->sum('success'))->toBe(2);
});
