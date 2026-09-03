<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Tests\TestCase;

class AccountBulkAppointmentDatesTest extends TestCase
{
    public function test_can_batch_apply_date_range(): void
    {
        $user = User::factory()->create();
        $accounts = Account::factory()->for($user)->count(3)->create(['appointment_dates' => null]);

        $dates = ['2026-08-01', '2026-08-02', '2026-08-03'];

        $response = $this->actingAs($user)->putJson('/api/accounts/bulk-appointment-dates', [
            'account_ids' => $accounts->pluck('id')->all(),
            'appointment_dates' => $dates,
        ]);

        $response->assertSuccessful();
        $response->assertJson(['updated' => 3]);

        foreach ($accounts as $account) {
            $this->assertSame($dates, $account->fresh()->appointment_dates);
        }
    }

    public function test_regular_user_cannot_batch_update_another_users_accounts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($owner)->create(['appointment_dates' => null]);

        $response = $this->actingAs($other)->putJson('/api/accounts/bulk-appointment-dates', [
            'account_ids' => [$account->id],
            'appointment_dates' => ['2026-08-01'],
        ]);

        $response->assertSuccessful();
        $this->assertNull($account->fresh()->appointment_dates);
    }

    public function test_requires_valid_account_ids(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/accounts/bulk-appointment-dates', [
            'account_ids' => [],
            'appointment_dates' => ['2026-08-01'],
        ]);

        $response->assertUnprocessable();
    }
}
