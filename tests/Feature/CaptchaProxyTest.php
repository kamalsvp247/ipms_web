<?php

namespace Tests\Feature;

use App\Enums\CaptchaProviderType;
use App\Models\CaptchaProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CaptchaProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_captcha_proxy_task_creation()
    {
        $user = User::factory()->create();
        $provider = CaptchaProvider::create([
            'user_id' => $user->id,
            'name' => 'CapMonster Test',
            'type' => CaptchaProviderType::CapMonster->value,
            'api_key' => 'test_key',
            'enabled' => true,
            'solver_threads' => 1,
        ]);

        Http::fake([
            'api.capmonster.cloud/createTask' => Http::response(['errorId' => 0, 'taskId' => 12345], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/api/captcha-proxy/create', [
            'provider_id' => $provider->id,
            'websiteURL' => 'https://example.com',
            'websiteKey' => 'site_key_123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['errorId' => 0, 'taskId' => 12345]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.capmonster.cloud/createTask' &&
                   $request['clientKey'] === 'test_key';
        });
    }

    public function test_captcha_proxy_result_polling()
    {
        $user = User::factory()->create();
        $provider = CaptchaProvider::create([
            'user_id' => $user->id,
            'name' => 'CaptchaAI Test',
            'type' => CaptchaProviderType::CaptchaAi->value,
            'api_key' => 'test_key_ai',
            'enabled' => true,
            'solver_threads' => 1,
        ]);

        Http::fake([
            'ocr.captchaai.com/res.php' => Http::response([
                'status' => 1,
                'request' => 'solved_token_xyz',
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/api/captcha-proxy/result', [
            'provider_id' => $provider->id,
            'taskId' => '12345',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'errorId' => 0,
                'status' => 'ready',
                'solution' => ['gRecaptchaResponse' => 'solved_token_xyz'],
            ]);
    }

    public function test_captcha_ai_turnstile_task_creation()
    {
        $user = User::factory()->create();
        $provider = CaptchaProvider::create([
            'user_id' => $user->id,
            'name' => 'CaptchaAI Turnstile',
            'type' => CaptchaProviderType::CaptchaAi->value,
            'api_key' => 'test_key_turnstile',
            'enabled' => true,
            'solver_threads' => 1,
        ]);

        Http::fake([
            'ocr.captchaai.com/in.php' => Http::response([
                'status' => 1,
                'request' => '2730468336',
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/api/captcha-proxy/create', [
            'provider_id' => $provider->id,
            'websiteURL' => 'https://appointment.ivacbd.com/',
            'websiteKey' => '0x4AAAAAACghKkJHL1t7UkuZ', // Cloudflare Turnstile key
        ]);

        $response->assertStatus(200)
            ->assertJson(['errorId' => 0, 'taskId' => '2730468336']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://ocr.captchaai.com/in.php' &&
                   $request['method'] === 'turnstile' &&
                   $request['sitekey'] === '0x4AAAAAACghKkJHL1t7UkuZ';
        });
    }
}
