# AI2Web PHP SDK (`ai2web/ai2web`)

The PHP reference implementation of the [AI2Web protocol](https://github.com/ai2web-foundation/ai2web-spec) - for Laravel, Symfony, or plain PHP. Mirrors `@ai2web/core`.

```php
use Ai2Web\Manifest;
use Ai2Web\Validator;
use Ai2Web\Server;

$manifest = Manifest::forSite('Example Store', 'https://example.com', 'ecommerce')
    ->capability('content')
    ->capability('commerce', ['endpoint' => '/ai2w/products', 'checkout' => true])
    ->transports(['mcp' => ['enabled' => true, 'endpoint' => '/ai2w/mcp'], 'rest' => ['enabled' => true]])
    ->auth(['methods' => ['none', 'oauth2'], 'oauth2' => ['pkce' => true, 'scopes' => ['checkout']]])
    ->consent(['requires_user_approval_for' => ['purchase']])
    ->contact(['support' => 'help@example.com'])
    ->build();

$result = Validator::validate($manifest);   // ['score' => 90+, 'tier' => 'Standard', ...]

// Serve every AI2Web route from one call:
$res = Server::handle(['manifest' => $manifest], $_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
http_response_code($res['status']);
foreach ($res['headers'] as $k => $v) { header("$k: $v"); }
echo json_encode($res['body']);
```

## Classes
- `Ai2Web\Manifest` - fluent capability-model builder.
- `Ai2Web\Validator` - validation + AI Readiness scoring (spec §9/§11).
- `Ai2Web\Negotiator` - capability negotiation (spec §5).
- `Ai2Web\Server` - framework-agnostic route handler.

## Test
```bash
php tests/run.php
```

## Licence
MIT.
