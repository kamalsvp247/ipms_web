<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Tests\TestCase;

class AccountBulkSetupStateTest extends TestCase
{
    public function test_can_batch_mark_pdf_uploaded(): void
    {
        $user = User::factory()->create();
        $accounts = Account::factory()->for($user)->count(3)->create(['pdf_uploaded' => false]);

        $response = $this->actingAs($user)->putJson('/api/accounts/bulk-setup-state', [
            'account_ids' => $accounts->pluck('id')->all(),
            'pdf_uploaded' => true,
        ]);

        $response->assertSuccessful();
        $response->assertJson(['updated' => 3]);

        foreach ($accounts as $account) {
            $fresh = $account->fresh();
            $this->assertTrue($fresh->pdf_uploaded);
            $this->assertNotNull($fresh->pdf_uploaded_date);
        }
    }

    public function test_can_batch_mark_booking_configured_pending(): void
    {
        $user = User::factory()->create();
        $accounts = Account::factory()->for($user)->count(2)->create([
            'booking_configured' => true,
            'booking_configured_date' => now(),
        ]);

        $response = $this->actingAs($user)->putJson('/api/accounts/bulk-setup-state', [
            'account_ids' => $accounts->pluck('id')->all(),
            'booking_configured' => false,
        ]);

        $response->assertSuccessful();

        foreach ($accounts as $account) {
            $fresh = $account->fresh();
            $this->assertFalse($fresh->booking_configured);
            $this->assertNull($fresh->booking_configured_date);
        }
    }

    public function test_can_batch_mark_active(): void
    {
        $user = User::factory()->create();
        $accounts = Account::factory()->for($user)->count(2)->create(['is_active' => true]);

        $response = $this->actingAs($user)->putJson('/api/accounts/bulk-setup-state', [
            'account_ids' => $accounts->pluck('id')->all(),
            'is_active' => false,
        ]);

        $response->assertSuccessful();
        $response->assertJson(['updated' => 2]);

        foreach ($accounts as $account) {
            $this->assertFalse($account->fresh()->is_active);
        }
    }

    public function test_regular_user_cannot_batch_update_another_users_accounts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($owner)->create(['pdf_uploaded' => false]);

        $response = $this->actingAs($other)->putJson('/api/accounts/bulk-setup-state', [
            'account_ids' => [$account->id],
            'pdf_uploaded' => true,
        ]);

        $response->assertSuccessful();
        $this->assertFalse($account->fresh()->pdf_uploaded);
    }

    public function test_requires_at_least_one_flag(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $response = $this->actingAs($user)->putJson('/api/accounts/bulk-setup-state', [
            'account_ids' => [$account->id],
        ]);

        $response->assertUnprocessable();
    }
}
