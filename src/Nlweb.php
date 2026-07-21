<?php

declare(strict_types=1);

namespace Ai2Web;

/**
 * NLWeb (nlweb.ai) interop primitives.
 *
 * NLWeb turns a site's content into a natural-language, schema.org-flavoured query endpoint (its
 * `ask` API). This class lets an AI2Web site advertise an NLWeb surface in its manifest and serve
 * a minimal, NLWeb-compatible `ask` response over its own content, so agents that speak NLWeb can
 * query the site without it deploying the full NLWeb stack.
 *
 * The search itself is application-specific (this is a pure toolkit): the app finds the matching
 * content items and passes them in; `askResponse()` shapes them into NLWeb's result envelope.
 * Responses cover NLWeb's `list` mode (schema.org `Item` results); a caller may also pass a
 * generated `answer` for `generate` mode. NLWeb defines no discovery file, so `transport()` is an
 * AI2Web convention pointing at the site's `/ask` (and `/mcp`) URLs.
 */
final class Nlweb
{
    /** NLWeb response protocol version this projection targets. */
    public const VERSION = '0.55';
    private const DEFAULT_ASK = '/ai2w/nlweb/ask';
    private const DEFAULT_MCP = '/ai2w/nlweb/mcp';

    /**
     * The `transports.nlweb` advertisement to merge into a manifest.
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public static function transport(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'version' => self::VERSION,
            'ask' => self::DEFAULT_ASK,
            'mcp' => self::DEFAULT_MCP,
            'modes' => ['list'],
        ], $overrides);
    }

    /**
     * Wrap one content item into an NLWeb result `Item`.
     * @param array<string,mixed> $content url, name/title, description, site, siteUrl, score, type, schema_object
     * @param array<string,mixed> $opts site, site_url
     * @return array<string,mixed>
     */
    public static function item(array $content, array $opts = []): array
    {
        return [
            '@type' => 'Item',
            'url' => (string) ($content['url'] ?? ''),
            'name' => (string) ($content['name'] ?? $content['title'] ?? ''),
            'site' => (string) ($content['site'] ?? $opts['site'] ?? ''),
            'siteUrl' => (string) ($content['siteUrl'] ?? $opts['site_url'] ?? ''),
            'score' => isset($content['score']) ? (int) $content['score'] : 100,
            'description' => (string) ($content['description'] ?? ''),
            'schema_object' => is_array($content['schema_object'] ?? null) ? $content['schema_object'] : self::schemaObject($content),
        ];
    }

    /**
     * Build a minimal buffered NLWeb `ask` response (list mode) from matched content items.
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $opts site, site_url, query_id, answer
     * @return array<string,mixed>
     */
    public static function askResponse(string $query, array $items, array $opts = []): array
    {
        $results = [];
        foreach ($items as $it) {
            $results[] = self::item(is_array($it) ? $it : [], $opts);
        }
        $resp = [
            'query' => $query,
            'query_id' => (string) ($opts['query_id'] ?? ('q_' . bin2hex(random_bytes(8)))),
            'message_type' => 'result',
            'results' => $results,
        ];
        if (!empty($opts['answer'])) {
            $resp['answer'] = ['@type' => 'GeneratedAnswer', 'answer' => (string) $opts['answer'], 'items' => $results];
        }
        return $resp;
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private static function schemaObject(array $c): array
    {
        return array_filter([
            '@type' => (string) ($c['type'] ?? 'Thing'),
            'name' => $c['name'] ?? $c['title'] ?? null,
            'url' => $c['url'] ?? null,
            'description' => $c['description'] ?? null,
        ], static fn($v) => $v !== null && $v !== '');
    }
}
