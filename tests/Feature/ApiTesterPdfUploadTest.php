<?php

use App\Models\Account;
use App\Models\AccountPdf;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'super_admin']);
    $this->account = Account::factory()->create(['phone' => '01700000001', 'status' => 'running']);
});

it('exposes the account attached pdfs (primary first) in the api-tester context', function () {
    AccountPdf::create([
        'account_id' => $this->account->id,
        'name' => 'secondary.pdf',
        'base64' => base64_encode('secondary bytes'),
        'original_size' => 15,
        'is_primary' => false,
    ]);
    AccountPdf::create([
        'account_id' => $this->account->id,
        'name' => 'primary.pdf',
        'base64' => base64_encode('primary bytes'),
        'original_size' => 13,
        'is_primary' => true,
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/api-tester/context')
        ->assertOk()
        ->assertJsonPath('accounts.0.pdfs.0.name', 'primary.pdf')
        ->assertJsonPath('accounts.0.pdfs.0.is_primary', true)
        ->assertJsonPath('accounts.0.pdfs.1.name', 'secondary.pdf')
        ->assertJsonPath('accounts.0.pdfs.1.is_primary', false);
});

it('rejects an account-pdf upload for a pdf that does not exist', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/api-tester/upload-account-pdf', [
            'access_token' => 'jwt.token.here',
            'pdf_id' => 999999,
        ])
        ->assertStatus(422);
});

it('returns 422 when the stored pdf has no decodable content', function () {
    $pdf = AccountPdf::create([
        'account_id' => $this->account->id,
        'name' => 'empty.pdf',
        'base64' => '',
        'is_primary' => true,
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/api-tester/upload-account-pdf', [
            'access_token' => 'jwt.token.here',
            'pdf_id' => $pdf->id,
        ])
        ->assertStatus(422)
        ->assertJson(['error' => 'PDF could not be decoded from storage.']);
});
