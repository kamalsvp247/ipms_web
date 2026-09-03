<?php

use App\Models\Account;
use App\Models\AccountPdf;
use App\Models\User;
use App\Services\Pdf\PdfOptimizer;
use Symfony\Component\Process\ExecutableFinder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Builds a valid single-page PDF whose only content is a large DeviceRGB image stored with
 * FlateDecode — mirroring the real application-form PDFs where a raw-RGB image dominates the file.
 * With `$noise` the pixels are high-entropy so the JPEG copy stays comfortably above the optimizer's
 * 50 KB floor (still far smaller than the raw original); without it the smooth gradient crushes to a
 * few KB, exercising the below-floor path. Returns Base64.
 */
function imageHeavyPdfBase64(int $w = 700, int $h = 900, bool $noise = true): string
{
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($noise) {
                $r = (($x * 131 + $y * 57) ^ ($x * $y)) & 255;
                $g = ($x * 17 + $y * 199) & 255;
                $b = (($x ^ $y) * 73) & 255;
            } else {
                $r = (int) (128 + 120 * sin($x / 25));
                $g = (int) (128 + 120 * sin($y / 25));
                $b = (int) (128 + 120 * sin(($x + $y) / 25));
            }
            $raw .= chr($r & 255).chr($g & 255).chr($b & 255);
        }
    }
    $stream = gzcompress($raw, 6);

    $objs = [];
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objs[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] "
        ."/Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>";
    $objs[4] = "<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB "
        .'/BitsPerComponent 8 /Filter /FlateDecode /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
    $content = "q $w 0 0 $h 0 0 cm /Im0 Do Q";
    $objs[5] = '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $n => $body) {
        $offsets[$n] = strlen($pdf);
        $pdf .= "$n 0 obj\n$body\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objs) + 1;
    $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
    foreach ($objs as $n => $body) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
    }
    $pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

    return base64_encode($pdf);
}

function ghostscriptAvailable(): bool
{
    return (new ExecutableFinder)->find('gs') !== null;
}

it('returns null for input that is not a PDF', function () {
    expect(app(PdfOptimizer::class)->optimize(base64_encode('not a pdf')))->toBeNull();
    expect(app(PdfOptimizer::class)->optimize('%%%not-base64%%%'))->toBeNull();
});

it('shrinks an image-heavy PDF while keeping it a valid single-page PDF', function () {
    if (! ghostscriptAvailable()) {
        $this->markTestSkipped('ghostscript (gs) not installed');
    }

    $original = imageHeavyPdfBase64();
    $result = app(PdfOptimizer::class)->optimize($original);

    expect($result)->not->toBeNull();
    expect($result['optimized_size'])->toBeLessThan($result['original_size']);
    expect($result['original_size'])->toBe((int) (strlen(base64_decode($original))));

    $optimizedBytes = base64_decode($result['base64']);
    expect(str_starts_with($optimizedBytes, '%PDF-'))->toBeTrue();
    // Materially smaller than the raw original, but never below the 50 KB floor IVAC rejects.
    expect($result['optimized_size'])->toBeGreaterThanOrEqual(50 * 1024);
});

it('keeps the original when compression would drop below the 50 KB floor', function () {
    if (! ghostscriptAvailable()) {
        $this->markTestSkipped('ghostscript (gs) not installed');
    }

    // A smooth-gradient image crushes to a few KB under JPEG — below the floor, so it is rejected.
    $result = app(PdfOptimizer::class)->optimize(imageHeavyPdfBase64(260, 340, noise: false));

    expect($result)->toBeNull();
});

it('populates optimized_base64 when an account PDF is stored', function () {
    if (! ghostscriptAvailable()) {
        $this->markTestSkipped('ghostscript (gs) not installed');
    }

    $user = User::factory()->create(['role' => 'super_admin']);

    actingAs($user, 'sanctum')->postJson('/api/accounts', [
        'phone' => '01900000001',
        'password' => 'secret',
        'booking_city' => 'Dhaka',
        'pdfs' => [
            ['name' => 'form.pdf', 'base64' => imageHeavyPdfBase64(), 'is_primary' => true],
        ],
    ])->assertSuccessful();

    $pdf = Account::where('phone', '01900000001')->first()->pdfs()->first();
    expect($pdf->optimized_base64)->not->toBeNull();
    expect($pdf->optimized_size)->toBeLessThan($pdf->original_size);
    // Pristine original is preserved; the bot-facing value prefers the optimized copy.
    expect($pdf->deliverable_base64)->toBe($pdf->optimized_base64);
});

it('serves the optimized copy to the bot but the pristine original to the edit modal', function () {
    if (! ghostscriptAvailable()) {
        $this->markTestSkipped('ghostscript (gs) not installed');
    }

    $slot = \App\Models\AgentSlot::create([
        'name' => 'slot-'.uniqid(),
        'api_key' => 'key-'.uniqid(),
        'status' => 'online',
    ]);
    $user = User::factory()->create(['role' => 'super_admin']);
    $original = imageHeavyPdfBase64();
    $account = Account::factory()->for($user)->create(['agent_slot_id' => $slot->id]);
    $optimized = app(PdfOptimizer::class)->optimize($original);
    $account->pdfs()->create([
        'name' => 'form.pdf',
        'base64' => $original,
        'optimized_base64' => $optimized['base64'],
        'is_primary' => true,
    ]);

    // Bot (slot auth) gets the smaller optimized copy.
    $botBase64 = getJson("/api/accounts/{$account->id}/pdfs", ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->json('pdfs.0.base64');
    expect($botBase64)->toBe($optimized['base64']);

    // Edit modal (user auth) gets the pristine original, so a re-save never double-compresses.
    actingAs($user, 'sanctum')->getJson("/api/accounts/{$account->id}")
        ->assertSuccessful()
        ->assertJsonPath('pdfs.0.base64', $original);
});

it('backfills existing PDFs via pdfs:optimize', function () {
    if (! ghostscriptAvailable()) {
        $this->markTestSkipped('ghostscript (gs) not installed');
    }

    $user = User::factory()->create(['role' => 'super_admin']);
    $account = Account::factory()->for($user)->create();
    $pdf = $account->pdfs()->create([
        'name' => 'form.pdf',
        'base64' => imageHeavyPdfBase64(),
        'is_primary' => true,
    ]);
    expect($pdf->optimized_base64)->toBeNull();

    artisan('pdfs:optimize')->assertSuccessful();

    $pdf->refresh();
    expect($pdf->optimized_base64)->not->toBeNull();
    expect($pdf->optimized_size)->toBeLessThan($pdf->original_size);
});

it('reverts a stale below-floor optimized copy on a forced re-optimize', function () {
    if (! ghostscriptAvailable()) {
        $this->markTestSkipped('ghostscript (gs) not installed');
    }

    $user = User::factory()->create(['role' => 'super_admin']);
    $account = Account::factory()->for($user)->create();
    // A smooth-gradient PDF whose optimized copy is below the current 50 KB floor, simulating a copy
    // made before the floor existed.
    $pdf = $account->pdfs()->create([
        'name' => 'form.pdf',
        'base64' => imageHeavyPdfBase64(260, 340, noise: false),
        'optimized_base64' => base64_encode('%PDF-1.4 stale tiny copy'),
        'optimized_size' => 24,
        'is_primary' => true,
    ]);

    artisan('pdfs:optimize --force')->assertSuccessful();

    $pdf->refresh();
    // Cleared, so the bot falls back to the pristine original rather than a too-small file.
    expect($pdf->optimized_base64)->toBeNull();
    expect($pdf->optimized_size)->toBeNull();
    expect($pdf->deliverable_base64)->toBe($pdf->base64);
});
