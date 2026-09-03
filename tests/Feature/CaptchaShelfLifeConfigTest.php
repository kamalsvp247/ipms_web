<?php

use App\Models\AgentSlot;
use App\Models\Setting;
use App\Services\ConfigExportService;

use function Pest\Laravel\getJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function shelfLifeSlot(): AgentSlot
{
    return AgentSlot::create([
        'name' => 'shelf-slot-'.uniqid(),
        'api_key' => 'key-'.uniqid(),
        'status' => 'online',
    ]);
}

it('delivers the stored shelf life to the bot', function () {
    $slot = shelfLifeSlot();
    Setting::instance()->update(['captcha_shelf_life_ms' => 90000]);

    getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->assertJsonPath('captchaShelfLifeMs', 90000);
});

it('keeps the legacy export mirror in step with /api/config', function () {
    $slot = shelfLifeSlot();
    Setting::instance()->update(['captcha_shelf_life_ms' => 45000]);

    $live = getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->json('captchaShelfLifeMs');

    // The mirror silently lacked this key, so anything reading it saw the bot's compiled-in
    // 20s fallback instead of the operator's setting. The two must not drift again.
    expect(app(ConfigExportService::class)->exportForSlot($slot)['captchaShelfLifeMs'])->toBe($live);
});
