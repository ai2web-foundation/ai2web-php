<?php

declare(strict_types=1);

namespace Ai2Web;

/**
 * Framework-agnostic AI2Web request handler. Port of @ai2web/server.
 * Returns ['status'=>int,'headers'=>array,'body'=>mixed]; adapt to your framework.
 */
final class Server
{
    private const CORS = [
        'access-control-allow-origin' => '*',
        'access-control-allow-methods' => 'GET, POST, OPTIONS',
        'access-control-allow-headers' => 'content-type, authorization',
    ];

    /**
     * @param array{manifest:array<string,mixed>,modules?:array<string,callable>,actions?:array<string,callable>} $opts
     * @return array{status:int,headers:array<string,string>,body:mixed}
     */
    public static function handle(array $opts, string $method, string $path, mixed $body = null, ?string $origin = null): array
    {
        $manifest = $opts['manifest'];
        $modules = $opts['modules'] ?? [];
        $actions = $opts['actions'] ?? [];
        $validateInput = $opts['validateInput'] ?? true;
        $declaredActions = [];
        foreach ($manifest['actions'] ?? [] as $a) {
            $declaredActions[$a['name'] ?? ''] = $a;
        }

        $path = rtrim($path, '/') ?: '/';
        $method = strtoupper($method);

        if ($method === 'OPTIONS') {
            return ['status' => 204, 'headers' => self::CORS, 'body' => null];
        }

        if ($path === '/.well-known/ai2w') {
            if ($origin) {
                return self::json(200, ['ai2w' => rtrim($origin, '/') . '/ai2w']);
            }
            return self::json(200, $manifest);
        }

        if (in_array($path, ['/ai2w', '/ai', '/.ai'], true)) {
            if ($method !== 'GET') {
                return self::error(405, 'invalid_request', 'Use GET for the manifest.');
            }
            return self::json(200, $manifest);
        }

        // Multi-surface projections (RFC-0015): the one canonical manifest, emitted in other
        // discovery formats so agents that speak llms.txt or agent.json need not parse ai2w first.
        if ($path === '/llms.txt') {
            if ($method !== 'GET') {
                return self::error(405, 'invalid_request', 'Use GET for llms.txt.');
            }
            return self::text(200, 'text/plain; charset=utf-8', Export::toLlmsTxt($manifest));
        }
        if (in_array($path, ['/.well-known/agent.json', '/agent.json'], true)) {
            if ($method !== 'GET') {
                return self::error(405, 'invalid_request', 'Use GET for agent.json.');
            }
            return self::json(200, Export::toAgentJson($manifest));
        }

        if ($path === '/ai2w/negotiate') {
            $b = is_array($body) ? $body : [];
            $supports = $b['agent']['supports'] ?? $b['supports'] ?? $b;
            return self::json(200, Negotiator::negotiate($manifest, is_array($supports) ? $supports : []));
        }

        if (preg_match('#^/ai2w/actions/([a-z0-9_-]+)$#i', $path, $mm)) {
            $name = str_replace('-', '_', $mm[1]);
            if (!isset($actions[$name])) {
                return self::error(404, 'unsupported_capability', "Unknown action '$name'.");
            }
            $declared = $declaredActions[$name] ?? null;
            if ($validateInput && $declared && !empty($declared['input_schema'])) {
                $r = Schema::validate($body ?? [], $declared['input_schema']);
                if (!$r['valid']) {
                    return self::error(400, 'invalid_request', 'Request does not match the declared input schema: ' . implode('; ', $r['errors']) . '.');
                }
            }
            return self::json(200, ($actions[$name])($body));
        }

        if (preg_match('#^/ai2w/([a-z0-9_-]+)$#i', $path, $mm)) {
            $name = $mm[1];
            if (!isset($modules[$name])) {
                return self::error(404, 'unsupported_capability', "Module '$name' not exposed.");
            }
            return self::json(200, ($modules[$name])($body));
        }

        return self::error(404, 'invalid_request', "No AI2Web route for $path.");
    }

    /** @return array{status:int,headers:array<string,string>,body:mixed} */
    private static function json(int $status, mixed $body): array
    {
        return ['status' => $status, 'headers' => array_merge(['content-type' => 'application/json; charset=utf-8'], self::CORS), 'body' => $body];
    }

    /** @return array{status:int,headers:array<string,string>,body:mixed} */
    private static function text(int $status, string $contentType, string $body): array
    {
        return ['status' => $status, 'headers' => array_merge(['content-type' => $contentType], self::CORS), 'body' => $body];
    }

    /** @return array{status:int,headers:array<string,string>,body:mixed} */
    private static function error(int $status, string $code, string $message, bool $retryable = false): array
    {
        return self::json($status, ['error' => ['code' => $code, 'message' => $message, 'retryable' => $retryable]]);
    }
}
