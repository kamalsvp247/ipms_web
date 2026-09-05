<?php

namespace App\Console\Commands;

use App\Models\ApkAppInfo;
use App\Models\ApkRelease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SyncBundledApkCommand extends Command
{
    protected $signature = 'apk:sync-bundled';

    protected $description = 'Publish the APK bundled with the repository as the active download release';

    public function handle(): int
    {
        $source = base_path('ipms_sms_android/DURONTO.apk');
        if (! is_file($source)) {
            $this->error("Bundled APK not found: {$source}");

            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $path = 'apk-releases/DURONTO.apk';
        $disk->put($path, file_get_contents($source));
        $size = $disk->size($path);
        $checksum = hash_file('sha256', $source);

        ApkRelease::query()->update(['is_active' => false]);
        ApkRelease::updateOrCreate(
            ['file_path' => $path],
            [
                'version_name' => env('APK_BUNDLED_VERSION', '1.0.0'),
                'version_code' => (int) env('APK_BUNDLED_VERSION_CODE', 1),
                'file_name' => env('APK_BUNDLED_FILE_NAME', 'DURONTO.apk'),
                'file_size' => $size,
                'checksum_sha256' => $checksum,
                'changelog' => 'Bundled repository release.',
                'min_android' => env('APK_BUNDLED_MIN_ANDROID', '8.0'),
                'is_active' => true,
                'released_at' => now(),
            ],
        );

        $info = ApkAppInfo::instance();
        if (! $info->is_published) {
            $info->update(['is_published' => true]);
        }

        $this->info("Published {$path} ({$size} bytes, sha256 {$checksum}).");

        return self::SUCCESS;
    }
}
