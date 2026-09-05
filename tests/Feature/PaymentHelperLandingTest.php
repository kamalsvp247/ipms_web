<?php

use Illuminate\Support\Facades\Storage;

it('renders the public payment-helper landing without auth', function () {
    $this->get('/payment-helper')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PaymentHelper/Landing')
            ->where('app.name', 'DURONTO IVAC Payment Helper')
            ->has('downloadUrl'));
});

it('serves the extension zip for download when the build exists', function () {
    Storage::fake('public');
    Storage::disk('public')->put('extensions/duronto-payment-helper.zip', 'PK-fake-zip-bytes');

    $response = $this->get('/payment-helper/download');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/zip');
    expect($response->headers->get('content-disposition'))->toContain('duronto-payment-helper.zip');
});

it('returns 404 when the extension build is missing', function () {
    Storage::fake('public');

    $this->get('/payment-helper/download')->assertNotFound();
});
