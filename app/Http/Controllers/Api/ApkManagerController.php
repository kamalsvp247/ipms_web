<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApkAppInfo;
use App\Models\ApkRelease;
use App\Models\ApkScreenshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ApkManagerController extends Controller
{
    public function overview(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function updateInfo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'app_title' => ['required', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'package_name' => ['nullable', 'string', 'max:255'],
            'developer_name' => ['nullable', 'string', 'max:255'],
            'developer_email' => ['nullable', 'email', 'max:255'],
            'developer_website' => ['nullable', 'url', 'max:255'],
            'features' => ['nullable', 'array', 'max:12'],
            'features.*' => ['string', 'max:255'],
            'is_published' => ['required', 'boolean'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
        ]);

        $info = ApkAppInfo::instance();

        if ($request->hasFile('logo')) {
            if ($info->logo_path) {
                Storage::disk('public')->delete($info->logo_path);
            }
            $validated['logo_path'] = $request->file('logo')->store('apk/logo', 'public');
        }

        unset($validated['logo']);
        $info->update($validated);

        return response()->json($this->payload());
    }

    public function storeRelease(Request $request): JsonResponse
    {
        $request->validate([
            'apk' => ['required', 'file', 'max:204800'],
            'version_name' => ['required', 'string', 'max:50'],
            'version_code' => ['nullable', 'integer', 'min:1'],
            'changelog' => ['nullable', 'string', 'max:5000'],
            'min_android' => ['nullable', 'string', 'max:50'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('apk');

        if (strtolower($file->getClientOriginalExtension()) !== 'apk') {
            return response()->json(['message' => 'The uploaded file must be an .apk package.'], 422);
        }

        $path = $file->storeAs('apk-releases', uniqid('apk_', true).'.apk', 'public');

        $release = ApkRelease::create([
            'version_name' => $request->string('version_name'),
            'version_code' => $request->input('version_code'),
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'checksum_sha256' => hash_file('sha256', Storage::disk('public')->path($path)),
            'changelog' => $request->input('changelog'),
            'min_android' => $request->input('min_android'),
            'released_at' => now(),
        ]);

        if ($request->boolean('activate', true)) {
            $release->activate();
        }

        return response()->json($this->payload(), 201);
    }

    public function updateRelease(Request $request, ApkRelease $release): JsonResponse
    {
        $validated = $request->validate([
            'version_name' => ['required', 'string', 'max:50'],
            'version_code' => ['nullable', 'integer', 'min:1'],
            'changelog' => ['nullable', 'string', 'max:5000'],
            'min_android' => ['nullable', 'string', 'max:50'],
        ]);

        $release->update($validated);

        return response()->json($this->payload());
    }

    public function activateRelease(ApkRelease $release): JsonResponse
    {
        $release->activate();

        return response()->json($this->payload());
    }

    public function destroyRelease(ApkRelease $release): JsonResponse
    {
        Storage::disk('public')->delete($release->file_path);
        $release->delete();

        return response()->json($this->payload());
    }

    public function storeScreenshots(Request $request): JsonResponse
    {
        $request->validate([
            'screenshots' => ['required', 'array', 'min:1', 'max:10'],
            'screenshots.*' => ['image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $nextOrder = (int) ApkScreenshot::max('sort_order') + 1;

        foreach ($request->file('screenshots') as $file) {
            ApkScreenshot::create([
                'path' => $file->store('apk/screenshots', 'public'),
                'sort_order' => $nextOrder++,
            ]);
        }

        return response()->json($this->payload(), 201);
    }

    public function updateScreenshot(Request $request, ApkScreenshot $screenshot): JsonResponse
    {
        $validated = $request->validate([
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        $screenshot->update($validated);

        return response()->json($this->payload());
    }

    public function reorderScreenshots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:apk_screenshots,id'],
        ]);

        foreach ($validated['ids'] as $order => $id) {
            ApkScreenshot::where('id', $id)->update(['sort_order' => $order]);
        }

        return response()->json($this->payload());
    }

    public function destroyScreenshot(ApkScreenshot $screenshot): JsonResponse
    {
        Storage::disk('public')->delete($screenshot->path);
        $screenshot->delete();

        return response()->json($this->payload());
    }

    /**
     * @return array{info: array<string, mixed>, releases: mixed, screenshots: mixed}
     */
    private function payload(): array
    {
        $info = ApkAppInfo::instance();

        return [
            'info' => [
                ...$info->toArray(),
                'logo_url' => $info->logo_path ? Storage::disk('public')->url($info->logo_path) : null,
            ],
            'releases' => ApkRelease::orderByDesc('released_at')->get(),
            'screenshots' => ApkScreenshot::orderBy('sort_order')->get()->map(fn (ApkScreenshot $shot) => [
                ...$shot->toArray(),
                'url' => Storage::disk('public')->url($shot->path),
            ]),
        ];
    }
}
