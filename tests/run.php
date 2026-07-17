<?php

declare(strict_types=1);

require __DIR__ . '/../src/Manifest.php';
require __DIR__ . '/../src/Validator.php';
require __DIR__ . '/../src/Negotiator.php';
require __DIR__ . '/../src/Schema.php';
require __DIR__ . '/../src/Server.php';
require __DIR__ . '/../src/Export.php';

use Ai2Web\Manifest;
use Ai2Web\Validator;
use Ai2Web\Negotiator;
use Ai2Web\Schema;
use Ai2Web\Server;
use Ai2Web\Export;

$failures = 0;
$assert = function (bool $cond, string $label, mixed $detail = null) use (&$failures): void {
    echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
    if (!$cond) {
        $failures++;
        if ($detail !== null) {
            echo '      got: ' . json_encode($detail) . "\n";
        }
    }
};

// Build a manifest with the fluent builder
$m = Manifest::forSite('Example Store', 'https://store.example.com', 'ecommerce')
    ->capability('content')
    ->capability('commerce', ['endpoint' => '/ai2w/products', 'checkout' => true])
    ->capability('search', ['endpoint' => '/ai2w/search'])
    ->transports(['mcp' => ['enabled' => true, 'endpoint' => '/ai2w/mcp'], 'rest' => ['enabled' => true, 'base' => '/ai2w']])
    ->auth(['methods' => ['none', 'oauth2'], 'oauth2' => ['pkce' => true, 'scopes' => ['checkout']]])
    ->consent(['requires_user_approval_for' => ['purchase']])
    ->events(['endpoint' => '/ai2w/events', 'types' => ['order.shipped', 'price.drop']])
    ->action(['name' => 'track_order', 'description' => 'Track an order', 'method' => 'POST', 'endpoint' => '/ai2w/actions/track-order', 'requires_auth' => true, 'requires_user_approval' => false, 'risk' => 'medium', 'input_schema' => ['type' => 'object']])
    ->identity(['legal_name' => 'Example Store Ltd'])
    ->contact(['support' => 'help@store.example.com'])
    ->build();

$assert(($m['protocol'] ?? null) === 'ai2w', 'builder sets protocol ai2w');

// Validate + score
$v = Validator::validate($m);
$assert($v['valid'] === true, 'manifest is valid', $v['errors']);
$assert($v['score'] >= 90, 'AI Readiness score >= 90', $v['score']);
$assert(in_array($v['tier'], ['Standard', 'Enterprise'], true), 'tier Standard/Enterprise', $v['tier']);

// Negotiate
$neg = Negotiator::negotiate($m, ['transports' => ['mcp', 'rest'], 'capabilities' => ['content', 'commerce', 'flying'], 'auth' => ['oauth2']]);
$assert($neg['negotiated']['transport'] === 'mcp', 'negotiate picks mcp', $neg['negotiated']['transport']);
$assert($neg['negotiated']['capabilities'] === ['content', 'commerce'], 'negotiate intersects caps', $neg['negotiated']['capabilities']);
$assert($neg['unsupported'] === ['flying'], 'negotiate reports unsupported', $neg['unsupported']);
$assert($neg['negotiated']['auth'] === 'oauth2', 'negotiate selects oauth2', $neg['negotiated']['auth']);

// Server routing
$home = Server::handle(['manifest' => $m], 'GET', '/ai2w');
$assert($home['status'] === 200 && ($home['body']['protocol'] ?? null) === 'ai2w', 'server serves manifest at /ai2w');
$wk = Server::handle(['manifest' => $m], 'GET', '/.well-known/ai2w', null, 'https://store.example.com');
$assert(($wk['body']['ai2w'] ?? null) === 'https://store.example.com/ai2w', 'well-known returns pointer', $wk['body']);
$negRoute = Server::handle(['manifest' => $m], 'POST', '/ai2w/negotiate', ['supports' => ['capabilities' => ['commerce']]]);
$assert(($negRoute['body']['negotiated']['capabilities'] ?? null) === ['commerce'], 'server negotiate route works', $negRoute['body']);
$act = Server::handle(['manifest' => $m, 'actions' => ['track_order' => fn($b) => ['ok' => true, 'echo' => $b]]], 'POST', '/ai2w/actions/track-order', ['order_id' => 'A1']);
$assert(($act['body']['ok'] ?? null) === true, 'server dispatches action handler', $act['body']);
$miss = Server::handle(['manifest' => $m], 'GET', '/ai2w/nope');
$assert($miss['status'] === 404, 'server 404s unknown module');

// Request validation (Schema + server)
$schema = ['type' => 'object', 'properties' => ['order_id' => ['type' => 'string'], 'qty' => ['type' => 'integer']], 'required' => ['order_id']];
$assert(Schema::validate(['order_id' => 'A1', 'qty' => 2], $schema)['valid'] === true, 'schema: valid input passes');
$assert(Schema::validate(['qty' => 2], $schema)['valid'] === false, 'schema: missing required fails');
$assert(Schema::validate(['order_id' => 5], $schema)['valid'] === false, 'schema: wrong type fails');
$assert(Schema::validate(['order_id' => 'A1', 'qty' => 1.5], $schema)['valid'] === false, 'schema: non-integer fails');
$assert(Schema::validate(['anything' => 1], [])['valid'] === true, 'schema: empty schema accepts anything');

$actMan = ['protocol' => 'ai2w', 'actions' => [['name' => 'track_order', 'endpoint' => '/ai2w/actions/track-order', 'input_schema' => $schema]]];
$acts = ['track_order' => fn($b) => ['ok' => true]];
$okRes = Server::handle(['manifest' => $actMan, 'actions' => $acts], 'POST', '/ai2w/actions/track-order', ['order_id' => 'A1']);
$assert($okRes['status'] === 200, 'server: valid body -> 200', $okRes);
$badRes = Server::handle(['manifest' => $actMan, 'actions' => $acts], 'POST', '/ai2w/actions/track-order', []);
$assert($badRes['status'] === 400 && ($badRes['body']['error']['code'] ?? null) === 'invalid_request', 'server: missing required -> 400 invalid_request', $badRes['body']);
$offRes = Server::handle(['manifest' => $actMan, 'actions' => $acts, 'validateInput' => false], 'POST', '/ai2w/actions/track-order', []);
$assert($offRes['status'] === 200, 'server: validateInput=false opt-out passes through', $offRes['status']);

// v0.2 modules + export adapters (parity with @ai2web/core)
$m2 = Manifest::forSite('Example Bistro', 'https://bistro.example', 'restaurant', ['description' => 'Italian, terrace dining.'])
    ->capability('content')
    ->capability('commerce', ['endpoint' => '/ai2w/products'])
    ->capability('search', ['endpoint' => '/ai2w/search'])
    ->action([
        'name' => 'book_table', 'description' => 'Reserve a table.', 'method' => 'POST',
        'endpoint' => '/ai2w/actions/book-table', 'requires_auth' => false, 'requires_user_approval' => true,
        'risk' => 'medium', 'intent' => 'reserve_table',
        'input_schema' => ['type' => 'object', 'properties' => ['date' => ['type' => 'string'], 'party' => ['type' => 'integer']], 'required' => ['date', 'party']],
        'bindings' => [
            ['kind' => 'mcp', 'ref' => 'book_table', 'priority' => 1],
            ['kind' => 'redirect', 'ref' => '/reserve', 'priority' => 9, 'fallback_only' => true],
        ],
    ])
    ->knowledge([['id' => 'menu', 'name' => 'Menu', 'kind' => 'catalog', 'ref' => '/ai2w/products', 'format' => 'json']])
    ->governance(['rate_limits' => ['requests' => 60, 'window_seconds' => 60], 'consent_mode' => ['book_table' => 'explicit']])
    ->usagePolicy(['bulk_extraction' => false, 'model_training' => false])
    ->legal(['jurisdiction' => 'EU', 'ai_transparency' => true, 'ai_risk_classification' => 'limited'])
    ->agentIdentity(['required' => false, 'allow_anonymous' => true, 'methods' => ['http_message_signatures']])
    ->contact(['support' => 'hi@bistro.example'])
    ->build();

$assert(($m2['version'] ?? null) === '0.2', 'builder defaults to version 0.2', $m2['version'] ?? null);
$assert($m2['governance']['rate_limits']['requests'] === 60, 'builder: governance');
$assert($m2['usage_policy']['model_training'] === false, 'builder: usage_policy');
$assert($m2['legal']['ai_risk_classification'] === 'limited', 'builder: legal');
$assert($m2['identity']['agent']['methods'][0] === 'http_message_signatures', 'builder: agent identity');
$assert($m2['knowledge'][0]['id'] === 'menu', 'builder: knowledge');
$assert($m2['actions'][0]['intent'] === 'reserve_table', 'action: intent');
$assert(count($m2['actions'][0]['bindings']) === 2, 'action: bindings');
$assert($m2['actions'][0]['bindings'][1]['fallback_only'] === true, 'action: fallback_only binding');

$txt = Export::toLlmsTxt($m2);
$assert(str_starts_with($txt, '# Example Bistro'), 'llms.txt: title');
$assert(str_contains($txt, '## Capabilities') && str_contains($txt, '- commerce'), 'llms.txt: capabilities');
$assert(str_contains($txt, '## Knowledge') && str_contains($txt, 'Menu'), 'llms.txt: knowledge');
$assert(str_contains($txt, 'book_table: Reserve a table.'), 'llms.txt: action');
$assert(str_contains($txt, 'https://bistro.example/ai2w'), 'llms.txt: discovery link');

$aj = Export::toAgentJson($m2);
$assert($aj['name'] === 'Example Bistro', 'agent.json: name');
$assert(in_array('commerce', $aj['capabilities'], true), 'agent.json: capabilities');
$assert($aj['actions'][0]['intent'] === 'reserve_table', 'agent.json: action intent');
$assert(count($aj['actions'][0]['bindings']) === 2, 'agent.json: bindings preserved');
$assert($aj['policies']['legal']['jurisdiction'] === 'EU', 'agent.json: legal in policies');
$assert($aj['policies']['governance']['consent_mode']['book_table'] === 'explicit', 'agent.json: governance carried');
$ajDefault = Export::toAgentJson(
    Manifest::forSite('X', 'https://x.example', 'site')
        ->action(['name' => 'a', 'description' => 'd', 'method' => 'POST', 'endpoint' => '/ai2w/actions/a', 'requires_auth' => false, 'requires_user_approval' => false, 'risk' => 'low'])
        ->build()
);
$assert($ajDefault['actions'][0]['bindings'][0]['kind'] === 'rest', 'agent.json: default rest binding');

// multi-surface serving (llms.txt + agent.json)
$srv = ['manifest' => $m2];
$llms = Server::handle($srv, 'GET', '/llms.txt');
$assert($llms['status'] === 200 && str_starts_with($llms['headers']['content-type'], 'text/plain'), 'server: /llms.txt text/plain');
$assert(is_string($llms['body']) && str_starts_with($llms['body'], '# Example Bistro'), 'server: /llms.txt body');
$ajr = Server::handle($srv, 'GET', '/.well-known/agent.json');
$assert($ajr['status'] === 200 && $ajr['body']['name'] === 'Example Bistro', 'server: /.well-known/agent.json');
$ajr2 = Server::handle($srv, 'GET', '/agent.json');
$assert($ajr2['status'] === 200 && $ajr2['body']['policies']['governance']['rate_limits']['requests'] === 60, 'server: /agent.json alias + governance');
$llpost = Server::handle($srv, 'POST', '/llms.txt');
$assert($llpost['status'] === 405, 'server: /llms.txt POST -> 405');

echo "\n" . ($failures === 0 ? 'ALL PASS' : "$failures FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
