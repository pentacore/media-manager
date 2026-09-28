<?php

declare(strict_types=1);

use App\Support\TrustedProxyRanges;

test('it flags wildcards as broad', function (): void {
    expect(TrustedProxyRanges::broadEntries('*'))->toBe(['*'])
        ->and(TrustedProxyRanges::broadEntries(['**']))->toBe(['**']);
});

test('it flags the Symfony PRIVATE_SUBNETS and REMOTE_ADDR keywords as broad, case-insensitively', function (): void {
    expect(TrustedProxyRanges::broadEntries(['PRIVATE_SUBNETS']))->toBe(['PRIVATE_SUBNETS'])
        ->and(TrustedProxyRanges::broadEntries(['private_subnets']))->toBe(['private_subnets'])
        ->and(TrustedProxyRanges::broadEntries(['REMOTE_ADDR']))->toBe(['REMOTE_ADDR'])
        ->and(TrustedProxyRanges::broadEntries(['remote_addr']))->toBe(['remote_addr'])
        ->and(TrustedProxyRanges::broadEntries(['192.168.1.10', 'PRIVATE_SUBNETS', 'remote_addr']))
        ->toBe(['PRIVATE_SUBNETS', 'remote_addr']);
});

test('it flags ranges wider than a /24 or an IPv6 /64 but not exact addresses or narrow ranges', function (): void {
    expect(TrustedProxyRanges::broadEntries([
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '192.168.1.0/24',
        '192.168.1.10',
        '192.168.1.10/32',
        'fd00::/8',
        'fd00:1:2:3::/64',
        'fd00::10',
    ]))->toBe(['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fd00::/8']);
});
