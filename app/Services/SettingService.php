<?php

namespace App\Services;

use App\Models\Setting;

class SettingService
{
    public function get(): Setting
    {
        return Setting::instance();
    }

    public function update(array $data): Setting
    {
        $setting = Setting::instance();
        $setting->update($data);

        return $setting->fresh();
    }
}
