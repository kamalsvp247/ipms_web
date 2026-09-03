<?php

use App\Models\Account;
use App\Models\AgentSlot;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeSlot(string $name = 'slot'): AgentSlot
{
    return AgentSlot::create([
        'name' => $name.'-'.uniqid(),
        'api_key' => 'key-'.uniqid(),
        'status' => 'online',
    ]);
}

it('rejects account creation with no primary PDF', function () {
    $user = User::factory()->create(['role' => 'super_admin']);

    actingAs($user, 'sanctum')->postJson('/api/accounts', [
        'phone' => '111',
        'password' => 'secret',
        'pdfs' => [
            ['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => false],
            ['name' => 'b.pdf', 'base64' => 'BBBB', 'is_primary' => false],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('pdfs');
});

it('rejects account creation with more than one primary PDF', function () {
    $user = User::factory()->create(['role' => 'super_admin']);

    actingAs($user, 'sanctum')->postJson('/api/accounts', [
        'phone' => '222',
        'password' => 'secret',
        'pdfs' => [
            ['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true],
            ['name' => 'b.pdf', 'base64' => 'BBBB', 'is_primary' => true],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('pdfs');
});

it('accepts account creation with exactly one primary PDF and a booking city', function () {
    $user = User::factory()->create(['role' => 'super_admin']);

    $response = actingAs($user, 'sanctum')->postJson('/api/accounts', [
        'phone' => '333',
        'password' => 'secret',
        'booking_city' => 'Dhaka',
        'pdfs' => [
            ['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true],
            ['name' => 'b.pdf', 'base64' => 'BBBB', 'is_primary' => false],
        ],
    ]);

    $response->assertSuccessful();
    $account = Account::where('phone', '333')->first();
    expect($account->booking_city)->toBe('Dhaka');
    expect($account->pdfs)->toHaveCount(2);
    expect($account->pdf_uploaded)->toBeFalse();
});

it('rejects an invalid booking city', function () {
    $user = User::factory()->create(['role' => 'super_admin']);

    actingAs($user, 'sanctum')->postJson('/api/accounts', [
        'phone' => '444',
        'password' => 'secret',
        'booking_city' => 'Paris',
    ])->assertStatus(422)->assertJsonValidationErrors('booking_city');
});

it('resets pdf_uploaded and booking_configured when pdfs or city change', function () {
    $user = User::factory()->create(['role' => 'super_admin']);
    $account = Account::factory()->for($user)->create([
        'pdf_uploaded' => true,
        'booking_city' => 'Dhaka',
        'booking_configured' => true,
    ]);
    $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

    putJson("/api/accounts/{$account->id}", [
        'pdfs' => [['name' => 'a.pdf', 'base64' => 'CHANGED', 'is_primary' => true]],
        'booking_city' => 'Sylhet',
    ]);

    actingAs($user, 'sanctum')->putJson("/api/accounts/{$account->id}", [
        'pdfs' => [['name' => 'a.pdf', 'base64' => 'CHANGED', 'is_primary' => true]],
        'booking_city' => 'Sylhet',
    ])->assertSuccessful();

    $account->refresh();
    expect($account->pdf_uploaded)->toBeFalse();
    expect($account->booking_configured)->toBeFalse();
});

it('lets an operator manually toggle pdf_uploaded and booking_configured, stamping today', function () {
    $user = User::factory()->create(['role' => 'super_admin']);
    $account = Account::factory()->for($user)->create([
        'pdf_uploaded' => false,
        'booking_configured' => false,
    ]);

    actingAs($user, 'sanctum')->putJson("/api/accounts/{$account->id}", [
        'pdf_uploaded' => true,
        'booking_configured' => true,
    ])->assertSuccessful();

    $account->refresh();
    expect($account->pdf_uploaded)->toBeTrue();
    expect($account->pdf_uploaded_date->toDateString())->toBe(now('Asia/Dhaka')->toDateString());
    expect($account->booking_configured)->toBeTrue();
    expect($account->booking_configured_date->toDateString())->toBe(now('Asia/Dhaka')->toDateString());

    actingAs($user, 'sanctum')->putJson("/api/accounts/{$account->id}", [
        'pdf_uploaded' => false,
    ])->assertSuccessful();

    $account->refresh();
    expect($account->pdf_uploaded)->toBeFalse();
    expect($account->pdf_uploaded_date)->toBeNull();
});

it('excludes an account with no PDFs from /api/config so the bot never signs in', function () {
    User::factory()->create(['role' => 'super_admin']);
    $this->seed(\Database\Seeders\SettingSeeder::class);
    $slot = makeSlot();
    $user = User::factory()->create(['role' => 'user']);
    Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'booking_city' => 'Dhaka',
    ]);

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonCount(0, 'accounts');
});

it('includes an account with no PDFs when the manual pdf_uploaded override is stamped for today', function () {
    User::factory()->create(['role' => 'super_admin']);
    $this->seed(\Database\Seeders\SettingSeeder::class);
    $slot = makeSlot();
    $user = User::factory()->create(['role' => 'user']);
    Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'booking_city' => 'Dhaka',
        'phone' => '01711112222',
        'pdf_uploaded' => true,
        'pdf_uploaded_date' => now('Asia/Dhaka')->toDateString(),
    ]);

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonCount(1, 'accounts')
        ->assertJsonPath('accounts.0.phone', '01711112222')
        ->assertJsonPath('accounts.0.pdfUploaded', true);
});

it('excludes an account with no PDFs when the manual pdf_uploaded override is stale (previous day)', function () {
    User::factory()->create(['role' => 'super_admin']);
    $this->seed(\Database\Seeders\SettingSeeder::class);
    $slot = makeSlot();
    $user = User::factory()->create(['role' => 'user']);
    Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'booking_city' => 'Dhaka',
        'pdf_uploaded' => true,
        'pdf_uploaded_date' => now('Asia/Dhaka')->subDay()->toDateString(),
    ]);

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonCount(0, 'accounts');
});

it('excludes an account whose PDFs have no primary from /api/config', function () {
    User::factory()->create(['role' => 'super_admin']);
    $this->seed(\Database\Seeders\SettingSeeder::class);
    $slot = makeSlot();
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'booking_city' => 'Dhaka',
    ]);
    $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => false]);

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonCount(0, 'accounts');
});

it('includes an account with a primary PDF in /api/config', function () {
    User::factory()->create(['role' => 'super_admin']);
    $this->seed(\Database\Seeders\SettingSeeder::class);
    $slot = makeSlot();
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'booking_city' => 'Dhaka',
        'phone' => '01700000000',
    ]);
    $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonCount(1, 'accounts')
        ->assertJsonPath('accounts.0.phone', '01700000000');
});

it('exposes setup flags + booking payload in /api/config and never leaks base64', function () {
    User::factory()->create(['role' => 'super_admin']);
    $this->seed(\Database\Seeders\SettingSeeder::class);
    $slot = makeSlot();
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'booking_city' => 'Chittagong',
        'pdf_uploaded' => true,
        'pdf_uploaded_date' => now('Asia/Dhaka')->toDateString(),
        'booking_configured' => false,
    ]);
    $account->pdfs()->create(['name' => 'secret.pdf', 'base64' => 'TOPSECRETBASE64', 'is_primary' => true]);

    $response = getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key]);

    $response->assertSuccessful()
        ->assertJsonPath('accounts.0.pdfUploaded', true)
        ->assertJsonPath('accounts.0.bookingConfigured', false)
        ->assertJsonPath('accounts.0.bookingMission', 'Chittagong')
        ->assertJsonPath('accounts.0.bookingIvacCenter', 'IVAC, CHITTAGONG');

    expect($response->getContent())->not->toContain('TOPSECRETBASE64');
});

it('reports setup as not done in /api/config when the stamp is from a previous day', function () {
    User::factory()->create(['role' => 'super_admin']);
    $this->seed(\Database\Seeders\SettingSeeder::class);
    $slot = makeSlot();
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'booking_city' => 'Dhaka',
        // Flags true but stamped yesterday — IVAC wiped the setup after that window.
        'pdf_uploaded' => true,
        'pdf_uploaded_date' => now('Asia/Dhaka')->subDay()->toDateString(),
        'booking_configured' => true,
        'booking_configured_date' => now('Asia/Dhaka')->subDay()->toDateString(),
    ]);
    $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonPath('accounts.0.pdfUploaded', false)
        ->assertJsonPath('accounts.0.bookingConfigured', false);
});

it('stamps today when setup-state flips a flag, so the same day reports done', function () {
    User::factory()->create(['role' => 'super_admin']);
    $this->seed(\Database\Seeders\SettingSeeder::class);
    $slot = makeSlot();
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'booking_city' => 'Dhaka',
        'phone' => '5551234567',
        'pdf_uploaded' => false,
        'booking_configured' => false,
    ]);
    $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

    postJson('/api/accounts/setup-state', [
        'phone' => '5551234567',
        'pdf_uploaded' => true,
        'booking_configured' => true,
    ], ['Authorization' => 'Bearer '.$slot->api_key])->assertSuccessful();

    expect($account->fresh()->pdf_uploaded_date->toDateString())->toBe(now('Asia/Dhaka')->toDateString());

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonPath('accounts.0.pdfUploaded', true)
        ->assertJsonPath('accounts.0.bookingConfigured', true);
});

it('keeps base64 out of the accounts list but serves it from show', function () {
    $user = User::factory()->create(['role' => 'super_admin']);
    $account = Account::factory()->for($user)->create();
    $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'LISTWEIGHTBASE64', 'is_primary' => true]);

    $list = actingAs($user, 'sanctum')->getJson('/api/accounts');
    $list->assertSuccessful();
    expect($list->getContent())->not->toContain('LISTWEIGHTBASE64');

    actingAs($user, 'sanctum')->getJson("/api/accounts/{$account->id}")
        ->assertSuccessful()
        ->assertJsonPath('pdfs.0.base64', 'LISTWEIGHTBASE64');
});

it('serves PDFs to the owning slot and forbids others', function () {
    $slot = makeSlot('owner');
    $otherSlot = makeSlot('other');
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
    ]);
    $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'MYBYTES', 'is_primary' => true]);

    getJson("/api/accounts/{$account->id}/pdfs", ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonPath('pdfs.0.base64', 'MYBYTES');

    getJson("/api/accounts/{$account->id}/pdfs", ['Authorization' => 'Bearer '.$otherSlot->api_key])
        ->assertStatus(403);

    getJson("/api/accounts/{$account->id}/pdfs")->assertStatus(401);
});

it('flips setup flags via setup-state for an owned account only', function () {
    $slot = makeSlot('owner');
    $otherSlot = makeSlot('other');
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'phone' => '9998887776',
        'pdf_uploaded' => false,
        'booking_configured' => false,
    ]);

    postJson('/api/accounts/setup-state', [
        'phone' => '9998887776',
        'pdf_uploaded' => true,
        'booking_configured' => true,
    ], ['Authorization' => 'Bearer '.$slot->api_key])->assertSuccessful();

    $account->refresh();
    expect($account->pdf_uploaded)->toBeTrue();
    expect($account->booking_configured)->toBeTrue();

    postJson('/api/accounts/setup-state', [
        'phone' => '9998887776',
        'pdf_uploaded' => false,
    ], ['Authorization' => 'Bearer '.$otherSlot->api_key])->assertStatus(404);
});

it('replaces the portal appointment dates with the ones IVAC reports as available', function () {
    $slot = makeSlot('owner');
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'phone' => '8887776665',
        'appointment_dates' => ['2026-08-01', '2026-08-02', '2026-08-03'],
    ]);

    postJson('/api/accounts/appointment-dates', [
        'phone' => '8887776665',
        'appointment_dates' => ['2026-07-26', '2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30'],
    ], ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonPath('appointment_dates.0', '2026-07-26');

    expect($account->fresh()->appointment_dates)
        ->toBe(['2026-07-26', '2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30']);
});

it('stores IVAC dates sorted and de-duplicated so the accounts UI reads a sane from/to range', function () {
    $slot = makeSlot('owner');
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'phone' => '7776665554',
    ]);

    postJson('/api/accounts/appointment-dates', [
        'phone' => '7776665554',
        'appointment_dates' => ['2026-07-30', '2026-07-26', '2026-07-30', '2026-07-28'],
    ], ['Authorization' => 'Bearer '.$slot->api_key])->assertSuccessful();

    expect($account->fresh()->appointment_dates)->toBe(['2026-07-26', '2026-07-28', '2026-07-30']);
});

it('exports the IVAC-synced dates back to the bot on the next config fetch', function () {
    User::factory()->create(['role' => 'super_admin']);
    $this->seed(\Database\Seeders\SettingSeeder::class);
    $slot = makeSlot('owner');
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'booking_city' => 'Dhaka',
        'phone' => '6665554443',
        'appointment_dates' => ['2026-08-01'],
    ]);
    $account->pdfs()->create(['name' => 'a.pdf', 'base64' => 'AAAA', 'is_primary' => true]);

    postJson('/api/accounts/appointment-dates', [
        'phone' => '6665554443',
        'appointment_dates' => ['2026-07-26', '2026-07-27'],
    ], ['Authorization' => 'Bearer '.$slot->api_key])->assertSuccessful();

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonPath('accounts.0.appointmentDates', ['2026-07-26', '2026-07-27']);
});

it('rejects an appointment-dates write from a foreign slot, no token, or an empty list', function () {
    $slot = makeSlot('owner');
    $otherSlot = makeSlot('other');
    $user = User::factory()->create(['role' => 'user']);
    Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'phone' => '5554443332',
        'appointment_dates' => ['2026-08-01'],
    ]);

    postJson('/api/accounts/appointment-dates', [
        'phone' => '5554443332',
        'appointment_dates' => ['2026-07-26'],
    ], ['Authorization' => 'Bearer '.$otherSlot->api_key])->assertStatus(404);

    postJson('/api/accounts/appointment-dates', [
        'phone' => '5554443332',
        'appointment_dates' => ['2026-07-26'],
    ])->assertStatus(401);

    postJson('/api/accounts/appointment-dates', [
        'phone' => '5554443332',
        'appointment_dates' => [],
    ], ['Authorization' => 'Bearer '.$slot->api_key])->assertStatus(422);
});

/**
 * IVAC answers appointment-booking-config with 400 "Invalid High Commission." when the operator's
 * centre does not match the applicant's own web file. The bot rotates through every centre the
 * portal ships in bookingCityOptions, so the list must reach /api/config in the pair shape the
 * booking-config body sends.
 */
it('ships every high commission to the bot so booking config can rotate off a rejected one', function () {
    $slot = makeSlot('owner');

    $response = getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful();

    $options = $response->json('bookingCityOptions');

    expect($options)->toHaveCount(count(\App\Support\IvacBookingCities::keys()));
    expect($options[0])->toBe(['city' => 'Dhaka', 'mission' => 'Dhaka', 'ivacCenter' => 'IVAC, Dhaka (JFP)']);
    expect(collect($options)->pluck('city')->all())->toBe(\App\Support\IvacBookingCities::keys());
});

/**
 * The centre the rotation landed on is where the account is really booked, so it is persisted —
 * and, unlike an operator edit of the same field, it must NOT clear booking_configured: the bot has
 * just configured IVAC with that very city.
 */
it('records the high commission the bot fell back to without undoing the setup flags', function () {
    $slot = makeSlot('owner');
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'phone' => '4443332221',
        'booking_city' => 'Dhaka',
        'booking_configured' => true,
        'booking_configured_date' => now('Asia/Dhaka')->toDateString(),
    ]);

    postJson('/api/accounts/setup-state', [
        'phone' => '4443332221',
        'booking_city' => 'Sylhet',
    ], ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonPath('booking_city', 'Sylhet');

    $account->refresh();
    expect($account->booking_city)->toBe('Sylhet');
    expect($account->booking_configured)->toBeTrue();
});

it('rejects a booking city the portal does not offer', function () {
    $slot = makeSlot('owner');
    $user = User::factory()->create(['role' => 'user']);
    Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'phone' => '3332221110',
        'booking_city' => 'Dhaka',
    ]);

    postJson('/api/accounts/setup-state', [
        'phone' => '3332221110',
        'booking_city' => 'Rangpur',
    ], ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertStatus(422)
        ->assertJsonValidationErrors('booking_city');
});
