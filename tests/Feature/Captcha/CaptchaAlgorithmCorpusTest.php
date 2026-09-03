<?php

use Symfony\Component\Process\Process;

/**
 * Regression corpus for the captcha algorithm extractor.
 *
 * IVAC rotates its bundle (version + module + secret + skip/enc_len) on almost every
 * redeploy, and rotates the SHAPE it emits the config in independently of that. Real
 * downloaded bundles are archived under storage/app/captcha/corpus, and this locks in
 * the extracted parameters across all of them, so a change to the scanner, the module
 * regex or the attribution logic that breaks any past shape is caught immediately.
 *
 * Fixtures live in storage/app/captcha/corpus (NOT .../bundles). The bundles directory
 * is retention-pruned by CaptchaBundleVersionService::pruneToLimit(), which silently ate
 * the original corpus: every fixture aged off disk and the suite degraded to 1 passed /
 * 6 skipped while still reporting green. The corpus directory is never pruned, and
 * captcha-corpus:sync adopts each newly-analyzed bundle into it.
 *
 * STRICT MODE: with CAPTCHA_CORPUS_STRICT=1 a missing fixture FAILS instead of skipping.
 * The automated extractor repair gate (ExtractorRepairService) runs the suite this way,
 * because "green with N skips" must never be mistaken for "the corpus still guards this".
 *
 * Shapes previously ground-truthed here whose bundles have since aged off disk — the
 * lessons they encode are still live requirements of the extractor:
 *   - SAME module, DIFFERENT params (login and reserve on one module at one version, but
 *     different skip/enc_len).
 *   - SAME module AND IDENTICAL params, only the SECRET differing (Jun 21 2026). Dedup by
 *     (skip, enc_len) collapsed the two sites and dropped reserve's secret. Labels must be
 *     collected BEFORE dedup and the two secrets must stay distinct.
 *   - LOGIN skip=1 (Jul 8 2026). skip < 2 pulls the "." Turnstile separator (token index 1)
 *     INTO the transform window; the algorithm passes it through unchanged because "." is
 *     not in the alphabet. The well-formedness canary must allow a non-alphabet input char
 *     to pass through while still requiring alphabet chars to map back into the alphabet.
 *   - Config trapped in an IIFE-keyed static method (Jul 9 2026): `static[fn()+…](){…}`,
 *     with a "}" inside a string literal in the secret concat. The backward brace walk must
 *     be string-aware or that "}" corrupts the depth count.
 *   - Config wrapped in a COMMA-SEQUENCE of decoy calls (Aug 11 2026): `IDENT=(d(),d(),…,{…})`
 *     so the object no longer follows the `=`. Covered live by c102f3c7 below. The object
 *     must be located structurally (innermost brace around `startAt:`) and its owner resolved
 *     by walking left out through the sequence parens.
 *
 * Keyed by the bundle file's sha256 prefix. {skip}/{enc_len}/v{version}/{module}.
 * 'secrets_distinct' is false when login and reserve share one config — common since Jul 2026.
 */
const CORPUS = [
    '0c7383b7' => [
        'login' => ['skip' => 10, 'enc_len' => 28, 'version' => 7, 'module' => 'c1'],
        'reserve' => ['skip' => 10, 'enc_len' => 28, 'version' => 7, 'module' => 'c1'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    '2ad7b1ad' => [
        'login' => ['skip' => 10, 'enc_len' => 22, 'version' => 3, 'module' => 'D$'],
        'reserve' => ['skip' => 10, 'enc_len' => 22, 'version' => 3, 'module' => 'D$'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    '39f6d546' => [
        'login' => ['skip' => 10, 'enc_len' => 19, 'version' => 2, 'module' => 'h$'],
        'reserve' => ['skip' => 10, 'enc_len' => 19, 'version' => 2, 'module' => 'h$'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    '493da738' => [
        'login' => ['skip' => 6, 'enc_len' => 20, 'version' => 5, 'module' => 'N0'],
        'reserve' => ['skip' => 6, 'enc_len' => 20, 'version' => 5, 'module' => 'N0'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    '4c7620c7' => [
        'login' => ['skip' => 5, 'enc_len' => 28, 'version' => 3, 'module' => 'q$'],
        'reserve' => ['skip' => 5, 'enc_len' => 28, 'version' => 3, 'module' => 'q$'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    '5e866412' => [
        'login' => ['skip' => 5, 'enc_len' => 24, 'version' => 8, 'module' => '_1'],
        'reserve' => ['skip' => 5, 'enc_len' => 24, 'version' => 8, 'module' => '_1'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    '66092e29' => [
        'login' => ['skip' => 8, 'enc_len' => 30, 'version' => 9, 'module' => 'e4'],
        'reserve' => ['skip' => 8, 'enc_len' => 30, 'version' => 9, 'module' => 'e4'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    '935aaf61' => [
        'login' => ['skip' => 5, 'enc_len' => 30, 'version' => 8, 'module' => 'G1'],
        'reserve' => ['skip' => 5, 'enc_len' => 30, 'version' => 8, 'module' => 'G1'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    'b83e7e81' => [
        'login' => ['skip' => 10, 'enc_len' => 21, 'version' => 5, 'module' => 'S0'],
        'reserve' => ['skip' => 10, 'enc_len' => 21, 'version' => 5, 'module' => 'S0'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    'c102f3c7' => [
        'login' => ['skip' => 5, 'enc_len' => 29, 'version' => 4, 'module' => 'c0'],
        'reserve' => ['skip' => 5, 'enc_len' => 29, 'version' => 4, 'module' => 'c0'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    'cada719f' => [
        'login' => ['skip' => 5, 'enc_len' => 22, 'version' => 7, 'module' => 'm1'],
        'reserve' => ['skip' => 5, 'enc_len' => 22, 'version' => 7, 'module' => 'm1'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    'd1c49b79' => [
        'login' => ['skip' => 7, 'enc_len' => 21, 'version' => 8, 'module' => 'M1'],
        'reserve' => ['skip' => 7, 'enc_len' => 21, 'version' => 8, 'module' => 'M1'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    'e34e39b4' => [
        'login' => ['skip' => 2, 'enc_len' => 19, 'version' => 8, 'module' => 'R1'],
        'reserve' => ['skip' => 2, 'enc_len' => 19, 'version' => 8, 'module' => 'R1'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
    'f21b2fd4' => [
        'login' => ['skip' => 6, 'enc_len' => 24, 'version' => 1, 'module' => 'FX'],
        'reserve' => ['skip' => 6, 'enc_len' => 24, 'version' => 1, 'module' => 'FX'],
        'login_has_module' => true,
        'secrets_distinct' => false,
    ],
];

/**
 * Locate an archived fixture, preferring the never-pruned corpus directory.
 */
function corpusFixturePath(string $hashPrefix): ?string
{
    foreach (['app/captcha/corpus', 'app/captcha/bundles'] as $dir) {
        $matches = glob(storage_path($dir).'/'.$hashPrefix.'*.js');
        if (! empty($matches)) {
            return $matches[0];
        }
    }

    return null;
}

/**
 * Run the analyzer's side-effect-free attribution mode on one archived bundle.
 * A missing fixture skips normally but FAILS under CAPTCHA_CORPUS_STRICT=1, so the
 * automated repair gate cannot pass on a corpus that has quietly emptied out.
 *
 * @return array<string, mixed>
 */
function attributeBundle(string $hashPrefix): array
{
    $path = corpusFixturePath($hashPrefix);

    if ($path === null) {
        $message = "archived bundle {$hashPrefix} is not on disk (run captcha-corpus:sync)";

        if (env('CAPTCHA_CORPUS_STRICT') === '1') {
            test()->fail($message);
        }

        test()->markTestSkipped($message);
    }

    $process = new Process([
        'python3', base_path('app/Scripts/analyze_captcha_algo.py'), '-', '--attribute', $path,
    ]);
    $process->setTimeout(180);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $out = json_decode(trim($process->getOutput()), true);
    expect($out)->toBeArray();

    return $out;
}

beforeEach(function () {
    foreach (['python3', 'node'] as $bin) {
        $p = new Process(['which', $bin]);
        $p->run();
        if (! $p->isSuccessful()) {
            $this->markTestSkipped("{$bin} not available");
        }
    }
});

it('attributes login and reserve correctly across the whole archived bundle corpus', function (string $hashPrefix, array $expected) {
    $out = attributeBundle($hashPrefix);

    foreach (['login', 'reserve'] as $type) {
        expect($out[$type])->not->toBeNull("{$hashPrefix}: {$type} was not attributed");
        expect($out[$type]['skip'])->toBe($expected[$type]['skip'], "{$hashPrefix} {$type} skip");
        expect($out[$type]['enc_len'])->toBe($expected[$type]['enc_len'], "{$hashPrefix} {$type} enc_len");
        expect($out[$type]['version'])->toBe($expected[$type]['version'], "{$hashPrefix} {$type} version");

        // The module IS the algorithm. Right params on the wrong module encrypts wrongly
        // while every other assertion here still passes.
        expect($out[$type]['module'])->toBe($expected[$type]['module'], "{$hashPrefix} {$type} module");
    }

    // distinct_modules is the canary for "same module mapped to two DIFFERENT versions"
    // (a dispatch inconsistency). Sharing a module at the SAME version is legitimate.
    expect($out['distinct_modules'])->toBeTrue("{$hashPrefix}: dispatch must be consistent");

    // Login and reserve must resolve to DIFFERENT secrets — UNLESS the bundle ships one
    // shared config for both. This is the core guard for the June 21 2026 regression: when
    // both sites share skip/enc_len/version, dropping one and reusing the other's secret
    // would otherwise pass every check above.
    $expectDistinct = $expected['secrets_distinct'] ?? true;
    expect($out['secrets_distinct'])->toBe($expectDistinct, "{$hashPrefix}: secrets_distinct");

    if ($expected['login_has_module']) {
        expect($out['wellformed']['login'])->toBeTrue("{$hashPrefix}: login output must be well-formed");
        expect($out['wellformed']['reserve'])->toBeTrue("{$hashPrefix}: reserve output must be well-formed");
        expect($out['extraction_ok'])->toBeTrue("{$hashPrefix}: extraction should be clean");
        expect($out['pending_rollout'])->toBe([], "{$hashPrefix}: nothing should be pending");
    } else {
        // Mid-rollout: login version not shipped — must be flagged, not silently wrong.
        expect($out['login']['module'])->toBeNull("{$hashPrefix}: login has no module yet");
        expect($out['pending_rollout'])->toContain('login');
        expect($out['wellformed']['login'])->toBeFalse("{$hashPrefix}: login cannot be well-formed without a module");
        expect($out['extraction_ok'])->toBeFalse("{$hashPrefix}: extraction must NOT be clean mid-rollout");
    }
})->with(array_map(fn ($k, $v) => [(string) $k, $v], array_keys(CORPUS), array_values(CORPUS)));

it('has every corpus fixture present on disk', function () {
    $missing = array_values(array_filter(
        array_keys(CORPUS),
        fn (string $hash) => corpusFixturePath($hash) === null
    ));

    expect($missing)->toBe([], 'missing corpus fixtures: '.implode(', ', $missing).' — run captcha-corpus:sync');
});
