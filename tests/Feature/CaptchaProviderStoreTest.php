<?php

use App\Enums\CaptchaProviderType;
use App\Models\CaptchaProvider;
use App\Models\User;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeCaptchaUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

// ─── Store: all provider types are accepted ───────────────────────────────────

it('accepts all captcha provider types on store', function (string $type, string $label) {
    $user = makeCaptchaUser();

    $response = $this->actingAs($user)->postJson('/api/captcha-providers', [
        'name' => "{$label} Test",
        'type' => $type,
        'enabled' => false,
        'api_key' => 'test-key-123',
        'solver_threads' => 1,
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('captcha_providers', [
        'user_id' => $user->id,
        'type' => $type,
        'name' => "{$label} Test",
    ]);
})->with([
    'capmonster' => ['capmonster', 'CapMonster'],
    '2captcha' => ['2captcha', '2Captcha'],
    'captchaai' => ['captchaai', 'CaptchaAI'],
    'capsolver' => ['capsolver', 'CapSolver'],
    'solvecaptcha' => ['solvecaptcha', 'SolveCaptcha'],
    'in_house' => ['in_house', 'In-House'],
]);

// ─── Store: invalid type is rejected ─────────────────────────────────────────

it('rejects an unknown captcha provider type', function () {
    $user = makeCaptchaUser();

    $this->actingAs($user)->postJson('/api/captcha-providers', [
        'name' => 'Bad Provider',
        'type' => 'unknown_provider',
        'enabled' => false,
    ])->assertUnprocessable();
});

// ─── Enum completeness ────────────────────────────────────────────────────────

it('has a store route that accepts every CaptchaProviderType enum case', function () {
    $user = makeCaptchaUser();

    foreach (CaptchaProviderType::cases() as $case) {
        $this->actingAs($user)->postJson('/api/captcha-providers', [
            'name' => "Enum test {$case->value}",
            'type' => $case->value,
            'enabled' => false,
            'api_key' => 'key',
        ])->assertSuccessful();
    }

    expect(CaptchaProvider::where('user_id', $user->id)->count())
        ->toBe(count(CaptchaProviderType::cases()));
});
