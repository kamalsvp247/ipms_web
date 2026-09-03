<?php

use App\Models\MonthlyReportOverride;
use App\Models\User;

it('lets a super admin save a monthly report override', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $response = $this->actingAs($admin)->post('/monthly-report/overrides', [
        'rows' => [
            [
                'date' => '2026-07-01',
                'original_visa_type' => 'MEDICAL',
                'visa_type' => 'TOURIST',
                'applicants' => 5,
            ],
        ],
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('monthly_report_overrides', [
        'report_date' => '2026-07-01',
        'original_visa_type' => 'MEDICAL',
        'visa_type' => 'TOURIST',
        'applicants' => 5,
        'updated_by' => $admin->id,
    ]);
});

it('updates an existing override instead of duplicating it', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    MonthlyReportOverride::create([
        'report_date' => '2026-07-01',
        'original_visa_type' => 'MEDICAL',
        'visa_type' => 'TOURIST',
        'applicants' => 5,
        'updated_by' => $admin->id,
    ]);

    $this->actingAs($admin)->post('/monthly-report/overrides', [
        'rows' => [
            [
                'date' => '2026-07-01',
                'original_visa_type' => 'MEDICAL',
                'visa_type' => 'BUSINESS',
                'applicants' => 9,
            ],
        ],
    ]);

    expect(MonthlyReportOverride::count())->toBe(1);
    $this->assertDatabaseHas('monthly_report_overrides', [
        'report_date' => '2026-07-01',
        'original_visa_type' => 'MEDICAL',
        'visa_type' => 'BUSINESS',
        'applicants' => 9,
    ]);
});

it('blocks non-admins from saving overrides', function () {
    $user = User::factory()->create(['role' => 'user']);

    $response = $this->actingAs($user)->post('/monthly-report/overrides', [
        'rows' => [
            [
                'date' => '2026-07-01',
                'original_visa_type' => 'MEDICAL',
                'visa_type' => 'TOURIST',
                'applicants' => 5,
            ],
        ],
    ]);

    $response->assertForbidden();
    $this->assertDatabaseCount('monthly_report_overrides', 0);
});
