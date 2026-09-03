<?php

use App\Models\Account;
use App\Models\AccountPdf;
use App\Models\AgentSlot;
use App\Models\User;
use App\Support\VisaFormPdfParser;
use Symfony\Component\Process\Process;

use function Pest\Laravel\postJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * The synthetic Indian visa application form at tests/Fixtures/visa-form.pdf, generated with PyMuPDF
 * at the label/value x origins encoded in App\Support\VisaFormPdfParser. It carries the applicant
 * BEGUM / MAHA / A00893899 / 1960628962 / 01726666741 / MAHA77BEGUM@PROTON.ME.
 */
function visaFormPdfBase64(): string
{
    return base64_encode((string) file_get_contents(base_path('tests/Fixtures/visa-form.pdf')));
}

/**
 * The editor runs the real PyMuPDF script, so it is skipped wherever fitz is not importable — the
 * same treatment PdfOptimizerTest gives a missing Ghostscript.
 */
function pdfEditorAvailable(): bool
{
    $process = new Process(['python3', '-c', 'import fitz']);
    $process->run();

    return $process->isSuccessful();
}

function syncSlot(string $name = 'sync-slot'): AgentSlot
{
    return AgentSlot::create([
        'name' => $name.'-'.uniqid(),
        'api_key' => 'key-'.uniqid(),
        'status' => 'online',
    ]);
}

/**
 * @param  array<string, mixed>  $pdfOverrides
 */
function accountWithForm(AgentSlot $slot, array $pdfOverrides = []): Account
{
    $account = Account::create([
        'user_id' => User::factory()->create(['role' => 'super_admin'])->id,
        'agent_slot_id' => $slot->id,
        'phone' => '0135'.random_int(100000, 999999),
        'email' => 'holder@example.com',
        'password' => 'secret',
        'booking_city' => 'Dhaka',
    ]);

    AccountPdf::create(array_merge([
        'account_id' => $account->id,
        'name' => 'applicant.pdf',
        'base64' => visaFormPdfBase64(),
        'is_primary' => true,
    ], $pdfOverrides));

    return $account;
}

/** The profile IVAC holds for the account — every value differs from the fixture form. */
function ivacProfile(): array
{
    return [
        'given_name' => 'LIPI RANI',
        'surname' => 'SARKER',
        'passport' => 'A17642157',
        'nid' => '3297718599',
        'phone' => '01606439393',
        'email' => 'suvo.sa.h.a54.9@gmail.com',
    ];
}

it('rejects a request with no slot token', function () {
    postJson('/api/accounts/pdf-profile-sync', ['phone' => '01350918207', 'profile' => ivacProfile()])
        ->assertStatus(401)
        ->assertJson(['error' => 'invalid_api_key']);
});

it('hides an account belonging to another slot', function () {
    $account = accountWithForm(syncSlot('owner'));
    $other = syncSlot('other');

    postJson('/api/accounts/pdf-profile-sync', [
        'phone' => $account->phone,
        'profile' => ivacProfile(),
    ], ['Authorization' => 'Bearer '.$other->api_key])
        ->assertStatus(404)
        ->assertJson(['error' => 'account_not_found']);
});

it('refuses an account with no primary document', function () {
    $slot = syncSlot();
    $account = accountWithForm($slot, ['is_primary' => false]);

    postJson('/api/accounts/pdf-profile-sync', [
        'phone' => $account->phone,
        'profile' => ivacProfile(),
    ], ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertStatus(422)
        ->assertJson(['error' => 'no_primary_pdf']);
});

it('rewrites every field of the form to match the IVAC profile', function () {
    if (! pdfEditorAvailable()) {
        $this->markTestSkipped('python3 PyMuPDF (fitz) not installed');
    }

    $slot = syncSlot();
    $account = accountWithForm($slot);
    $account->update(['pdf_uploaded' => true, 'booking_configured' => true]);

    $response = postJson('/api/accounts/pdf-profile-sync', [
        'phone' => $account->phone,
        'profile' => ivacProfile(),
    ], ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertOk()
        ->assertJson(['phone' => $account->phone, 'changed' => true]);

    // The document handed back is the one the bot will upload, so it is what has to state the profile.
    $delivered = base64_decode($response->json('pdfs.0.base64'));
    expect(VisaFormPdfParser::extract($delivered))
        ->toMatchArray([
            'given_name' => 'LIPI RANI',
            'surname' => 'SARKER',
            'passport' => 'A17642157',
            'nid' => '3297718599',
            'phone' => '01606439393',
            'email' => 'suvo.sa.h.a54.9@gmail.com',
        ]);
    expect($response->json('pdfs.0.is_primary'))->toBeTrue();

    // Date of birth is not written, so it must survive untouched.
    expect(VisaFormPdfParser::extract($delivered)['dob'])->toBe('2003-10-01');

    // The IVAC-side chain was built around the old form and has to be rebuilt against the new one.
    $account->refresh();
    expect($account->pdf_uploaded)->toBeFalse();
    expect($account->booking_configured)->toBeFalse();
});

it('leaves an already-correct form completely untouched', function () {
    if (! pdfEditorAvailable()) {
        $this->markTestSkipped('python3 PyMuPDF (fitz) not installed');
    }

    $slot = syncSlot();
    $account = accountWithForm($slot);

    // The profile IVAC holds already matches the fixture, so there is nothing to write.
    $matching = [
        'given_name' => 'MAHA',
        'surname' => 'BEGUM',
        'passport' => 'A00893899',
        'nid' => '1960628962',
        'phone' => '01726666741',
        'email' => 'maha77begum@proton.me',
    ];

    $before = $account->pdfs()->first()->base64;

    postJson('/api/accounts/pdf-profile-sync', [
        'phone' => $account->phone,
        'profile' => $matching,
    ], ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertOk()
        ->assertJson(['changed' => false]);

    // Byte-identical: a repeated repair must never stack a second overlay onto the same page.
    expect($account->pdfs()->first()->base64)->toBe($before);
});

it('stores nothing when a value cannot be placed on the form', function () {
    if (! pdfEditorAvailable()) {
        $this->markTestSkipped('python3 PyMuPDF (fitz) not installed');
    }

    $slot = syncSlot();
    $account = accountWithForm($slot);
    $before = $account->pdfs()->first()->base64;

    // A landline cannot be read back by the parser, so the edit can never be verified as applied.
    $response = postJson('/api/accounts/pdf-profile-sync', [
        'phone' => $account->phone,
        'profile' => array_merge(ivacProfile(), ['phone' => '029334455']),
    ], ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertStatus(422)
        ->assertJson(['error' => 'fields_not_applied']);

    expect($response->json('fields'))->toHaveKey('phone');
    // The stored document is the operator's original, not a half-applied edit.
    expect($account->pdfs()->first()->base64)->toBe($before);
});

it('does not accept an unverifiable value as applied', function () {
    if (! pdfEditorAvailable()) {
        $this->markTestSkipped('python3 PyMuPDF (fitz) not installed');
    }

    $slot = syncSlot();
    // A form carrying only Phone No — no Mobile /Cell No for the parser to fall back to. Writing a
    // landline here leaves the parser with nothing to read, so expected and actual are BOTH null and
    // a plain equality check would call the edit applied.
    $account = accountWithForm($slot, ['base64' => base64_encode(
        (string) file_get_contents(base_path('tests/Fixtures/visa-form-no-mobile.pdf')))]);

    postJson('/api/accounts/pdf-profile-sync', [
        'phone' => $account->phone,
        'profile' => array_merge(ivacProfile(), ['phone' => '029334455']),
    ], ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertStatus(422)
        ->assertJson(['error' => 'fields_not_applied']);
});
