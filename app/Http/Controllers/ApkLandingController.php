<?php

namespace App\Http\Controllers;

use App\Models\ApkAppInfo;
use App\Models\ApkRelease;
use App\Models\ApkScreenshot;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApkLandingController extends Controller
{
    public function show(): Response
    {
        $info = ApkAppInfo::instance();
        $release = ApkRelease::active();

        abort_unless($info->is_published, 404);

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
            'release' => $release ? [
                'version_name' => $release->version_name,
                'version_code' => $release->version_code,
                'file_name' => $release->file_name,
                'file_size' => $release->file_size,
                'checksum_sha256' => $release->checksum_sha256,
                'changelog' => $release->changelog,
                'min_android' => $release->min_android,
                'download_count' => $release->download_count,
                'released_at' => $release->released_at?->toIso8601String(),
            ] : null,
            'screenshots' => ApkScreenshot::orderBy('sort_order')->get()->map(fn (ApkScreenshot $shot) => [
                'id' => $shot->id,
                'url' => Storage::disk('public')->url($shot->path),
                'caption' => $shot->caption,
            ]),
            'downloadUrl' => route('apk.download', absolute: true),
        ]);
    }

    public function download(): StreamedResponse
    {
        $release = ApkRelease::active();

        abort_if($release === null || ! Storage::disk('public')->exists($release->file_path), 404);

        $release->increment('download_count');

        return Storage::disk('public')->download($release->file_path, $release->file_name, [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
