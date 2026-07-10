<?php

declare(strict_types=1);

require __DIR__ . '/../src/Manifest.php';
require __DIR__ . '/../src/Validator.php';
require __DIR__ . '/../src/Negotiator.php';
require __DIR__ . '/../src/Server.php';

use Ai2Web\Manifest;
use Ai2Web\Validator;
use Ai2Web\Negotiator;
use Ai2Web\Server;

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

echo "\n" . ($failures === 0 ? 'ALL PASS' : "$failures FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
