<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountSession;
use App\Models\User;
use Tests\TestCase;

class AccountStatusTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_account_status_defaults_to_running(): void
    {
        $account = Account::factory()->for($this->user)->create();

        $this->assertEquals('running', $account->status);
    }

    public function test_can_update_account_status(): void
    {
        $account = Account::factory()->for($this->user)->create();

        $response = $this->actingAs($this->user)->putJson("/api/accounts/{$account->id}", [
            'status' => 'completed',
        ]);

        $response->assertSuccessful();
        $this->assertEquals('completed', $account->fresh()->status);
    }

    public function test_can_filter_accounts_by_status(): void
    {
        Account::factory()->for($this->user)->create(['status' => 'running']);
        Account::factory()->for($this->user)->create(['status' => 'completed']);
        Account::factory()->for($this->user)->create(['status' => 'running']);

        $response = $this->actingAs($this->user)->getJson('/api/accounts?status=running');

        $response->assertSuccessful();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_public_config_includes_otp_and_signin_fields(): void
    {
        $account = Account::factory()->create(['status' => 'running', 'is_active' => true]);
        $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

        $exp = now()->addSeconds(500)->getTimestamp();
        $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['exp' => $exp])), '+/', '-_'), '=');
        $jwt = "$header.$payload.sig";

        AccountSession::factory()->create([
            'account_id' => $account->id,
            'phone' => $account->phone,
            'jwt_token' => $jwt,
            'jwt_expires_at' => now()->addSeconds(500),
            'is_otp_verified' => true,
            'request_id' => 'req-signin-xyz',
            'signed_in_server_time' => '2026-06-16T07:29:15.000Z',
        ]);

        $response = $this->getJson('/api/config');

        $response->assertOk();
        $entry = collect($response->json('accounts'))
            ->firstWhere('phone', $account->phone);

        $this->assertTrue($entry['isOtpVerified']);
        $this->assertSame('req-signin-xyz', $entry['signinRequestId']);
        $this->assertNotNull($entry['signinServerTimeMs']);
    }

    public function test_public_config_includes_per_account_max_retries(): void
    {
        $account = Account::factory()->create([
            'status' => 'running',
            'is_active' => true,
            'max_retries' => 2,
        ]);
        $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

        $response = $this->getJson('/api/config');

        $response->assertOk();
        $entry = collect($response->json('accounts'))
            ->firstWhere('phone', $account->phone);

        $this->assertSame(2, $entry['maxRetries']);
    }

    public function test_public_config_otp_verified_false_when_jwt_expired(): void
    {
        $account = Account::factory()->create(['status' => 'running', 'is_active' => true]);
        $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

        AccountSession::factory()->create([
            'account_id' => $account->id,
            'phone' => $account->phone,
            'jwt_token' => 'some.jwt.token',
            'jwt_expires_at' => now()->subMinutes(5),
            'is_otp_verified' => true,
            'request_id' => 'req-old',
        ]);

        $response = $this->getJson('/api/config');

        $response->assertOk();
        $entry = collect($response->json('accounts'))
            ->firstWhere('phone', $account->phone);

        // Expired JWT — isOtpVerified must be false and signinRequestId still returned
        $this->assertFalse($entry['isOtpVerified']);
    }

    public function test_can_update_single_sign_in_flag(): void
    {
        $account = Account::factory()->for($this->user)->create(['single_sign_in' => false]);

        $response = $this->actingAs($this->user)->putJson("/api/accounts/{$account->id}", [
            'single_sign_in' => true,
        ]);

        $response->assertSuccessful();
        $this->assertTrue($account->fresh()->single_sign_in);
    }

    public function test_public_config_includes_single_sign_in_flag(): void
    {
        $account = Account::factory()->create([
            'status' => 'running',
            'is_active' => true,
            'single_sign_in' => true,
        ]);
        $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

        $response = $this->getJson('/api/config');

        $response->assertOk();
        $entry = collect($response->json('accounts'))
            ->firstWhere('phone', $account->phone);

        $this->assertTrue($entry['singleSignIn']);
    }

    public function test_public_config_single_sign_in_defaults_to_false(): void
    {
        $account = Account::factory()->create(['status' => 'running', 'is_active' => true]);
        $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

        $response = $this->getJson('/api/config');

        $response->assertOk();
        $entry = collect($response->json('accounts'))
            ->firstWhere('phone', $account->phone);

        $this->assertFalse($entry['singleSignIn']);
    }

    public function test_public_config_only_returns_running_accounts(): void
    {
        $running1 = Account::factory()->create(['status' => 'running', 'is_active' => true]);
        $completed = Account::factory()->create(['status' => 'completed', 'is_active' => true]);
        $running2 = Account::factory()->create(['status' => 'running', 'is_active' => true]);
        $running1->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);
        $running2->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

        $response = $this->getJson('/api/config');

        $response->assertSuccessful();
        $accounts = $response->json('accounts');

        // Verify that only running accounts are returned
        $accountPhones = collect($accounts)->pluck('phone')->toArray();
        $this->assertContains($running1->phone, $accountPhones);
        $this->assertContains($running2->phone, $accountPhones);
        $this->assertNotContains($completed->phone, $accountPhones);
    }
}
