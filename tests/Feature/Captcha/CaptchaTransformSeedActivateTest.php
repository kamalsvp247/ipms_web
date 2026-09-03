<?php

use App\Models\CaptchaTransformSeed;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('keeps the row active when re-activating the already-active row', function () {
    $a = CaptchaTransformSeed::create(['token_type' => 'login', 'seed' => 'aaa', 'offset' => 7, 'length' => 19, 'is_active' => true]);
    $b = CaptchaTransformSeed::create(['token_type' => 'login', 'seed' => 'bbb', 'offset' => 4, 'length' => 19, 'is_active' => false]);

    // Re-activate the row that is already active in memory — the dirty-check trap.
    $a->activate();

    expect(CaptchaTransformSeed::activeForType('login')?->id)->toBe($a->id);
    expect(CaptchaTransformSeed::where('token_type', 'login')->where('is_active', true)->count())->toBe(1);
    expect($b->fresh()->is_active)->toBeFalse();
});

it('switches activation to a different row and deactivates the rest', function () {
    $a = CaptchaTransformSeed::create(['token_type' => 'reserve', 'seed' => 'aaa', 'offset' => 5, 'length' => 17, 'is_active' => true]);
    $b = CaptchaTransformSeed::create(['token_type' => 'reserve', 'seed' => 'bbb', 'offset' => 2, 'length' => 24, 'is_active' => false]);

    $b->activate();

    expect(CaptchaTransformSeed::activeForType('reserve')?->id)->toBe($b->id);
    expect($a->fresh()->is_active)->toBeFalse();
});

it('does not affect a different token type', function () {
    $login = CaptchaTransformSeed::create(['token_type' => 'login', 'seed' => 'L', 'offset' => 7, 'length' => 19, 'is_active' => true]);
    $reserve = CaptchaTransformSeed::create(['token_type' => 'reserve', 'seed' => 'R', 'offset' => 5, 'length' => 17, 'is_active' => true]);

    $reserve->activate();

    expect($login->fresh()->is_active)->toBeTrue();
    expect($reserve->fresh()->is_active)->toBeTrue();
});
