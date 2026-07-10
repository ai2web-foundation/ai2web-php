<?php

declare(strict_types=1);

namespace Ai2Web;

/**
 * Capability negotiation (spec §5). Port of @ai2web/core negotiate().
 */
final class Negotiator
{
    /**
     * @param array<string,mixed> $m
     * @param array{transports?:string[],capabilities?:string[],auth?:string[]} $agent
     * @return array{negotiated:array{transport:?string,capabilities:string[],auth:?string,endpoints:array<string,string>},unsupported:string[]}
     */
    public static function negotiate(array $m, array $agent = []): array
    {
        $has = static fn(mixed $v): bool => $v === true || (is_array($v) && ($v['enabled'] ?? false) === true);
        $endpointOf = static fn(string $name, mixed $v): string => (is_array($v) && is_string($v['endpoint'] ?? null)) ? $v['endpoint'] : "/ai2w/$name";

        $siteCaps = [];
        foreach (($m['capabilities'] ?? []) as $k => $v) {
            if ($has($v)) {
                $siteCaps[] = $k;
            }
        }

        $wantCaps = $agent['capabilities'] ?? $siteCaps;
        $capabilities = array_values(array_intersect($siteCaps, $wantCaps));
        $unsupported = array_values(array_diff($wantCaps, $siteCaps));

        // Only transports explicitly enabled are negotiable (parity with negotiate.ts).
        $siteTransports = [];
        foreach (($m['transports'] ?? []) as $k => $v) {
            if (is_array($v) && ($v['enabled'] ?? false) === true) {
                $siteTransports[] = $k;
            }
        }
        $wantTransports = $agent['transports'] ?? $siteTransports;
        $transport = null;
        foreach ($wantTransports as $t) {
            if (in_array($t, $siteTransports, true)) {
                $transport = $t;
                break;
            }
        }

        $siteAuth = $m['auth']['methods'] ?? ['none'];
        $wantAuth = $agent['auth'] ?? $siteAuth;
        $auth = null;
        if (in_array('oauth2', $siteAuth, true) && in_array('oauth2', $wantAuth, true)) {
            $auth = 'oauth2';
        } else {
            foreach ($wantAuth as $a) {
                if (in_array($a, $siteAuth, true)) {
                    $auth = $a;
                    break;
                }
            }
            if ($auth === null && in_array('none', $siteAuth, true)) {
                $auth = 'none';
            }
        }

        $endpoints = [];
        foreach ($capabilities as $c) {
            $endpoints[$c] = $endpointOf($c, $m['capabilities'][$c]);
        }
        if ($transport !== null && is_array($m['transports'][$transport] ?? null) && isset($m['transports'][$transport]['endpoint'])) {
            $endpoints[$transport] = $m['transports'][$transport]['endpoint'];
        }

        return [
            'negotiated' => ['transport' => $transport, 'capabilities' => $capabilities, 'auth' => $auth, 'endpoints' => $endpoints],
            'unsupported' => $unsupported,
        ];
    }
}
