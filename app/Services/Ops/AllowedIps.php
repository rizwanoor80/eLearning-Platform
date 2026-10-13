<?php

namespace App\Services\Ops;

use App\Exceptions\OpsStatusTokenException;

/**
 * The status token's optional IP allow-list (R193): exact addresses and CIDR ranges, typed one per
 * line or separated by commas or spaces. Matching happens in `OpsStatusToken::allowsIp()` against
 * `Request::ip()`. `TRUSTED_PROXIES` is empty by default (`config/trustedproxy.php`, `.env.example`), so
 * no `X-Forwarded-For` is believed and that address is the connecting socket's — behind a load balancer it
 * is the balancer's, not the caller's, until `TRUSTED_PROXIES` names the balancer. If the variable is
 * absent altogether, Laravel trusts every proxy on a `*.on-forge.com` or `*.on-vapor.com` host and under
 * `LARAVEL_CLOUD=1`, so keep it present (ADR-026).
 */
final class AllowedIps
{
    /** At most this many entries; a list longer than that is a mistake, not a policy. */
    public const MAX_ENTRIES = 20;

    /**
     * @return list<string>
     *
     * @throws OpsStatusTokenException naming the first entry that is neither an address nor a range
     */
    public static function parse(?string $text): array
    {
        $entries = array_values(array_unique(array_filter(
            preg_split('/[\s,;]+/', (string) $text) ?: [],
            fn (string $entry) => $entry !== '',
        )));

        if (count($entries) > self::MAX_ENTRIES) {
            throw new OpsStatusTokenException('At most '.self::MAX_ENTRIES.' addresses or ranges are allowed.');
        }

        foreach ($entries as $entry) {
            if (! self::isValidEntry($entry)) {
                throw new OpsStatusTokenException('"'.mb_substr($entry, 0, 60).'" is not an IP address or a CIDR range.');
            }
        }

        return $entries;
    }

    public static function isValidEntry(string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = explode('/', $entry, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false || ! ctype_digit($prefix)) {
            return false;
        }

        $max = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return (int) $prefix <= $max;
    }
}
