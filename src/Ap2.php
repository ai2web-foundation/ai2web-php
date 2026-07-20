<?php

declare(strict_types=1);

namespace Ai2Web;

/**
 * AP2 (Agent Payments Protocol, Google - v0.2.0) merchant primitives.
 *
 * AP2 is mandate-based: the merchant prices a buyer agent's Intent Mandate as a CartContents
 * (a W3C PaymentRequest, amounts in decimal major units) and digitally SIGNS it into a
 * CartMandate - a short-lived guarantee of items and price - then settles a user-signed Payment
 * Mandate. This class provides the reusable, app-agnostic core: build an Intent Mandate, build a
 * CartContents from line items, sign it as an RS256 JWT (cart_hash over the canonical contents),
 * publish the public key as a JWKS, verify a Cart Mandate, and parse a Payment Mandate.
 *
 * Pricing a cart is application-specific, so this stays a pure toolkit - it does not fetch a
 * catalogue or serve routes. Signing uses PHP's OpenSSL (RSA / RS256).
 */
final class Ap2
{
    public const EXTENSION_URI = 'https://github.com/google-agentic-commerce/ap2/v1';
    public const VERSION = '0.2.0';

    private const DEFAULT_TTL = 900;

    /**
     * The `transports.ap2` advertisement to merge into a manifest.
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public static function transport(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'version' => self::VERSION,
            'extension' => self::EXTENSION_URI,
            'agent_card' => '/ai2w/ap2/agent-card',
            'cart' => '/ai2w/ap2/cart',
            'payment' => '/ai2w/ap2/payment',
            'jwks' => '/ai2w/ap2/jwks',
        ], $overrides);
    }

    /**
     * Build an AP2 IntentMandate (classic v0.2.0 shape).
     * @param array<string,mixed> $opts merchants, skus, items, requires_refundability, expires_in, now, user_cart_confirmation_required
     * @return array<string,mixed>
     */
    public static function intentMandate(string $description, array $opts = []): array
    {
        $now = (int) ($opts['now'] ?? time());
        $ttl = (int) ($opts['expires_in'] ?? self::DEFAULT_TTL);
        $intent = [
            'natural_language_description' => $description,
            'intent_expiry' => gmdate('c', $now + $ttl),
            'user_cart_confirmation_required' => (bool) ($opts['user_cart_confirmation_required'] ?? true),
        ];
        if (!empty($opts['merchants'])) {
            $intent['merchants'] = array_values($opts['merchants']);
        }
        if (!empty($opts['skus'])) {
            $intent['skus'] = array_values($opts['skus']);
        }
        if (!empty($opts['items'])) {
            $intent['items'] = array_values($opts['items']);
        }
        if (!empty($opts['requires_refundability'])) {
            $intent['requires_refundability'] = true;
        }
        return $intent;
    }

    /** AP2 PaymentCurrencyAmount: decimal major units, ISO 4217. @return array<string,mixed> */
    public static function amount(float $value, string $currency): array
    {
        return ['currency' => strtoupper($currency), 'value' => round($value, 2)];
    }

    /**
     * Build a CartContents (W3C PaymentRequest) from line items.
     * @param array<int,array<string,mixed>> $items each ['label'=>string, 'unit_amount'=>float, 'quantity'?=>int]
     * @param array<string,mixed> $opts id, payment_details_id, method_data, expires_in, now, user_cart_confirmation_required
     * @return array<string,mixed>
     */
    public static function cartContents(array $items, string $currency, string $merchantName, array $opts = []): array
    {
        $now = (int) ($opts['now'] ?? time());
        $ttl = (int) ($opts['expires_in'] ?? self::DEFAULT_TTL);
        $display = [];
        $total = 0.0;
        foreach ($items as $it) {
            $qty = max(1, (int) ($it['quantity'] ?? 1));
            $unit = (float) ($it['unit_amount'] ?? $it['amount'] ?? 0);
            $line = $unit * $qty;
            $label = (string) ($it['label'] ?? 'Item');
            if ($qty > 1) {
                $label = sprintf('%s x%d', $label, $qty);
            }
            $display[] = ['label' => $label, 'amount' => self::amount($line, $currency)];
            $total += $line;
        }
        return [
            'id' => (string) ($opts['id'] ?? ('cart_' . bin2hex(random_bytes(10)))),
            'user_cart_confirmation_required' => (bool) ($opts['user_cart_confirmation_required'] ?? true),
            'payment_request' => [
                'method_data' => $opts['method_data'] ?? [['supported_methods' => 'card', 'data' => (object) []]],
                'details' => [
                    'id' => (string) ($opts['payment_details_id'] ?? ('pr_' . bin2hex(random_bytes(10)))),
                    'display_items' => $display,
                    'total' => ['label' => 'Total', 'amount' => self::amount($total, $currency)],
                ],
                'options' => ['request_shipping' => true],
            ],
            'cart_expiry' => gmdate('c', $now + $ttl),
            'merchant_name' => $merchantName,
        ];
    }

    /**
     * Sign CartContents into a CartMandate (contents + merchant_authorization JWT).
     * @param array<string,mixed> $contents
     * @param array<string,mixed> $opts kid, iss, aud, expires_in, now
     * @return array<string,mixed>
     */
    public static function cartMandate(array $contents, string $privateKeyPem, array $opts = []): array
    {
        return [
            'contents' => $contents,
            'merchant_authorization' => self::signCart($contents, $privateKeyPem, $opts),
        ];
    }

    /**
     * The merchant_authorization JWT (RS256) over the canonical CartContents, with a cart_hash claim.
     * @param array<string,mixed> $contents
     * @param array<string,mixed> $opts
     */
    public static function signCart(array $contents, string $privateKeyPem, array $opts = []): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new \RuntimeException('AP2: invalid private key');
        }
        $details = openssl_pkey_get_details($key);
        $kid = (string) ($opts['kid'] ?? substr(hash('sha256', (string) ($details['key'] ?? '')), 0, 16));
        $now = (int) ($opts['now'] ?? time());
        $ttl = (int) ($opts['expires_in'] ?? self::DEFAULT_TTL);

        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid];
        $claims = [
            'iss' => (string) ($opts['iss'] ?? ($contents['merchant_name'] ?? '')),
            'sub' => (string) ($contents['id'] ?? ''),
            'aud' => (string) ($opts['aud'] ?? 'ap2-network'),
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(12)),
            'cart_hash' => self::b64url(hash('sha256', self::canonical($contents), true)),
        ];
        $signingInput = self::b64url(self::canonical($header)) . '.' . self::b64url(self::canonical($claims));
        $sig = '';
        if (!openssl_sign($signingInput, $sig, $privateKeyPem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('AP2: signing failed');
        }
        return $signingInput . '.' . self::b64url($sig);
    }

    /**
     * JWKS publishing the cart-signing public key, for verifiers.
     * @param array<string,mixed> $opts kid
     * @return array<string,mixed>
     */
    public static function jwks(string $privateKeyPem, array $opts = []): array
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            return ['keys' => []];
        }
        $d = openssl_pkey_get_details($key);
        if (!isset($d['rsa']['n'], $d['rsa']['e'])) {
            return ['keys' => []];
        }
        $kid = (string) ($opts['kid'] ?? substr(hash('sha256', (string) ($d['key'] ?? '')), 0, 16));
        return ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => self::b64url($d['rsa']['n']),
            'e' => self::b64url($d['rsa']['e']),
        ]]];
    }

    /**
     * Verify a CartMandate's signature (against a public or private PEM) and its cart_hash binding,
     * and that it has not expired.
     * @param array<string,mixed> $mandate
     */
    public static function verifyCartMandate(array $mandate, string $keyPem): bool
    {
        $jwt = (string) ($mandate['merchant_authorization'] ?? '');
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        [$h, $p, $s] = $parts;
        if (openssl_verify("$h.$p", self::b64urlDecode($s), $keyPem, OPENSSL_ALGO_SHA256) !== 1) {
            return false;
        }
        $claims = json_decode(self::b64urlDecode($p), true);
        if (!is_array($claims) || empty($claims['cart_hash'])) {
            return false;
        }
        if (isset($claims['exp']) && time() > (int) $claims['exp']) {
            return false;
        }
        $expected = self::b64url(hash('sha256', self::canonical($mandate['contents'] ?? []), true));
        return hash_equals((string) $claims['cart_hash'], $expected);
    }

    /**
     * Extract the salient fields of a PaymentMandate for settlement.
     * @param array<string,mixed> $paymentMandate
     * @return array<string,mixed>
     */
    public static function paymentDetails(array $paymentMandate): array
    {
        $c = isset($paymentMandate['payment_mandate_contents']) && is_array($paymentMandate['payment_mandate_contents'])
            ? $paymentMandate['payment_mandate_contents'] : [];
        $resp = isset($c['payment_response']) && is_array($c['payment_response']) ? $c['payment_response'] : [];
        return [
            'payment_mandate_id' => $c['payment_mandate_id'] ?? null,
            'payment_details_id' => $c['payment_details_id'] ?? null,
            'total' => $c['payment_details_total']['amount'] ?? null,
            'method' => $resp['method_name'] ?? null,
            'payer_email' => $resp['payer_email'] ?? null,
            'payer_name' => $resp['payer_name'] ?? null,
        ];
    }

    /** Extract the public-key PEM from a private-key PEM (for verifiers / JWKS builders). */
    public static function publicKey(string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new \RuntimeException('AP2: invalid private key');
        }
        return (string) openssl_pkey_get_details($key)['key'];
    }

    /** @param array<string,mixed> $data */
    private static function canonical(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }
}
