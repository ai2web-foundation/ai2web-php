<?php

declare(strict_types=1);

namespace Ai2Web;

/**
 * Validation + AI Readiness scoring. Port of @ai2web/core validateManifest (spec §9/§11).
 */
final class Validator
{
    /**
     * @param array<string,mixed> $m
     * @return array{valid:bool,errors:string[],checks:array<int,array{ok:bool,points:int,label:string,hint:?string}>,score:int,tier:string}
     */
    public static function validate(array $m): array
    {
        $errors = [];
        $checks = [];
        $caps = $m['capabilities'] ?? [];
        $has = static function (mixed $v): bool {
            return $v === true || (is_array($v) && ($v['enabled'] ?? false) === true);
        };
        $cap = static fn(string $n): mixed => $caps[$n] ?? null;

        if (($m['protocol'] ?? null) !== 'ai2w') {
            $errors[] = "protocol must be 'ai2w'";
        }
        if (!preg_match('/^\d+\.\d+(\.\d+)?$/', (string) ($m['version'] ?? ''))) {
            $errors[] = 'version missing/invalid';
        }
        foreach (['name', 'url', 'type'] as $k) {
            if (empty($m['site'][$k] ?? null)) {
                $errors[] = "site.$k missing";
            }
        }
        if (empty($caps) || !is_array($caps)) {
            $errors[] = 'capabilities empty';
        }

        $actionsExist = $has($cap('actions'))
            || (!empty($m['actions']) && is_array($m['actions']))
            || $has($cap('commerce')) || $has($cap('booking'));

        $score = 0;
        $add = static function (bool $ok, int $points, string $label, string $hint) use (&$score, &$checks): void {
            $checks[] = ['ok' => $ok, 'points' => $points, 'label' => $label, 'hint' => $ok ? null : $hint];
            if ($ok) {
                $score += $points;
            }
        };

        $add(count($errors) === 0, 30, 'Valid discovery manifest', 'fix errors');
        $add($has($cap('content')), 6, 'Content', 'expose content module');
        $add($has($cap('commerce')) || $has($cap('booking')) || $has($cap('services')), 6, 'Products / services / booking', 'expose a commerce/services/booking module');
        $add($has($cap('search')), 4, 'Search', 'add a search capability');
        $add($actionsExist, 5, 'Actions', 'declare actions');
        $add($has($cap('events')), 6, 'Events / subscriptions', 'publish subscribable events');
        $add(($m['agent_service']['enabled'] ?? false) === true, 4, 'Agent service (A2A)', 'expose /ai2w/agent');

        $commerce = $cap('commerce');
        $add(!$has($commerce) || (is_array($commerce) && ($commerce['checkout'] ?? false) === true), 4, 'Checkout', 'commerce present but checkout missing');

        $add(($m['transports']['mcp']['enabled'] ?? false) === true, 8, 'MCP transport', 'expose an MCP endpoint');
        $add(($m['transports']['rest']['enabled'] ?? false) === true || !empty($m['transports']['feeds'] ?? null), 4, 'REST / feeds', 'expose REST or feeds');

        $oauthOk = in_array('oauth2', $m['auth']['methods'] ?? [], true) && ($m['auth']['oauth2']['pkce'] ?? false) === true;
        $consentDeclared = !empty($m['consent']['requires_user_approval_for'] ?? null);
        $add(!$actionsExist || $oauthOk, 8, 'OAuth2 + PKCE', 'protected actions need oauth2+pkce');
        $add(!$actionsExist || $consentDeclared, 7, 'Consent declared', 'declare consent for sensitive actions');

        $add(!empty($m['identity'] ?? null), 4, 'Identity', 'add identity (legal_name, policies)');
        $add(!empty($m['contact'] ?? null), 4, 'Contact', 'add support/security contact');

        $score = min(100, $score);

        $basic = count($errors) === 0;
        $standard = $basic && !empty($m['transports'] ?? null) && (!$actionsExist || $consentDeclared) && !empty($m['contact'] ?? null);
        $enterprise = $standard && !empty($m['identity'] ?? null) && !empty($m['auth'] ?? null) && !empty($m['rate_limits'] ?? null);
        $tier = $enterprise ? 'Enterprise' : ($standard ? 'Standard' : ($basic ? 'Basic' : 'Invalid'));

        return ['valid' => count($errors) === 0, 'errors' => $errors, 'checks' => $checks, 'score' => $score, 'tier' => $tier];
    }
}
