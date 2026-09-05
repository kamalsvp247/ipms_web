<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public landing + download for the DURONTO IVAC Payment Helper browser extension.
 * The extension captures the IVAC dg-epay payment callback URL in the browser and
 * POSTs it to the portal's public redirect-url ingest endpoint (see PaymentLinkController).
 */
class PaymentHelperController extends Controller
{
    private const ZIP_PATH = 'extensions/duronto-payment-helper.zip';

    private const SOURCE_MANIFEST = 'ipms_payment_helper/duronto-payment-helper/manifest.json';

    private const SOURCE_EXTENSION_ID = 'ipms_payment_helper/extension_id.txt';

    public function show(): Response
    {
        $manifest = $this->manifest();
        $disk = Storage::disk('public');
        $exists = $disk->exists(self::ZIP_PATH);

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
                'size' => $disk->size(self::ZIP_PATH),
                'checksum_sha256' => hash('sha256', $disk->get(self::ZIP_PATH)),
            ] : null,
            'downloadUrl' => route('payment-helper.download', absolute: true),
        ]);
    }

    public function download(): StreamedResponse
    {
        $disk = Storage::disk('public');
        abort_unless($disk->exists(self::ZIP_PATH), 404);

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
