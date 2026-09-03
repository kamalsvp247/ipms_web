<?php

use Symfony\Component\Process\Process;

/**
 * Covers the /dev/dri guard in app/Scripts/in_house_captcha_solver.cjs.
 *
 * A node on a GPU host reports zero solves with no error and no 403 — the failure is
 * silent and reads exactly like IP reputation, so the guard has to be exercised rather
 * than trusted. The solver is required as a module (side-effect free unless run as main)
 * with fs and child_process stubbed, so nothing here touches real systemd.
 */
function runGpuGuardHarness(array $env, string $js): array
{
    $harness = <<<JS
    const Module = require('module');
    const realFs = require('fs');
    const realCp = require('child_process');

    const calls = [];
    const files = {};
    const dirs = new Set(process.env.FAKE_DRI === '1' ? ['/dev/dri'] : []);

    // Only the paths the guard touches are faked; everything else falls through, because
    // requiring the solver reads real files of its own.
    const fsStub = Object.assign(Object.create(realFs), {
        existsSync: (p) => (String(p).startsWith('/dev/dri') || String(p).includes('.service.d'))
            ? (dirs.has(String(p)) || files[String(p)] !== undefined)
            : realFs.existsSync(p),
        readdirSync: (p) => String(p) === '/dev/dri' ? ['card0', 'renderD128'] : realFs.readdirSync(p),
        mkdirSync: (p, o) => String(p).includes('.service.d') ? dirs.add(String(p)) : realFs.mkdirSync(p, o),
        writeFileSync: (p, d) => {
            if (String(p).includes('.service.d')) { files[String(p)] = d; return; }
            return realFs.writeFileSync(p, d);
        },
    });

    const cpStub = Object.assign(Object.create(realCp), {
        execFile: (cmd, args, cb) => { calls.push([cmd, ...args].join(' ')); if (cb) cb(null); },
    });

    const origLoad = Module._load;
    Module._load = function (request, parent, isMain) {
        if (request === 'fs') return fsStub;
        if (request === 'child_process') return cpStub;
        return origLoad.apply(this, arguments);
    };

    const solver = require(process.argv[1]);
    Module._load = origLoad;

    const out = (o) => process.stdout.write('\\n<<<JSON>>>' + JSON.stringify(o));
    {$js}
    JS;

    $process = new Process(
        ['node', '-e', $harness, base_path('app/Scripts/in_house_captcha_solver.cjs')],
        null,
        array_merge(['CAPTCHA_NODE_SERVICE' => 'ipms-captcha-node'], $env),
    );
    $process->setTimeout(30);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $output = $process->getOutput();
    $marker = strrpos($output, '<<<JSON>>>');

    expect($marker)->not->toBeFalse('harness produced no JSON payload: '.$output);

    return json_decode(trim(substr($output, $marker + 10)), true);
}

it('writes the drop-in and restarts when the host exposes a GPU', function () {
    $result = runGpuGuardHarness(
        ['FAKE_DRI' => '1', 'CAPTCHA_NODE_SELF_UPDATE' => '1'],
        <<<'JS'
        const restarted = solver.ensureDevicesHidden();
        out({ restarted, calls, conf: files['/etc/systemd/system/ipms-captcha-node.service.d/private-devices.conf'] });
        JS
    );

    expect($result['restarted'])->toBeTrue();
    expect($result['conf'])->toContain('PrivateDevices=yes');

    // daemon-reload must precede the restart, or systemd restarts the unit it already had.
    expect($result['calls'][0])->toBe('systemctl daemon-reload');
    expect($result['calls'][1])->toBe('systemctl restart ipms-captcha-node');
});

it('does nothing on a host with no GPU', function () {
    $result = runGpuGuardHarness(
        ['FAKE_DRI' => '0', 'CAPTCHA_NODE_SELF_UPDATE' => '1'],
        <<<'JS'
        const restarted = solver.ensureDevicesHidden();
        out({ restarted, calls, files: Object.keys(files) });
        JS
    );

    // The healthy majority of the fleet must not be restarted by this.
    expect($result['restarted'])->toBeFalse();
    expect($result['calls'])->toBe([]);
    expect($result['files'])->toBe([]);
});

it('does not restart twice once the drop-in is already applied', function () {
    $result = runGpuGuardHarness(
        ['FAKE_DRI' => '1', 'CAPTCHA_NODE_SELF_UPDATE' => '1'],
        <<<'JS'
        const first = solver.ensureDevicesHidden();
        const second = solver.ensureDevicesHidden();
        out({ first, second, calls: calls.length });
        JS
    );

    // Without the file guard a restart loop takes the node off the fleet entirely.
    expect($result['first'])->toBeTrue();
    expect($result['second'])->toBeFalse();
    expect($result['calls'])->toBe(2);
});

it('leaves the portal checkout alone', function () {
    $result = runGpuGuardHarness(
        ['FAKE_DRI' => '1', 'CAPTCHA_NODE_SELF_UPDATE' => '0'],
        <<<'JS'
        const restarted = solver.ensureDevicesHidden();
        out({ restarted, calls });
        JS
    );

    // The portal host's unit is managed from its own drop-in and is not what this repairs.
    expect($result['restarted'])->toBeFalse();
    expect($result['calls'])->toBe([]);
});
