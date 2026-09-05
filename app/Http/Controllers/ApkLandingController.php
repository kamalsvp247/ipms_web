<?php

namespace App\Http\Controllers;

use App\Models\ApkAppInfo;
use App\Models\ApkRelease;
use App\Models\ApkScreenshot;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApkLandingController extends Controller
{
    private const BUNDLED_APK_PATH = 'ipms_sms_android/DURONTO.apk';

    public function show(): Response
    {
        $info = ApkAppInfo::instance();
        $release = ApkRelease::active();

        abort_unless($info->is_published, 404);

        $releasePayload = $release ? [
            'version_name' => $release->version_name,
            'version_code' => $release->version_code,
            'file_name' => $release->file_name,
            'file_size' => $release->file_size,
            'checksum_sha256' => $release->checksum_sha256,
            'changelog' => $release->changelog,
            'min_android' => $release->min_android,
            'download_count' => $release->download_count,
            'released_at' => $release->released_at?->toIso8601String(),
        ] : $this->bundledReleasePayload();

        return Inertia::render('Apk/Landing', [
            'app' => [
                'title' => $info->app_title,
                'tagline' => $info->tagline,
                'description' => $info->description,
                'package_name' => $info->package_name,
                'developer_name' => $info->developer_name,
                'developer_email' => $info->developer_email,
                'developer_website' => $info->developer_website,
                'logo_url' => $info->logo_path ? Storage::disk('public')->url($info->logo_path) : null,
                'features' => $info->features ?? [],
            ],
            'release' => $releasePayload,
            'screenshots' => ApkScreenshot::orderBy('sort_order')->get()->map(fn (ApkScreenshot $shot) => [
                'id' => $shot->id,
                'url' => Storage::disk('public')->url($shot->path),
                'caption' => $shot->caption,
            ]),
            'downloadUrl' => route('apk.download', absolute: true),
        ]);
    }

    public function download(): BinaryFileResponse|StreamedResponse
    {
        $release = ApkRelease::active();

        if ($release === null || ! Storage::disk('public')->exists($release->file_path)) {
            $bundledPath = base_path(self::BUNDLED_APK_PATH);
            abort_unless(is_file($bundledPath), 404);

            return response()->download($bundledPath, env('APK_BUNDLED_FILE_NAME', 'DURONTO.apk'), [
                'Content-Type' => 'application/vnd.android.package-archive',
            ]);
        }

        $release->increment('download_count');

        return Storage::disk('public')->download($release->file_path, $release->file_name, [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }

    /** @return array<string, mixed>|null */
    private function bundledReleasePayload(): ?array
    {
        $path = base_path(self::BUNDLED_APK_PATH);
        if (! is_file($path)) {
            return null;
        }

        return [
            'version_name' => env('APK_BUNDLED_VERSION', '1.0.0'),
            'version_code' => (int) env('APK_BUNDLED_VERSION_CODE', 1),
            'file_name' => env('APK_BUNDLED_FILE_NAME', 'DURONTO.apk'),
            'file_size' => filesize($path),
            'checksum_sha256' => hash_file('sha256', $path),
            'changelog' => 'Bundled repository release.',
            'min_android' => env('APK_BUNDLED_MIN_ANDROID', '8.0'),
            'download_count' => 0,
            'released_at' => null,
        ];
    }
}
