<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public landing + download for the DURONTO IVAC Payment Helper browser extension.
 * The extension captures the IVAC dg-epay payment callback URL in the browser and
 * POSTs it to the portal's public redirect-url ingest endpoint (see PaymentLinkController).
 */
class PaymentHelperController extends Controller
{
    private const ZIP_PATH = 'extensions/duronto-payment-helper.zip';

    private const BUNDLED_ZIP_PATH = 'ipms_payment_helper/duronto-payment-helper.zip';

    private const SOURCE_MANIFEST = 'ipms_payment_helper/duronto-payment-helper/manifest.json';

    private const SOURCE_EXTENSION_ID = 'ipms_payment_helper/extension_id.txt';

    public function show(): Response
    {
        $manifest = $this->manifest();
        $disk = Storage::disk('public');
        $bundledPath = base_path(self::BUNDLED_ZIP_PATH);
        $exists = $disk->exists(self::ZIP_PATH) || is_file($bundledPath);
        $size = $disk->exists(self::ZIP_PATH) ? $disk->size(self::ZIP_PATH) : (@filesize($bundledPath) ?: 0);
        $checksum = $disk->exists(self::ZIP_PATH)
            ? hash('sha256', $disk->get(self::ZIP_PATH))
            : (is_file($bundledPath) ? hash_file('sha256', $bundledPath) : null);

        return Inertia::render('PaymentHelper/Landing', [
            'app' => [
                'name' => $manifest['name'] ?? 'DURONTO IVAC Payment Helper',
                'version' => $manifest['version'] ?? null,
                'description' => $manifest['description'] ?? null,
                'author' => $manifest['author'] ?? 'DURONTO',
                'logo_url' => asset('images/duronto-logo.svg'),
                'extension_id' => $this->extensionId(),
            ],
            'file' => $exists ? [
                'name' => 'duronto-payment-helper.zip',
                'size' => $size,
                'checksum_sha256' => $checksum,
            ] : null,
            'downloadUrl' => route('payment-helper.download', absolute: true),
        ]);
    }

    public function download(): BinaryFileResponse|StreamedResponse
    {
        $disk = Storage::disk('public');
        if (! $disk->exists(self::ZIP_PATH)) {
            $bundledPath = base_path(self::BUNDLED_ZIP_PATH);
            abort_unless(is_file($bundledPath), 404);

            return response()->download($bundledPath, 'duronto-payment-helper.zip', [
                'Content-Type' => 'application/zip',
            ]);
        }

        return $disk->download(self::ZIP_PATH, 'duronto-payment-helper.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $path = base_path(self::SOURCE_MANIFEST);
        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?? [];
    }

    private function extensionId(): ?string
    {
        $path = base_path(self::SOURCE_EXTENSION_ID);
        if (! is_file($path)) {
            return null;
        }

        return trim((string) file_get_contents($path)) ?: null;
    }
}
