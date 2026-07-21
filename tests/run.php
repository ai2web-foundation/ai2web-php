<?php

declare(strict_types=1);

require __DIR__ . '/../src/Manifest.php';
require __DIR__ . '/../src/Validator.php';
require __DIR__ . '/../src/Negotiator.php';
require __DIR__ . '/../src/Schema.php';
require __DIR__ . '/../src/Server.php';
require __DIR__ . '/../src/Export.php';
require __DIR__ . '/../src/Safety.php';
require __DIR__ . '/../src/Ap2.php';
require __DIR__ . '/../src/Nlweb.php';

use Ai2Web\Manifest;
use Ai2Web\Validator;
use Ai2Web\Negotiator;
use Ai2Web\Schema;
use Ai2Web\Server;
use Ai2Web\Export;
use Ai2Web\Safety;
use Ai2Web\Ap2;
use Ai2Web\Nlweb;

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

// SSRF guard (parity with the other SDKs)
$assert(Safety::isSafePublicUrl('https://store.example.com') === true, 'ssrf: allows public https');
$assert(Safety::isSafePublicUrl('http://169.254.169.254/latest') === false, 'ssrf: blocks cloud metadata ip');
$assert(Safety::isSafePublicUrl('http://localhost:8080') === false, 'ssrf: blocks localhost');
$assert(Safety::isSafePublicUrl('https://10.0.0.5/x') === false, 'ssrf: blocks 10.x');
$assert(Safety::isSafePublicUrl('https://192.168.1.1') === false, 'ssrf: blocks 192.168.x');
$assert(Safety::isSafePublicUrl('https://172.16.0.9/x') === false, 'ssrf: blocks 172.16-31.x');
$assert(Safety::isSafePublicUrl('http://[::1]/x') === false, 'ssrf: blocks ipv6 loopback');
$assert(Safety::isSafePublicUrl('https://fcbarcelona.com') === true, 'ssrf: fc-prefixed domain is not an ipv6 ULA');
$assert(Safety::isSafePublicUrl('file:///etc/passwd') === false, 'ssrf: blocks non-http scheme');
$assert(Safety::sameOrigin('https://a.example/x', 'https://a.example/y') === true, 'sameOrigin: same host');
$assert(Safety::sameOrigin('https://a.example', 'https://b.example') === false, 'sameOrigin: different host');

// AP2 (Agent Payments Protocol) merchant primitives
$ap2Key = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC7/yKHuyEHpcRo
Zahdi0IJeDyBoy7jV73flum/ysm3H3nK1lh7WHPNV1r27rOodKAIiJH/yVrKcAeR
qRyDgJ8ftAIla/qj9zDu3h5rR40wRDM60DhpkjMoHa2aQ3Lh93wH004k40HxvWOA
FORAZPrxo4JJTA7Qayak4VwWH2zepeSpmqO3kovZR4DDeDRJf/UnWC5fDAvQno+W
c2lVdbzeErLS1TvbmVDVfIwPkE008gZWEhQ/qK3RSoQEUxqeqaA8BM/WYdQr+PDv
EJgT0MfECcV+6ACMNHTCzspVRkE3pPcM2PVJekbGlirzxYMn2i0Hs0xgz1lwjEAb
/pIA3Vh1AgMBAAECggEAGRI5ZKiMCx0MSG/mODNuJx0l1JQSmLcG116k5bMBm65S
674SJsDxEJ1pwCytQPXssbak4dvUg9LU75QB/XeVwQCcmKkB0AQTPofYvq3YImu1
+U3zeADLWbo7gKsmEwSSQejoLvsvvDFpp5chqYTOApOvuF6wSxM/IBX91eVy+24h
sQgxxwmYtwaFqiW56oNcF+8OZVCenZF4NWGfJ6vDxyIgkfvlhPSzQl8BimzIB2j+
hs5S4TYY1fE7pcuI91zk2dGpK9E1nxl3e57gZJ19w+YrhOXvatOSX++QeBrv2Vik
kU1SbJq5K3fcGvjkEYXRqth0loTbZl3HxOgef4QksQKBgQDlxWxaQFrsa5pFP1a2
iklsuIbKr/0DgHuENzlZtrUPzbzCYBQT28ADa+3HZIvXvNo4bUbHayrrwQh6nFWl
n0JUVl3JzUcGJO6nJH/4uLI/G4NkMz/BW5G1fMnfpEBc2LAWbGYE0tgFxL/uvTeL
o5zTI3ElZX5FsMb/KAoU5J8TYwKBgQDRdPA5ydXMoooQQ3mYc/UUdnVZPtiN0G1j
+v/QyH5+0SEbj5AUaIbuTblNANRZsiz0OjJ4i5ZrXLRXOwYL0WvcC2we1KnRaomv
dNmdQwu31YRnxEq97/3dSBJC7K0VkiRjrLIZD/dDDUnjFjBD1fa51AcedXmPJNjf
3RyTYcKoRwKBgQDh8x2VNtnyyfHADQQ5p42C04cBxMSbb/qGz0OffHNbIidwQckc
qimNc9I1FSQLuBQkDxneOv3PLlknMZtrrkws4W2DaFFismjZhqQts3rdYjH4FAmr
HGASR6/BNCVy6EdpFZnRPoHeUlen7vyzXeZ3HtBCRSdCYw+dlQMs/pGMHwKBgQCG
igaEGBEskHr+V1kTg+g4bJ6T5LpU3TxmrCMFiMM30jzh5yU09q81AtezjoTX2Irn
lTo2E/NaowFzxoXrsWkGvo+EfjVWPoiSGwxs51PvkUarIHqh5jW6nUCdnEjRQj39
iEAduROqDi8XnnkCGb2RP5ATEII0YAauROjGAlV2oQKBgD4yneSwi1i8gfd4fEUS
tuRB4AkX6EHw6E9Zjj/gwttVt1vYM8dbam5aZPlP602yRRUrt0T101zE+s0SBQZh
9IUctJHxGO/5cufDZvovw2pXKlZkcpDxwPoKiUQZxiPBXf8YfKHUXz0gSc6QHAzu
XinNZUVoxqiVkt4smBecyfGS
-----END PRIVATE KEY-----
PEM;

$ap2Transport = Ap2::transport();
$assert(($ap2Transport['enabled'] ?? false) === true && ($ap2Transport['version'] ?? '') === '0.2.0', 'ap2: transport advertises version');
$assert(str_contains($ap2Transport['extension'] ?? '', 'ap2'), 'ap2: transport carries the extension uri');

$ap2Golden = ['z' => 'a/b', 'currency' => 'GBP', 'n' => 10.0, 'items' => [['value' => 9.99, 'label' => 'Mug']], 'ok' => true];
$assert(Ap2::canonical($ap2Golden) === '{"currency":"GBP","items":[{"label":"Mug","value":9.99}],"n":10,"ok":true,"z":"a/b"}', 'ap2: JCS canonical is cross-SDK stable', Ap2::canonical($ap2Golden));

$ap2Intent = Ap2::intentMandate('a red basketball shoe', ['skus' => ['SHOE-1'], 'now' => 1000, 'expires_in' => 900]);
$assert(($ap2Intent['natural_language_description'] ?? '') === 'a red basketball shoe' && !empty($ap2Intent['intent_expiry']) && $ap2Intent['skus'] === ['SHOE-1'], 'ap2: intent mandate built');

$ap2Contents = Ap2::cartContents(
    [['label' => 'Mug', 'unit_amount' => 9.99, 'quantity' => 3]],
    'GBP',
    'Test Store',
    ['now' => 1000]
);
$assert(($ap2Contents['payment_request']['details']['total']['amount']['value'] ?? 0) === 29.97, 'ap2: cart total = 3 x 9.99', $ap2Contents['payment_request']['details']['total'] ?? null);
$assert(($ap2Contents['payment_request']['details']['total']['amount']['currency'] ?? '') === 'GBP', 'ap2: cart currency major units');

$ap2Mandate = Ap2::cartMandate($ap2Contents, $ap2Key);
$assert(substr_count($ap2Mandate['merchant_authorization'] ?? '', '.') === 2, 'ap2: cart mandate is a JWT');
$ap2Pub = Ap2::publicKey($ap2Key);
$assert(Ap2::verifyCartMandate($ap2Mandate, $ap2Pub) === true, 'ap2: valid cart mandate verifies against the public key');

// Tamper: change the total, signature/hash must no longer verify.
$ap2Tampered = $ap2Mandate;
$ap2Tampered['contents']['payment_request']['details']['total']['amount']['value'] = 0.01;
$assert(Ap2::verifyCartMandate($ap2Tampered, $ap2Pub) === false, 'ap2: tampered cart mandate fails verification');

$ap2Jwks = Ap2::jwks($ap2Key);
$assert(($ap2Jwks['keys'][0]['kty'] ?? '') === 'RSA' && !empty($ap2Jwks['keys'][0]['n']) && ($ap2Jwks['keys'][0]['alg'] ?? '') === 'RS256', 'ap2: jwks publishes the RSA signing key');

$ap2Pd = Ap2::paymentDetails(['payment_mandate_contents' => [
    'payment_mandate_id' => 'pm_1',
    'payment_details_id' => 'pr_x',
    'payment_details_total' => ['label' => 'Total', 'amount' => Ap2::amount(29.97, 'GBP')],
    'payment_response' => ['method_name' => 'card', 'payer_email' => 'a@b.com'],
]]);
$assert($ap2Pd['payment_details_id'] === 'pr_x' && $ap2Pd['method'] === 'card' && $ap2Pd['payer_email'] === 'a@b.com', 'ap2: payment mandate parsed');

// NLWeb (nlweb.ai) interop
$nlTransport = Nlweb::transport();
$assert(($nlTransport['enabled'] ?? false) === true && ($nlTransport['version'] ?? '') === '0.55' && !empty($nlTransport['ask']), 'nlweb: transport advertises ask endpoint');

$nlResp = Nlweb::askResponse('red shoes', [
    ['url' => 'https://s.example/p/1', 'name' => 'Red Shoe', 'description' => 'A red running shoe', 'score' => 90],
    ['url' => 'https://s.example/p/2', 'title' => 'Crimson Sneaker'],
], ['site' => 'store']);
$assert(($nlResp['results'][0]['@type'] ?? '') === 'Item', 'nlweb: results are schema.org Items', $nlResp['results'][0] ?? null);
$assert(($nlResp['results'][0]['name'] ?? '') === 'Red Shoe' && ($nlResp['results'][0]['score'] ?? 0) === 90 && ($nlResp['results'][0]['site'] ?? '') === 'store', 'nlweb: item fields mapped');
$assert(($nlResp['results'][1]['name'] ?? '') === 'Crimson Sneaker' && ($nlResp['results'][1]['schema_object']['@type'] ?? '') === 'Thing', 'nlweb: title falls back to name + schema_object built');
$assert(count($nlResp['results']) === 2 && ($nlResp['query'] ?? '') === 'red shoes', 'nlweb: ask response envelope');

echo "\n" . ($failures === 0 ? 'ALL PASS' : "$failures FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
