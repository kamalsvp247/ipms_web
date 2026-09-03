<?php

use App\Models\Account;
use App\Models\AccountSession;
use App\Models\PaymentLink;
use App\Models\User;

describe('PaymentLink API', function () {
    beforeEach(function () {
        $user = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($user);
    });

    describe('index', function () {
        it('returns all payment links grouped by account_phone', function () {
            $account = Account::factory()->create(['phone' => '1234567890']);
            PaymentLink::factory(3)->create([
                'account_id' => $account->id,
                'account_phone' => '1234567890',
                'created_at' => now(),
            ]);

            $response = $this->getJson('/api/payment-links');

            $response->assertOk();
            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.links_count'))->toBe(3);
        });

        it('orders groups by their most recent link, not their oldest', function () {
            $accountA = Account::factory()->create(['phone' => '1111111111']);
            $accountB = Account::factory()->create(['phone' => '2222222222']);

            // Account A's group started earlier today but got a fresh link most recently.
            PaymentLink::factory()->create([
                'account_id' => $accountA->id,
                'account_phone' => '1111111111',
                'created_at' => now()->subHours(3),
            ]);
            PaymentLink::factory()->create([
                'account_id' => $accountA->id,
                'account_phone' => '1111111111',
                'created_at' => now(),
            ]);

            // Account B's single link is newer than A's oldest but older than A's newest.
            PaymentLink::factory()->create([
                'account_id' => $accountB->id,
                'account_phone' => '2222222222',
                'created_at' => now()->subHours(1),
            ]);

            $response = $this->getJson('/api/payment-links');

            $response->assertOk();
            expect($response->json('data.0.account_phone'))->toBe('1111111111');
            expect($response->json('data.1.account_phone'))->toBe('2222222222');
        });

        it('includes the account jwt_expires_at for the countdown column', function () {
            $account = Account::factory()->create(['phone' => '1234567890']);
            PaymentLink::factory()->create([
                'account_id' => $account->id,
                'account_phone' => '1234567890',
            ]);
            $jwtExpiresAt = now()->addMinutes(10);
            AccountSession::factory()->create([
                'phone' => '1234567890',
                'jwt_expires_at' => $jwtExpiresAt,
            ]);

            $response = $this->getJson('/api/payment-links');

            $response->assertOk();
            $returned = \Carbon\Carbon::parse($response->json('data.0.jwt_expires_at'));
            expect($returned->diffInSeconds($jwtExpiresAt))->toBeLessThan(1);
        });

        it('filters by date range', function () {
            $account = Account::factory()->create(['phone' => '1234567890']);

            // Create payment links on different dates
            PaymentLink::factory()->create([
                'account_id' => $account->id,
                'account_phone' => '1234567890',
                'created_at' => now()->subDays(5),
            ]);

            PaymentLink::factory()->create([
                'account_id' => $account->id,
                'account_phone' => '1234567890',
                'created_at' => now(),
            ]);

            PaymentLink::factory()->create([
                'account_id' => $account->id,
                'account_phone' => '1234567890',
                'created_at' => now()->addDays(5),
            ]);

            $fromDate = now()->toDateString();
            $toDate = now()->toDateString();

            $response = $this->getJson("/api/payment-links?from_date={$fromDate}&to_date={$toDate}");

            $response->assertOk();
            expect($response->json('total'))->toBe(1);
        });

        it('filters by phone number', function () {
            $account1 = Account::factory()->create(['phone' => '1111111111']);
            $account2 = Account::factory()->create(['phone' => '2222222222']);

            PaymentLink::factory()->create([
                'account_id' => $account1->id,
                'account_phone' => '1111111111',
            ]);

            PaymentLink::factory(2)->create([
                'account_id' => $account2->id,
                'account_phone' => '2222222222',
            ]);

            $response = $this->getJson('/api/payment-links?phone=1111111111');

            $response->assertOk();
            expect($response->json('total'))->toBe(1);
            expect($response->json('data.0.account_phone'))->toBe('1111111111');
        });

        it('combines date range and phone filters', function () {
            $account1 = Account::factory()->create(['phone' => '1111111111']);
            $account2 = Account::factory()->create(['phone' => '2222222222']);

            PaymentLink::factory()->create([
                'account_id' => $account1->id,
                'account_phone' => '1111111111',
                'created_at' => now(),
            ]);

            PaymentLink::factory()->create([
                'account_id' => $account2->id,
                'account_phone' => '2222222222',
                'created_at' => now(),
            ]);

            PaymentLink::factory()->create([
                'account_id' => $account1->id,
                'account_phone' => '1111111111',
                'created_at' => now()->subDays(10),
            ]);

            $fromDate = now()->toDateString();
            $toDate = now()->toDateString();

            $response = $this->getJson("/api/payment-links?from_date={$fromDate}&to_date={$toDate}&phone=1111111111");

            $response->assertOk();
            expect($response->json('total'))->toBe(1);
        });

        it('eagerly loads account relationship', function () {
            $account = Account::factory()->create();
            PaymentLink::factory()->create([
                'account_id' => $account->id,
            ]);

            $response = $this->getJson('/api/payment-links');

            $response->assertOk();
            expect($response->json('data.0.account'))->not->toBeNull();
        });
    });

    describe('store', function () {
        it('automatically sets account_id when account exists', function () {
            $account = Account::factory()->create(['phone' => '9876543210']);

            $response = $this->postJson('/api/payment-links', [
                'data' => ['GatewayPageURL' => 'https://example.com'],
                'account_phone' => '9876543210',
            ]);

            $response->assertCreated();
            expect(PaymentLink::first()->account_id)->toBe($account->id);
        });

        it('stores null account_id when account does not exist', function () {
            $response = $this->postJson('/api/payment-links', [
                'data' => ['GatewayPageURL' => 'https://example.com'],
                'account_phone' => '9999999999',
            ]);

            $response->assertCreated();
            expect(PaymentLink::first()->account_id)->toBeNull();
        });

        it('persists reservation_id from the bot', function () {
            $this->postJson('/api/payment-links', [
                'data' => ['GatewayPageURL' => 'https://gateway.example.com/pay/abc'],
                'account_phone' => '1112223333',
                'reservation_id' => '6a48cc08-aeef-4a20-afba-ed8adef5ed47',
            ])->assertCreated();

            expect(PaymentLink::first()->reservation_id)->toBe('6a48cc08-aeef-4a20-afba-ed8adef5ed47');
        });
    });

    describe('redirect callback', function () {
        it('routes a submitted redirect URL to the link whose reservation_id matches tran_id', function () {
            $target = PaymentLink::factory()->create(['reservation_id' => 'res-AAA']);
            $other = PaymentLink::factory()->create(['reservation_id' => 'res-BBB']);

            $url = 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=res-AAA&data=ENC';

            $this->postJson('/api/payment-links/callback-url', ['url' => $url])
                ->assertOk()
                ->assertJson(['id' => $target->id, 'reservation_id' => 'res-AAA']);

            $target->refresh();
            expect($target->callback_url)->toBe($url);
            expect($target->callback_status)->toBe('pending');
            expect($other->fresh()->callback_url)->toBeNull();
        });

        it('returns 422 when the URL has no tran_id', function () {
            PaymentLink::factory()->create(['reservation_id' => 'res-XYZ']);

            $this->postJson('/api/payment-links/callback-url', [
                'url' => 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback',
            ])->assertStatus(422);
        });

        it('returns 404 when no payment link matches the tran_id', function () {
            $this->postJson('/api/payment-links/callback-url', [
                'url' => 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=ghost&data=ENC',
            ])->assertStatus(404);
        });

        it('lets the bot poll the pending redirect URL by reservation_id', function () {
            PaymentLink::factory()->create([
                'reservation_id' => 'res-POLL',
                'callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-POLL&data=X',
                'callback_status' => 'pending',
            ]);

            $this->getJson('/api/payment-callback?reservation_id=res-POLL')
                ->assertOk()
                ->assertJson([
                    'reservation_id' => 'res-POLL',
                    'callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-POLL&data=X',
                    'callback_status' => 'pending',
                ]);
        });

        it('returns null callback_url when nothing is submitted yet', function () {
            PaymentLink::factory()->create(['reservation_id' => 'res-EMPTY']);

            $this->getJson('/api/payment-callback?reservation_id=res-EMPTY')
                ->assertOk()
                ->assertJson(['reservation_id' => 'res-EMPTY', 'callback_url' => null]);
        });

        it('records the bot-reported callback result', function () {
            $link = PaymentLink::factory()->create([
                'reservation_id' => 'res-DONE',
                'callback_status' => 'pending',
            ]);

            $this->postJson('/api/payment-callback/result', [
                'reservation_id' => 'res-DONE',
                'status' => 'success',
                'status_code' => 200,
                'response' => 'OK',
            ])->assertOk()->assertJson(['ok' => true]);

            $link->refresh();
            expect($link->callback_status)->toBe('success');
            expect($link->callback_status_code)->toBe(200);
            expect($link->callback_completed_at)->not->toBeNull();
        });

        it('returns 404 reporting a result for an unknown reservation_id', function () {
            $this->postJson('/api/payment-callback/result', [
                'reservation_id' => 'nope',
                'status' => 'failed',
            ])->assertStatus(404);
        });

        it('routes a redirect URL submitted via the public ingest endpoint, without auth', function () {
            \Illuminate\Support\Facades\Auth::logout();

            $target = PaymentLink::factory()->create(['reservation_id' => 'res-PUB']);
            $url = 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=res-PUB&data=ENC';

            $this->postJson('/api/payment-links/redirect-url', ['url' => $url])
                ->assertOk()
                ->assertJson(['id' => $target->id, 'reservation_id' => 'res-PUB']);

            expect($target->fresh()->callback_url)->toBe($url);
        });

        it('routes a redirect URL submitted via GET query string, without auth', function () {
            \Illuminate\Support\Facades\Auth::logout();

            $target = PaymentLink::factory()->create(['reservation_id' => 'res-GET']);
            $url = 'https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=res-GET&data=ENC';

            $this->getJson('/api/payment-links/redirect-url?'.http_build_query(['url' => $url]))
                ->assertOk()
                ->assertJson(['id' => $target->id, 'reservation_id' => 'res-GET']);

            expect($target->fresh()->callback_url)->toBe($url);
        });

        it('lists the most recently submitted redirect URLs, newest first', function () {
            $older = PaymentLink::factory()->create([
                'reservation_id' => 'res-OLD',
                'callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-OLD',
                'callback_status' => 'pending',
                'callback_submitted_at' => now()->subMinutes(5),
            ]);
            $newer = PaymentLink::factory()->create([
                'reservation_id' => 'res-NEW',
                'callback_url' => 'https://api.ivacbd.com/cb?tran_id=res-NEW',
                'callback_status' => 'pending',
                'callback_submitted_at' => now(),
            ]);
            PaymentLink::factory()->create(['reservation_id' => 'res-NONE']);

            $response = $this->getJson('/api/payment-links/recent-callbacks')->assertOk();

            expect($response->json())->toHaveCount(2);
            expect($response->json('0.id'))->toBe($newer->id);
            expect($response->json('1.id'))->toBe($older->id);
        });
    });

    describe('invoice download', function () {
        it('returns 404 when the reservation has no payment link', function () {
            $this->getJson('/api/payment-links/invoice?txrId=abc123')
                ->assertStatus(404)
                ->assertJsonPath('message', 'No payment link found for txrId abc123.');
        });

    });

    describe('destroy', function () {
        it('forbids an agent from deleting a payment link', function () {
            $link = PaymentLink::factory()->create();

            $this->actingAs(User::factory()->create(['role' => 'agent']))
                ->deleteJson("/api/payment-links/{$link->id}")
                ->assertForbidden();

            $this->assertModelExists($link);
        });

        it('forbids a manager from deleting a payment link', function () {
            $link = PaymentLink::factory()->create();

            $this->actingAs(User::factory()->create(['role' => 'manager']))
                ->deleteJson("/api/payment-links/{$link->id}")
                ->assertForbidden();

            $this->assertModelExists($link);
        });

        it('lets a super admin delete a payment link', function () {
            $link = PaymentLink::factory()->create();

            $this->actingAs(User::factory()->create(['role' => 'super_admin']))
                ->deleteJson("/api/payment-links/{$link->id}")
                ->assertNoContent();

            $this->assertModelMissing($link);
        });
    });
});
