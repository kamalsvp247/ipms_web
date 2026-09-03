<?php

use App\Http\Controllers\Api\ApiTesterController;
use App\Models\Account;
use App\Models\AccountSession;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'super_admin']);
    $this->account = Account::factory()->create(['phone' => '01700000001', 'status' => 'running']);
});

it('returns null session when no session exists for account', function () {
    $this->actingAs($this->admin)
        ->getJson("/api/api-tester/session/{$this->account->id}")
        ->assertOk()
        ->assertJson(['jwt_token' => null, 'jwt_expires_at' => null, 'jwt_generated_at' => null]);
});

it('returns existing session jwt for account', function () {
    AccountSession::create([
        'account_id' => $this->account->id,
        'phone' => $this->account->phone,
        'jwt_token' => 'test.jwt.token',
        'jwt_expires_at' => now()->addMinutes(15),
        'jwt_generated_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->getJson("/api/api-tester/session/{$this->account->id}")
        ->assertOk()
        ->assertJsonPath('jwt_token', 'test.jwt.token')
        ->assertJsonStructure(['jwt_token', 'jwt_expires_at', 'jwt_generated_at']);
});

it('upserts session when saving a manual jwt token', function () {
    // Real JWT with iat/exp claims (expires far in future)
    $payload = base64_encode(json_encode(['iat' => time(), 'exp' => time() + 899]));
    $jwt = "header.{$payload}.sig";

    $this->actingAs($this->admin)
        ->putJson("/api/api-tester/session/{$this->account->id}", ['jwt_token' => $jwt])
        ->assertOk()
        ->assertJsonPath('jwt_token', $jwt)
        ->assertJsonStructure(['jwt_token', 'jwt_expires_at', 'jwt_generated_at']);

    $this->assertDatabaseHas('account_sessions', [
        'phone' => $this->account->phone,
        'jwt_token' => $jwt,
    ]);
});

it('overwrites existing session when saving a new jwt', function () {
    AccountSession::create([
        'account_id' => $this->account->id,
        'phone' => $this->account->phone,
        'jwt_token' => 'old.token',
    ]);

    $payload = base64_encode(json_encode(['iat' => time(), 'exp' => time() + 899]));
    $newJwt = "header.{$payload}.sig";

    $this->actingAs($this->admin)
        ->putJson("/api/api-tester/session/{$this->account->id}", ['jwt_token' => $newJwt])
        ->assertOk()
        ->assertJsonPath('jwt_token', $newJwt);

    $this->assertEquals(1, AccountSession::where('phone', $this->account->phone)->count());
});

it('returns 422 when jwt_token is missing on update', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/api-tester/session/{$this->account->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['jwt_token']);
});

it('pins the origin via DNS resolve when a bypass IP is selected', function () {
    Setting::instance()->update(['captcha_bd_proxy_url' => 'http://user:pass@bd-pr.oxylabs.io:30001']);

    $transport = app(ApiTesterController::class)->resolveTransport('203.0.113.10');

    expect($transport)->toBe(['mode' => 'resolve', 'value' => '203.0.113.10']);
});

it('tunnels through the BD proxy when no bypass IP is selected', function () {
    Setting::instance()->update(['captcha_bd_proxy_url' => 'http://user:pass@bd-pr.oxylabs.io:30001']);

    $transport = app(ApiTesterController::class)->resolveTransport(null);

    expect($transport)->toBe(['mode' => 'proxy', 'value' => 'http://user:pass@bd-pr.oxylabs.io:30001']);
});

it('goes direct when no bypass IP and no proxy is configured', function () {
    Setting::instance()->update(['captcha_bd_proxy_url' => null]);

    $transport = app(ApiTesterController::class)->resolveTransport(null);

    expect($transport)->toBe(['mode' => 'direct', 'value' => null]);
});

it('returns 404 for unknown account id', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/api-tester/session/99999')
        ->assertNotFound();
});

it('rejects pdf upload without an access token', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/api-tester/upload-pdf', [
            'pdf' => UploadedFile::fake()->create('passport.pdf', 50, 'application/pdf'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['access_token']);
});

it('rejects pdf upload when file is missing', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/api-tester/upload-pdf', [
            'access_token' => 'header.payload.sig',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pdf']);
});

it('rejects pdf upload when file is not a pdf', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/api-tester/upload-pdf', [
            'access_token' => 'header.payload.sig',
            'pdf' => UploadedFile::fake()->create('notes.txt', 50, 'text/plain'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pdf']);
});
