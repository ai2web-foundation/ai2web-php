<?php

declare(strict_types=1);

namespace Ai2Web;

/**
 * SSRF guard. Parity with @ai2web/core's safety.
 *
 * Blocks the obvious pivots (loopback, private ranges, cloud metadata, link-local, non-http
 * schemes) AND the alternative IP encodings HTTP clients resolve to those same addresses
 * (decimal / hex / octal / short-form IPv4, and IPv4-mapped IPv6). This is a literal host/IP
 * check and is not, by itself, DNS-rebind safe.
 */
final class Safety
{
    /** True if a standard dotted-quad is loopback/private/reserved (or has an invalid octet). */
    private static function ipv4Blocked(string $host): bool
    {
        if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $host, $m)) {
            return false;
        }
        $a = (int) $m[1];
        $b = (int) $m[2];
        if ($a > 255 || (int) $m[3] > 255 || (int) $m[4] > 255) {
            return true; // not a real address; refuse
        }
        if (in_array($a, [0, 10, 127], true)) {
            return true;
        }
        if ($a === 169 && $b === 254) { // link-local + cloud metadata (169.254.169.254)
            return true;
        }
        if ($a === 172 && $b >= 16 && $b <= 31) {
            return true;
        }
        if ($a === 192 && $b === 168) {
            return true;
        }
        if ($a === 100 && $b >= 64 && $b <= 127) { // CGNAT
            return true;
        }
        return false;
    }

    /**
     * Embedded IPv4 from an IPv4-mapped/compat IPv6 host, in either the dotted form
     * (::ffff:a.b.c.d) or the hex-compressed form a URL parser can produce (::ffff:7f00:1).
     */
    private static function mappedIpv4(string $host): ?string
    {
        if (preg_match('/^::(?:ffff:)?(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/', $host, $m)) {
            return $m[1];
        }
        if (preg_match('/^::(?:ffff:)?([0-9a-f]{1,4}):([0-9a-f]{1,4})$/', $host, $m)) {
            $hi = hexdec($m[1]);
            $lo = hexdec($m[2]);
            return sprintf('%d.%d.%d.%d', ($hi >> 8) & 255, $hi & 255, ($lo >> 8) & 255, $lo & 255);
        }
        return null;
    }

    /** Reports whether $raw is a safe public http(s) target. */
    public static function isSafePublicUrl(string $raw): bool
    {
        $p = parse_url($raw);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            return false;
        }
        $scheme = strtolower((string) $p['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return false;
        }
        // parse_url keeps IPv6 literals in brackets, e.g. [::1]; normalise them.
        $host = strtolower(trim((string) $p['host'], '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        // IPv6 literal: range-check any embedded IPv4 (mapped/compat), then block loopback and
        // ULA/link-local (fc/fd/fe80). Guard on ':' so a real domain such as "fcbarcelona.com"
        // is not mistaken for an fc00::/7 address.
        if (strpos($host, ':') !== false) {
            $mapped = self::mappedIpv4($host);
            if ($mapped !== null && self::ipv4Blocked($mapped)) {
                return false;
            }
            return !($host === '::1' || str_starts_with($host, 'fc') || str_starts_with($host, 'fd') || str_starts_with($host, 'fe80'));
        }

        // Hex-encoded IP (0x7f000001, or a dotted octet like 0x7f): a client resolves these to an IP.
        if (preg_match('/(^|\.)0x/', $host)) {
            return false;
        }

        // Standard dotted-quad IPv4.
        if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host)) {
            return !self::ipv4Blocked($host);
        }

        // Any remaining all-numeric host is an alternative IPv4 encoding (decimal integer, octal,
        // or short form like 127.1) that a client resolves to an IP. No real domain looks like this.
        if (!preg_match('/[a-z]/', $host)) {
            return false;
        }

        return true;
    }

    /** Returns $raw if safe, otherwise throws. */
    public static function assertSafePublicUrl(string $raw): string
    {
        if (!self::isSafePublicUrl($raw)) {
            throw new \InvalidArgumentException('ai2w: refusing to fetch non-public or unsafe URL: ' . $raw);
        }
        return $raw;
    }

    /** Reports whether $a and $b share scheme + host + port. */
    public static function sameOrigin(string $a, string $b): bool
    {
        $pa = parse_url($a);
        $pb = parse_url($b);
        if ($pa === false || $pb === false) {
            return false;
        }
        return [strtolower((string) ($pa['scheme'] ?? '')), strtolower((string) ($pa['host'] ?? '')), $pa['port'] ?? null]
            === [strtolower((string) ($pb['scheme'] ?? '')), strtolower((string) ($pb['host'] ?? '')), $pb['port'] ?? null];
    }
}
