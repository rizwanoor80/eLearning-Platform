<?php

use App\Exceptions\OpsStatusTokenException;
use App\Services\Ops\AllowedIps;

/**
 * R193: the status token's allow-list parser. Pure string handling, no application needed.
 */
it('splits on lines, commas, semicolons and spaces, and drops blanks and repeats', function () {
    expect(AllowedIps::parse("203.0.113.0/24\n198.51.100.7, 198.51.100.7;  2001:db8::/32\r\n\r\n"))
        ->toBe(['203.0.113.0/24', '198.51.100.7', '2001:db8::/32']);
});

it('reads nothing as an empty list, meaning any address', function () {
    expect(AllowedIps::parse(null))->toBe([])
        ->and(AllowedIps::parse(''))->toBe([])
        ->and(AllowedIps::parse(" \n , ; "))->toBe([]);
});

it('accepts addresses and ranges, v4 and v6', function (string $entry) {
    expect(AllowedIps::isValidEntry($entry))->toBeTrue();
})->with(['198.51.100.7', '0.0.0.0/0', '203.0.113.0/24', '10.0.0.1/32', '::1', '2001:db8::1', '2001:db8::/32', '::/0', '2001:db8::1/128']);

it('refuses anything else', function (string $entry) {
    expect(AllowedIps::isValidEntry($entry))->toBeFalse();
})->with(['not-an-address', '256.1.1.1', '1.2.3', '203.0.113.0/33', '2001:db8::/129', '203.0.113.0/', '203.0.113.0/-1', '203.0.113.0/2a', '/24', 'example.com', '203.0.113.0/24/8']);

it('names the first bad entry, trimmed to a sane length', function () {
    expect(fn () => AllowedIps::parse("203.0.113.1\nbogus\nalso-bogus"))
        ->toThrow(OpsStatusTokenException::class, '"bogus" is not an IP address or a CIDR range.');

    expect(fn () => AllowedIps::parse(str_repeat('x', 500)))
        ->toThrow(OpsStatusTokenException::class, '"'.str_repeat('x', 60).'" is not');
});

it('refuses a list longer than the cap', function () {
    // The literal 20 is the point: a test built from the constant would stay green if the cap were raised.
    $ok = implode(' ', array_map(fn (int $i) => "198.51.100.$i", range(1, 20)));
    $tooMany = $ok.' 198.51.100.200';

    expect(AllowedIps::MAX_ENTRIES)->toBe(20)
        ->and(AllowedIps::parse($ok))->toHaveCount(20)
        ->and(fn () => AllowedIps::parse($tooMany))->toThrow(OpsStatusTokenException::class, 'At most');
});
