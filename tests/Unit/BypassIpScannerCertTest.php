<?php

use App\Services\BypassIpScanner;

test('cert matching accepts a leaf certificate issued for ivacbd via Subject', function () {
    $certInfo = [
        ['Subject' => 'CN = *.ivacbd.com'],
    ];

    expect(BypassIpScanner::certMatchesOrigin($certInfo))->toBeTrue();
});

test('cert matching accepts a leaf certificate issued for ivacbd via SAN', function () {
    $certInfo = [
        [
            'Subject' => 'CN = something-else.example.com',
            'X509v3 Subject Alternative Name' => 'DNS:*.ivacbd.com, DNS:ivacbd.com',
        ],
    ];

    expect(BypassIpScanner::certMatchesOrigin($certInfo))->toBeTrue();
});

test('cert matching rejects an unrelated AWS ELB certificate', function () {
    $certInfo = [
        [
            'Subject' => 'CN = *.osstem.com',
            'X509v3 Subject Alternative Name' => 'DNS:*.osstem.com, DNS:osstem.com',
        ],
    ];

    expect(BypassIpScanner::certMatchesOrigin($certInfo))->toBeFalse();
});

test('cert matching rejects empty or missing certificate info', function () {
    expect(BypassIpScanner::certMatchesOrigin(null))->toBeFalse()
        ->and(BypassIpScanner::certMatchesOrigin([]))->toBeFalse();
});
