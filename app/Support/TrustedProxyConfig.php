<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * GAP-10 (finding F-25) — trusted-proxy configuration for a real deployment.
 *
 * The application ships `config/trustedproxy.php` (`TRUSTED_PROXIES`,
 * `TRUSTED_PROXY_HEADERS`) but nothing consumed it, so behind the load
 * balancer that terminates TLS — the normal production shape — Laravel could
 * not see the real client IP or the real scheme. That silently breaks, at a
 * minimum:
 *
 *   * HSTS: `SecurityHeaders` only emits `Strict-Transport-Security` when
 *     `$request->isSecure()`, which is false behind a TLS-terminating proxy;
 *   * IP-keyed rate limits: every request looks like it comes from the proxy,
 *     so one bucket serves the whole internet (429 storms, and the limiters
 *     stop protecting anything);
 *   * absolute URLs and signed links: they are generated as `http://`.
 *
 * `bootstrap/app.php` therefore calls `trustProxies()` with the two values this
 * class derives. The default (unset) trusts nothing, which is the fail-closed
 * choice: an unconfigured deployment behaves exactly as before rather than
 * trusting whatever `X-Forwarded-*` a client sends.
 *
 * Trusting a proxy is a security decision, not a convenience: the client can
 * forge every `X-Forwarded-*` header, so they may only be believed for a peer
 * that is actually under your control. `TRUSTED_PROXIES` accepts a
 * comma-separated list of IPs/CIDRs (recommended), or `*` — which is only
 * acceptable when the application port is unreachable except through the proxy
 * (for example a container that only the ingress can address).
 */
class TrustedProxyConfig
{
    /**
     * The proxy addresses passed to `trustProxies(at:)`.
     *
     * @return string|array<int, string>|null null = trust nothing
     */
    public static function proxies(?string $spec = null): string|array|null
    {
        $spec ??= self::rawProxies();

        $spec = trim((string) $spec);

        if ($spec === '') {
            return null;
        }

        if ($spec === '*') {
            return '*';
        }

        $addresses = array_values(array_filter(
            array_map('trim', explode(',', $spec)),
            static fn (string $address): bool => $address !== '',
        ));

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! self::looksLikeAnAddressOrSubnet($address)) {
                // A typo in this value must not silently widen trust, and it
                // must not take the site down either: the invalid entry is
                // dropped and reported, and everything else still applies.
                Log::warning('Ignoring an invalid TRUSTED_PROXIES entry.', [
                    'entry' => $address,
                    'hint' => 'use an IP address or CIDR, comma-separated, or "*"',
                ]);
            }
        }

        $addresses = array_values(array_filter(
            $addresses,
            static fn (string $address): bool => self::looksLikeAnAddressOrSubnet($address),
        ));

        // Laravel normalises IPs itself; keep the list as an array unless a
        // single string was given (both are supported).
        return $addresses === [] ? null : ($addresses === [1] ? $addresses[0] : $addresses);
    }

    /**
     * The `X-Forwarded-*` headers that may be believed, as a bitmask for
     * `trustProxies(headers:)`.
     *
     * @throws InvalidArgumentException when a spec is supplied but nothing in
     *                                  it can be mapped — failing closed is
     *                                  better than booting with a mask of 0
     *                                  that looks configured.
     */
    public static function headerMask(?string $spec = null): int
    {
        $spec ??= self::rawHeaders();

        $spec = trim((string) $spec);

        if ($spec === '') {
            return self::defaultHeaderMask();
        }

        $map = [
            'X_FORWARDED_FOR' => Request::HEADER_X_FORWARDED_FOR,
            'X_FORWARDED_HOST' => Request::HEADER_X_FORWARDED_HOST,
            'X_FORWARDED_PROTO' => Request::HEADER_X_FORWARDED_PROTO,
            'X_FORWARDED_PORT' => Request::HEADER_X_FORWARDED_PORT,
            'X_FORWARDED_PREFIX' => Request::HEADER_X_FORWARDED_PREFIX,
            'X_FORWARDED_AWS_ELB' => Request::HEADER_X_FORWARDED_AWS_ELB,
        ];

        $mask = 0;
        $unknown = [];

        foreach (array_map('trim', explode('|', $spec)) as $token) {
            if ($token === '') {
                continue;
            }

            $key = strtoupper(str_replace('-', '_', $token));

            if (isset($map[$key])) {
                $mask |= $map[$key];

                continue;
            }

            $unknown[] = $token;
        }

        if ($unknown !== []) {
            Log::warning('Ignoring unknown TRUSTED_PROXY_HEADERS entries.', [
                'entries' => $unknown,
                'known' => array_keys($map),
            ]);
        }

        if ($mask === 0) {
            // No known header at all: trusting the proxies without trusting
            // any header is a no-op, so it cannot be what was intended.
            throw new InvalidArgumentException(
                'TRUSTED_PROXY_HEADERS has no recognised entry; set it to a pipe-separated '
                .'list of: '.implode('|', array_keys($map)).'.'
            );
        }

        return $mask;
    }

    /**
     * Whether a peer address is inside any of the configured trust ranges.
     * Used by the tests to state the guarantee in both directions, and by
     * operations to sanity-check a deployment's configuration.
     *
     * @param  string|array<int, string>|null  $proxies
     */
    public static function trusts(string|array|null $proxies, string $peer): bool
    {
        if ($proxies === '*') {
            return true;
        }

        if ($proxies === null) {
            return false;
        }

        foreach ((array) $proxies as $range) {
            if (self::addressInRange($peer, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The header names that are trusted, for diagnostics and for the runbook.
     *
     * @return array<int, string>
     */
    public static function trustedHeaderNames(int $mask): array
    {
        $names = [
            Request::HEADER_X_FORWARDED_FOR => 'X-Forwarded-For',
            Request::HEADER_X_FORWARDED_HOST => 'X-Forwarded-Host',
            Request::HEADER_X_FORWARDED_PROTO => 'X-Forwarded-Proto',
            Request::HEADER_X_FORWARDED_PORT => 'X-Forwarded-Port',
            Request::HEADER_X_FORWARDED_PREFIX => 'X-Forwarded-Prefix',
        ];

        $trusted = [];

        foreach ($names as $flag => $name) {
            if (($mask & $flag) === $flag) {
                $trusted[] = $name;
            }
        }

        // `HEADER_X_FORWARDED_AWS_ELB` is a PRESET (26 = X-Forwarded-For |
        // Proto | Port), i.e. a subset of the four individual flags — in an
        // AWS ELB deployment the load balancer does not send X-Forwarded-Host
        // or X-Forwarded-Prefix. It is therefore only named when it IS the
        // whole mask; a spec that names the individual headers (the shipped
        // default does both) resolves to the header names alone.
        if ($mask === Request::HEADER_X_FORWARDED_AWS_ELB) {
            $trusted[] = 'AWS ELB preset';
        }

        return $trusted;
    }

    /**
     * A plain-language summary for the health/support pages and the runbook.
     */
    public static function describe(): string
    {
        $proxies = self::proxies();

        if ($proxies === null) {
            return 'No trusted proxies: forwarded headers are ignored (correct when the '
                .'application is reached directly, or when the proxy does not terminate TLS).';
        }

        $list = $proxies === '*' ? 'every peer' : implode(', ', (array) $proxies);

        return 'Trusting forwarded headers from '.$list.' ('
            .implode(', ', self::trustedHeaderNames(self::headerMask())).').';
    }

    /**
     * The raw configuration values. Split out so the tests can exercise the
     * parsing without touching the environment.
     */
    protected static function rawProxies(): ?string
    {
        $value = config('trustedproxy.proxies');

        return $value === null ? null : (string) $value;
    }

    protected static function rawHeaders(): ?string
    {
        $value = config('trustedproxy.headers');

        return $value === null ? null : (string) $value;
    }

    /**
     * The mask used when `TRUSTED_PROXY_HEADERS` is not set.
     */
    protected static function defaultHeaderMask(): int
    {
        return Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO;
    }

    /**
     * Accepts an IPv4/IPv6 address or a CIDR subnet; rejects anything else so a
     * typo cannot be treated as a proxy.
     */
    protected static function looksLikeAnAddressOrSubnet(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        if (! ctype_digit($prefix)) {
            return false;
        }

        $bits = str_contains($address, ':') ? 128 : 32;
        $prefix = (int) $prefix;

        return $prefix >= 0 && $prefix <= $bits;
    }

    /**
     * True when `$peer` is inside a single address or CIDR range.
     */
    protected static function addressInRange(string $peer, string $range): bool
    {
        if (filter_var($peer, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (! str_contains($range, '/')) {
            return $peer === $range;
        }

        [$subnet, $prefix] = explode('/', $range, 2);

        if (filter_var($subnet, FILTER_VALIDATE_IP) === false || ! ctype_digit($prefix)) {
            return false;
        }

        $peerBinary = @inet_pton($peer);
        $subnetBinary = @inet_pton($subnet);

        if ($peerBinary === false || $subnetBinary === false) {
            return false;
        }

        if (strlen($peerBinary) !== strlen($subnetBinary)) {
            // Never compare a v4 address against a v6 range.
            return false;
        }

        return self::binaryPrefixEquals($peerBinary, $subnetBinary, (int) $prefix);
    }

    /**
     * Compares the first `$prefix` bits of two packed addresses.
     */
    protected static function binaryPrefixEquals(string $left, string $right, int $prefix): bool
    {
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        if ($bytes > 0 && substr($left, 0, $bytes) !== substr($right, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $bits) & 0xFF;

        return (ord($left[$bytes]) & $mask) === (ord($right[$bytes]) & $mask);
    }
}
