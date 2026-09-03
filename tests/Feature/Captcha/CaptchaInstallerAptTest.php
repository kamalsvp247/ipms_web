<?php

/**
 * The installers run under `set -euo pipefail`, so a non-zero `apt-get update` aborts the
 * whole run. On a rented VPS a stale third-party source (speedtest-cli, docker, ...) makes
 * apt exit 100 even when every Ubuntu source refreshed fine, which silently killed the
 * install right after the banner. These tests pin the tolerance and the real-failure path.
 */
function installerScratch(string $name): string
{
    $dir = sys_get_temp_dir().'/ipms-installer-test-'.getmypid().'/'.$name;

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

/**
 * Runs an installer's prerequisite section with a stubbed apt-get on PATH.
 *
 * @return array{exit:int, output:string}
 */
function runPrerequisiteBlock(string $script, string $aptStub): array
{
    $lines = file(base_path($script), FILE_IGNORE_NEW_LINES);
    $body = [];
    $inBlock = false;

    foreach ($lines as $line) {
        if (str_starts_with($line, 'GREEN=') || str_starts_with($line, 'log()') || str_starts_with($line, 'warn()') || str_starts_with($line, 'die()')) {
            $body[] = $line;

            continue;
        }

        if (str_contains($line, '── Prerequisites ──')) {
            $inBlock = true;

            continue;
        }

        if ($inBlock && preg_match('/^# ── (?!Prerequisites)/', $line)) {
            break;
        }

        if ($inBlock) {
            $body[] = $line;
        }
    }

    $dir = installerScratch(md5($script.$aptStub));
    file_put_contents($dir.'/apt-get', $aptStub);
    chmod($dir.'/apt-get', 0755);

    $runner = $dir.'/block.sh';
    file_put_contents($runner, "set -euo pipefail\n".implode("\n", $body)."\n");

    $output = [];
    $exit = 0;
    exec('PATH='.escapeshellarg($dir).':$PATH bash '.escapeshellarg($runner).' 2>&1', $output, $exit);

    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

$brokenRepoStub = <<<'SH'
#!/usr/bin/env bash
if [[ "$1" == "update" ]]; then
    echo "E: The repository 'https://packagecloud.io/ookla/speedtest-cli/ubuntu noble Release' does not have a Release file." >&2
    exit 100
fi
exit 0
SH;

$missingPackageStub = <<<'SH'
#!/usr/bin/env bash
[[ "$1" == "update" ]] && exit 0
echo "E: Unable to locate package libnss3" >&2
exit 100
SH;

it('continues past a broken third-party apt source when installing a captcha node', function () use ($brokenRepoStub) {
    $result = runPrerequisiteBlock('public/captcha-install.sh', $brokenRepoStub);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('speedtest-cli');
});

it('fails loudly and shows apt output when a Chrome dependency cannot be installed', function () use ($missingPackageStub) {
    $result = runPrerequisiteBlock('public/captcha-install.sh', $missingPackageStub);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('Unable to locate package libnss3')
        ->and($result['output'])->toContain("Could not install Chrome's shared library dependencies");
});

it('continues past a broken third-party apt source when installing a bot worker', function () use ($brokenRepoStub) {
    $result = runPrerequisiteBlock('public/install.sh', $brokenRepoStub);

    expect($result['exit'])->toBe(0);
});
