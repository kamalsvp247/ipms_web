<?php

/**
 * The Algorithm Monitor ships one copy-paste recovery prompt per failure mode of the
 * captcha pipeline (download, encryption, endpoints, request constants, redeploy
 * detection). They are the operator's whole recovery path when IVAC rotates something
 * mid-window, so a prompt that silently loses its scenario or its remediation anchors
 * is a real outage-time failure — this locks the set and each prompt's contract.
 */

function monitorPage(): string
{
    return file_get_contents(resource_path('js/pages/CaptchaAlgorithm/Index.vue'));
}

it('defines a recovery prompt for every failure mode of the analyzer pipeline', function () {
    $page = monitorPage();

    foreach (['p_download', 'p_encryption', 'p_endpoints', 'p_constants', 'p_autorefresh'] as $key) {
        expect($page)->toContain("key: '{$key}'");
    }
});

it('renders the prompts from the list so every scenario gets its own copy button', function () {
    $page = monitorPage();

    expect($page)->toContain('v-for="p in recoveryPrompts"')
        ->and($page)->toContain("copyToClipboard(p.prompt, p.key)");
});

/**
 * Each prompt has to point at the code that actually owns its failure. These anchors are
 * the ones a fix cannot avoid touching, so their absence means the prompt was rewritten
 * into something that no longer routes the reader to the right pipeline stage.
 *
 * @param  array<int, string>  $anchors
 */
it('points each prompt at the files that own its failure', function (string $key, array $anchors) {
    $page = monitorPage();
    $start = strpos($page, "key: '{$key}'");
    expect($start)->not->toBeFalse();

    // Slice to the next prompt entry so an anchor cannot be satisfied by a sibling prompt.
    $next = strpos($page, "\n{\n    key: '", $start);
    $section = substr($page, $start, $next === false ? 6000 : $next - $start);

    foreach ($anchors as $anchor) {
        expect($section)->toContain($anchor);
    }
})->with([
    ['p_download', ['IvacEdgeBundleFetcher.php', 'CURLOPT_SSL_ENABLE_ALPN', 'IvacEdgeBundleFetcherTest.php']],
    ['p_encryption', ['captcha_encrypt_server.cjs', 'ANALYSIS_CACHE_VERSION', 'CaptchaAlgorithmCorpusTest']],
    ['p_endpoints', ['extract_request_constants.cjs', 'PublicConfigController.php', 'IvacEndpointsConfigTest.php']],
    ['p_constants', ['syncReserveSlotId', 'syncRequestConstants', 'RequestConstantsExtractorTest.php']],
    ['p_autorefresh', ['RefreshCaptchaAlgorithmCommand.php', 'inBookingWindow', 'schedule:run']],
]);

/**
 * Every prompt must drive the same three-stage outcome the operator needs: fix the
 * failure, bring the portal back to a usable state, then prove it with tests. A prompt
 * that only diagnoses leaves the portal broken at exactly the wrong moment.
 */
it('makes every prompt fix, restore and then test', function (string $key) {
    $page = monitorPage();
    $start = strpos($page, "key: '{$key}'");
    $next = strpos($page, "\n{\n    key: '", $start);
    $section = substr($page, $start, $next === false ? 6000 : $next - $start);

    expect($section)->toContain('php artisan test --compact')
        ->and($section)->toContain('www-data');
})->with(['p_download', 'p_encryption', 'p_endpoints', 'p_constants', 'p_autorefresh']);

/**
 * sendOtp and uploadRuntimeState depend on a runtime variable and are not headless
 * decodable, so they are expected to stay on their seeded defaults. Without that stated
 * up front, the endpoints prompt sends the reader chasing a bug that does not exist.
 */
it('tells the endpoints prompt which keys are intentionally never extracted', function () {
    $page = monitorPage();
    $start = strpos($page, "key: 'p_endpoints'");
    $section = substr($page, $start, 6000);

    expect($section)->toContain('sendOtp')
        ->and($section)->toContain('uploadRuntimeState')
        ->and($section)->toContain('not a bug');
});
