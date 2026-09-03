<?php

namespace Database\Seeders;

use App\Models\ApkAppInfo;
use App\Models\ApkRelease;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ApkDistributionSeeder extends Seeder
{
    public function run(): void
    {
        ApkAppInfo::firstOrCreate([], [
            'app_title' => 'Blitz SMS Forwarder',
            'tagline' => 'Real-time SMS to portal. Zero friction.',
            'description' => 'Blitz forwards incoming SMS messages to the IPMS portal in real time. Built for reliability: foreground service, boot persistence, and OEM battery-optimization bypass out of the box.',
            'package_name' => 'site.mashmininet.smsforwarder',
            'developer_name' => 'Senda Japan Ltd',
            'features' => [
                'Instant SMS forwarding over HTTPS',
                'Survives reboots and task kills',
                'Dual-SIM slot detection',
                'Foreground service with live status',
                'OEM battery-optimization setup guide',
                'Zero configuration — install and go',
            ],
            'is_published' => true,
        ]);

        $sourcePath = public_path('blitz.apk');

        if (ApkRelease::count() > 0 || ! is_file($sourcePath)) {
            return;
        }

        $storedPath = 'apk-releases/'.uniqid('apk_', true).'.apk';
        Storage::put($storedPath, file_get_contents($sourcePath));

        ApkRelease::create([
            'version_name' => '2.0',
            'version_code' => 20,
            'file_path' => $storedPath,
            'file_name' => 'Blitz-v2.0.apk',
            'file_size' => filesize($sourcePath),
            'checksum_sha256' => hash_file('sha256', $sourcePath),
            'changelog' => "Redesigned setup flow with guided permissions\nDual-SIM slot detection\nNew Blitz branding and adaptive icons\nForeground service reliability improvements",
            'min_android' => '8.0',
            'is_active' => true,
            'released_at' => now(),
        ]);
    }
}
