<div align="center">
  <a href="https://ai2web.dev">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/ai2web-foundation/.github/main/profile/ai2web-logo-white.svg">
      <img alt="AI2Web" src="https://raw.githubusercontent.com/ai2web-foundation/.github/main/profile/ai2web-logo-black.svg" width="200">
    </picture>
  </a>
</div>

# AI2Web PHP SDK (`ai2web/ai2web`)

[![CI](https://github.com/ai2web-foundation/ai2web-php/actions/workflows/ci.yml/badge.svg)](https://github.com/ai2web-foundation/ai2web-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/ai2web/ai2web)](https://packagist.org/packages/ai2web/ai2web)
[![PHP Version](https://img.shields.io/packagist/php-v/ai2web/ai2web)](https://packagist.org/packages/ai2web/ai2web)

The PHP reference implementation of the [AI2Web protocol](https://github.com/ai2web-foundation/ai2web-spec) - for Laravel, Symfony, or plain PHP. Mirrors `@ai2web/core`.

```bash
composer require ai2web/ai2web
```

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
- `Ai2Web\Manifest` - fluent capability-model builder (with the v0.2 modules: governance, usage policy, legal, knowledge, agent identity).
- `Ai2Web\Validator` - validation + AI Readiness scoring (spec §9/§11).
- `Ai2Web\Negotiator` - capability negotiation (spec §5).
- `Ai2Web\Server` - framework-agnostic route handler (serves `/ai2w`, actions, and the `/llms.txt` + `/.well-known/agent.json` projections).
- `Ai2Web\Export` - `toLlmsTxt()` / `toAgentJson()` projections of the manifest (RFC-0015).
- `Ai2Web\Schema` - JSON Schema input validation.
- `Ai2Web\Safety` - `isSafePublicUrl()` / `assertSafePublicUrl()` / `sameOrigin()` SSRF guard.

## Test
```bash
php tests/run.php
```

## Licence
MIT.
