<?php

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Exercises the real encrypt sidecar (app/Scripts/captcha_encrypt_server.cjs) against
 * synthetic bundle/meta files in a temp dir. Proves the unchanged-bundle fast path
 * refreshes only the meta and skips the (otherwise ~1s) bundle re-evaluation, while a
 * genuine content change still triggers a full reload that re-exposes the new modules.
 *
 * The sidecar is a Node process, so these assertions read its stdout log and /health
 * output rather than timing (a tiny synthetic bundle re-evals in ms, so wall time cannot
 * distinguish the two paths — the behavioral markers can).
 */

/**
 * Minimal bundle whose text matches the runtime MODULE_RE, so exactly one encrypt module
 * (named $moduleVar) is exposed. The extra $tag byte changes the file bytes (and thus the
 * sha256) between versions without changing the exposed module when we want a pure content
 * change.
 */
function fakeSidecarBundle(string $moduleVar, string $tag = ''): string
{
    return "/* {$tag} */var d0=function(t){return t;},e0=function(t){return t;};\n"
        ."var {$moduleVar}=Object.freeze(Object.defineProperty({__proto__:null,decryptText:d0,encryptText:e0},Symbol.toStringTag,{value:\"Module\"}));\n";
}

function freeLocalPort(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = stream_socket_get_name($sock, false);
    fclose($sock);

    return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * @return array<string, mixed>|null the /health body once ok, or null on timeout
 */
function waitForSidecarHealth(int $port, float $timeoutSec = 20.0): ?array
{
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        try {
            $res = Http::timeout(2)->get("http://127.0.0.1:{$port}/health");
            if ($res->ok() && $res->json('ok') === true) {
                return $res->json();
            }
        } catch (\Throwable $e) {
            // sidecar not up yet
        }
        usleep(150_000);
    }

    return null;
}

it('skips re-evaluating an unchanged bundle on reload but reloads on a real content change', function () {
    $node = (new Process(['node', '--version']));
    $node->run();
    if (! $node->isSuccessful()) {
        test()->markTestSkipped('node is not available in this environment');
    }

    $dir = sys_get_temp_dir().'/ipms-sidecar-'.bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    $bundlePath = $dir.'/ivac-bundle.js';
    $metaPath = $dir.'/encrypt_meta.json';

    file_put_contents($bundlePath, fakeSidecarBundle('AA', 'v1'));
    file_put_contents($metaPath, json_encode(['login' => ['module' => 'AA', 'skip' => 0, 'enc_len' => 0]]));

    $port = freeLocalPort();
    $proc = new Process(['node', base_path('app/Scripts/captcha_encrypt_server.cjs')], base_path(), [
        'CAPTCHA_SIDECAR_PORT' => (string) $port,
        'CAPTCHA_SIDECAR_HOST' => '127.0.0.1',
        'CAPTCHA_BUNDLE_PATH' => $bundlePath,
        'CAPTCHA_META_PATH' => $metaPath,
        'PATH' => getenv('PATH') ?: '/usr/bin:/usr/local/bin',
    ]);

    try {
        $proc->start();

        $health = waitForSidecarHealth($port);
        expect($health)->not->toBeNull();
        $firstHash = $health['bundle_hash'];
        expect($health['modules'])->toContain('AA');

        // Reload with the bundle unchanged -> fast path: meta-only refresh, no re-eval.
        $reload1 = Http::timeout(20)->post("http://127.0.0.1:{$port}/reload");
        expect($reload1->ok())->toBeTrue();
        expect($reload1->json('bundle_hash'))->toBe($firstHash);

        // Change the bundle bytes for real -> full reload path, new hash + new module.
        file_put_contents($bundlePath, fakeSidecarBundle('BB', 'v2-different-bytes'));
        $reload2 = Http::timeout(20)->post("http://127.0.0.1:{$port}/reload");
        expect($reload2->ok())->toBeTrue();
        expect($reload2->json('bundle_hash'))->not->toBe($firstHash);

        $health2 = Http::timeout(2)->get("http://127.0.0.1:{$port}/health")->json();
        expect($health2['bundle_hash'])->not->toBe($firstHash);
        expect($health2['modules'])->toContain('BB');

        // The stdout log is the direct proof of which path each reload took.
        $out = $proc->getOutput();
        expect($out)->toContain('meta-only refresh, skipped re-eval');
        expect($out)->toContain('loaded bundle '.substr((string) $reload2->json('bundle_hash'), 0, 12));
    } finally {
        if ($proc->isRunning()) {
            $proc->stop(2);
        }
        @unlink($bundlePath);
        @unlink($metaPath);
        @rmdir($dir);
    }
});

it('stages a candidate bundle without touching live, then promotes it instantly', function () {
    $node = (new Process(['node', '--version']));
    $node->run();
    if (! $node->isSuccessful()) {
        test()->markTestSkipped('node is not available in this environment');
    }

    $dir = sys_get_temp_dir().'/ipms-sidecar-'.bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    $bundlePath = $dir.'/ivac-bundle.js';
    $metaPath = $dir.'/encrypt_meta.json';

    file_put_contents($bundlePath, fakeSidecarBundle('AA', 'v1'));
    file_put_contents($metaPath, json_encode(['login' => ['module' => 'AA', 'skip' => 0, 'enc_len' => 0]]));

    $port = freeLocalPort();
    $proc = new Process(['node', base_path('app/Scripts/captcha_encrypt_server.cjs')], base_path(), [
        'CAPTCHA_SIDECAR_PORT' => (string) $port,
        'CAPTCHA_SIDECAR_HOST' => '127.0.0.1',
        'CAPTCHA_BUNDLE_PATH' => $bundlePath,
        'CAPTCHA_META_PATH' => $metaPath,
        'PATH' => getenv('PATH') ?: '/usr/bin:/usr/local/bin',
    ]);

    try {
        $proc->start();

        $health = waitForSidecarHealth($port);
        expect($health)->not->toBeNull();
        $liveHash = $health['bundle_hash'];
        expect($health['modules'])->toContain('AA');
        expect($health['staged_hash'] ?? null)->toBeNull();

        // Change the on-disk bundle to a different module. Live must stay on AA: staging
        // pre-evaluates the candidate into a separate slot without disturbing the encryptor.
        file_put_contents($bundlePath, fakeSidecarBundle('BB', 'v2-staged-bytes'));

        $stage = Http::timeout(5)->post("http://127.0.0.1:{$port}/stage");
        expect($stage->status())->toBe(202);

        // Wait for the background eval to populate the staging slot.
        $stagedHash = null;
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $h = Http::timeout(2)->get("http://127.0.0.1:{$port}/health")->json();
            if (! empty($h['staged_hash'])) {
                $stagedHash = $h['staged_hash'];
                break;
            }
            usleep(100_000);
        }
        expect($stagedHash)->not->toBeNull('staging slot never populated');
        expect($stagedHash)->not->toBe($liveHash);

        // Live is UNCHANGED while a candidate is staged.
        $duringStage = Http::timeout(2)->get("http://127.0.0.1:{$port}/health")->json();
        expect($duringStage['bundle_hash'])->toBe($liveHash);
        expect($duringStage['modules'])->toContain('AA');
        expect($duringStage['modules'])->not->toContain('BB');

        // Promote the staged bundle -> instant swap, new module live, staging cleared.
        $promote = Http::timeout(30)->acceptJson()->post("http://127.0.0.1:{$port}/promote", ['bundle_hash' => $stagedHash]);
        expect($promote->ok())->toBeTrue();
        expect($promote->json('promoted'))->toBeTrue();
        $afterPromote = Http::timeout(2)->get("http://127.0.0.1:{$port}/health")->json();
        expect($afterPromote['bundle_hash'])->toBe($stagedHash);
        expect($afterPromote['modules'])->toContain('BB');
        expect($afterPromote['staged_hash'] ?? null)->toBeNull();

        // Promote with a non-matching hash (no staged candidate) falls back to a full reload
        // from disk — never worse than the old /reload path.
        file_put_contents($bundlePath, fakeSidecarBundle('CC', 'v3-fallback'));
        $fallback = Http::timeout(30)->acceptJson()->post("http://127.0.0.1:{$port}/promote", ['bundle_hash' => 'deadbeefdeadbeef']);
        expect($fallback->ok())->toBeTrue();
        expect($fallback->json('promoted'))->toBeFalse();
        $afterFallback = Http::timeout(2)->get("http://127.0.0.1:{$port}/health")->json();
        expect($afterFallback['modules'])->toContain('CC');

        // Log proves the intended paths: a live-untouched stage + an instant (no re-eval) promote.
        $out = $proc->getOutput();
        expect($out)->toContain('staged bundle '.substr((string) $stagedHash, 0, 12).' modules=[BB] (live untouched)');
        expect($out)->toContain('promoted staged bundle '.substr((string) $stagedHash, 0, 12).' (instant, no re-eval)');
    } finally {
        if ($proc->isRunning()) {
            $proc->stop(2);
        }
        @unlink($bundlePath);
        @unlink($metaPath);
        @rmdir($dir);
    }
});

it('skips staging a bundle that is already live or already staged', function () {
    $node = (new Process(['node', '--version']));
    $node->run();
    if (! $node->isSuccessful()) {
        test()->markTestSkipped('node is not available in this environment');
    }

    $dir = sys_get_temp_dir().'/ipms-sidecar-'.bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    $bundlePath = $dir.'/ivac-bundle.js';
    $metaPath = $dir.'/encrypt_meta.json';

    file_put_contents($bundlePath, fakeSidecarBundle('AA', 'v1'));
    file_put_contents($metaPath, json_encode(['login' => ['module' => 'AA', 'skip' => 0, 'enc_len' => 0]]));

    $port = freeLocalPort();
    $proc = new Process(['node', base_path('app/Scripts/captcha_encrypt_server.cjs')], base_path(), [
        'CAPTCHA_SIDECAR_PORT' => (string) $port,
        'CAPTCHA_SIDECAR_HOST' => '127.0.0.1',
        'CAPTCHA_BUNDLE_PATH' => $bundlePath,
        'CAPTCHA_META_PATH' => $metaPath,
        'PATH' => getenv('PATH') ?: '/usr/bin:/usr/local/bin',
    ]);

    try {
        $proc->start();

        $health = waitForSidecarHealth($port);
        expect($health)->not->toBeNull();
        $liveHash = $health['bundle_hash'];

        // Staging blocks Node's single event loop for ~1-2s on the real 2 MB bundle, which
        // stalls /encrypt and any queued /promote. Analysis re-runs on an unchanged bundle
        // (the common case) must therefore not stage at all.
        $stage = Http::timeout(5)->acceptJson()->post("http://127.0.0.1:{$port}/stage");
        expect($stage->status())->toBe(200);
        expect($stage->json('staging'))->toBeFalse();
        expect($stage->json('reason'))->toBe('already-live');

        $afterSkip = Http::timeout(2)->get("http://127.0.0.1:{$port}/health")->json();
        expect($afterSkip['staged_hash'] ?? null)->toBeNull();
        expect($afterSkip['bundle_hash'])->toBe($liveHash);

        // A promote for the live bundle still succeeds, via load()'s unchanged fast path.
        $promote = Http::timeout(30)->acceptJson()->post("http://127.0.0.1:{$port}/promote", ['bundle_hash' => $liveHash]);
        expect($promote->ok())->toBeTrue();
        expect(Http::timeout(2)->get("http://127.0.0.1:{$port}/health")->json('bundle_hash'))->toBe($liveHash);

        // A genuine content change must still stage.
        file_put_contents($bundlePath, fakeSidecarBundle('BB', 'v2'));
        expect(Http::timeout(5)->acceptJson()->post("http://127.0.0.1:{$port}/stage")->json('staging'))->toBeTrue();

        $stagedHash = null;
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $h = Http::timeout(2)->get("http://127.0.0.1:{$port}/health")->json();
            if (! empty($h['staged_hash'])) {
                $stagedHash = $h['staged_hash'];
                break;
            }
            usleep(100_000);
        }
        expect($stagedHash)->not->toBeNull('staging slot never populated');

        // Re-staging the same bytes is a no-op rather than a second eval.
        $again = Http::timeout(5)->acceptJson()->post("http://127.0.0.1:{$port}/stage");
        expect($again->json('staging'))->toBeFalse();
        expect($again->json('reason'))->toBe('already-staged');

        $out = $proc->getOutput();
        expect($out)->toContain('stage skipped: '.substr((string) $liveHash, 0, 12).' is already live');
        expect($out)->toContain('stage skipped: '.substr((string) $stagedHash, 0, 12).' is already staged');
    } finally {
        if ($proc->isRunning()) {
            $proc->stop(2);
        }
        @unlink($bundlePath);
        @unlink($metaPath);
        @rmdir($dir);
    }
});
