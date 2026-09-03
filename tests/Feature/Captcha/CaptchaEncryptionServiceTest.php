<?php

use App\Models\CaptchaTransformSeed;
use App\Services\Captcha\CaptchaEncryptionService;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const ENC_TOKEN = '0.Abc123-_xYzDEFghijklmNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz';
const ENC_LOGIN_SECRET = '671hnk6vg7e5hnv$4fy+7-_ch_io0)q$_xz=k++r-^&i32dfst';
const ENC_RESERVE_SECRET = '@541m3tp&t63noy&3ngwa%fgfivy3n1_7d)zvj$h-au+bah50f';

function loginSeed(): CaptchaTransformSeed
{
    return CaptchaTransformSeed::create([
        'token_type' => 'login', 'seed' => ENC_LOGIN_SECRET, 'offset' => 7, 'length' => 23, 'is_active' => true,
    ]);
}

function reserveSeed(): CaptchaTransformSeed
{
    return CaptchaTransformSeed::create([
        'token_type' => 'reserve', 'seed' => ENC_RESERVE_SECRET, 'offset' => 4, 'length' => 22, 'is_active' => true,
    ]);
}

describe('live_js sidecar (always used)', function () {
    it('calls the sidecar and returns its output for login', function () {
        Http::fake(['*/encrypt' => Http::response(['token' => 'SIDECAR_OUTPUT'], 200)]);

        $out = app(CaptchaEncryptionService::class)->encryptLogin(ENC_TOKEN, loginSeed());

        expect($out)->toBe('SIDECAR_OUTPUT');
        Http::assertSent(fn ($req) => str_contains($req->url(), '/encrypt') && $req['type'] === 'login');
    });

    it('calls the sidecar and returns its output for reserve', function () {
        Http::fake(['*/encrypt' => Http::response(['token' => 'SIDECAR_RESERVE'], 200)]);

        $out = app(CaptchaEncryptionService::class)->encryptReserve(ENC_TOKEN, reserveSeed());

        expect($out)->toBe('SIDECAR_RESERVE');
        Http::assertSent(fn ($req) => str_contains($req->url(), '/encrypt') && $req['type'] === 'reserve');
    });

    it('returns null when the sidecar is unavailable', function () {
        Http::fake(['*/encrypt' => Http::response(['error' => 'down'], 503)]);

        expect(app(CaptchaEncryptionService::class)->encryptReserve(ENC_TOKEN, reserveSeed()))->toBeNull();
    });

    it('treats an empty-string token from the sidecar as failure (not silent success)', function () {
        Http::fake(['*/encrypt' => Http::response(['token' => ''], 200)]);

        expect(app(CaptchaEncryptionService::class)->encryptLogin(ENC_TOKEN, loginSeed()))->toBeNull();
    });

    it('treats a non-string token from the sidecar as failure', function () {
        Http::fake(['*/encrypt' => Http::response(['token' => null], 200)]);

        expect(app(CaptchaEncryptionService::class)->encryptReserve(ENC_TOKEN, reserveSeed()))->toBeNull();
    });
});

describe('bundle-as-truth (no DB seed)', function () {
    it('encrypts via the sidecar with no seed and sends no secret (meta drives)', function () {
        Http::fake(['*/encrypt' => Http::response(['token' => 'META_DRIVEN'], 200)]);

        $out = app(CaptchaEncryptionService::class)->encryptLogin(ENC_TOKEN, null);

        expect($out)->toBe('META_DRIVEN');
        Http::assertSent(fn ($req) => str_contains($req->url(), '/encrypt')
            && $req['type'] === 'login'
            && ! isset($req['secret']) && ! isset($req['skip']) && ! isset($req['encLen']));
    });

    it('returns null when there is no seed and the sidecar is unavailable', function () {
        Http::fake(['*/encrypt' => Http::response(['error' => 'down'], 503)]);

        expect(app(CaptchaEncryptionService::class)->encryptReserve(ENC_TOKEN, null))->toBeNull();
    });
});
