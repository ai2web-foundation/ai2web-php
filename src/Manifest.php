<?php

declare(strict_types=1);

namespace Ai2Web;

/**
 * Fluent AI2Web (ai2w) manifest builder - the "describe your website once" surface.
 * Mirrors @ai2web/core's builder.
 */
final class Manifest
{
    /** @var array<string,mixed> */
    private array $m;

    /** @param array<string,mixed> $site */
    public function __construct(array $site)
    {
        $this->m = [
            'protocol' => 'ai2w',
            'version' => '0.2',
            'site' => $site,
            'capabilities' => [],
        ];
    }

    /** @param array<string,mixed> $site */
    public static function forSite(string $name, string $url, string $type, array $extra = []): self
    {
        return new self(array_merge(['name' => $name, 'url' => $url, 'type' => $type], $extra));
    }

    /** @param bool|array<string,mixed> $value */
    public function capability(string $name, bool|array $value = true): self
    {
        if (is_array($value)) {
            $value = array_merge(['enabled' => true], $value);
        }
        $this->m['capabilities'][$name] = $value;
        return $this;
    }

    /** @param array<string,mixed> $t */
    public function transports(array $t): self
    {
        $this->m['transports'] = array_merge($this->m['transports'] ?? [], $t);
        return $this;
    }

    /** @param array<string,mixed> $a */
    public function auth(array $a): self
    {
        $this->m['auth'] = $a;
        return $this;
    }

    /** @param array<string,mixed> $c */
    public function consent(array $c): self
    {
        $this->m['consent'] = $c;
        return $this;
    }

    /** @param array<string,mixed> $a */
    public function action(array $a): self
    {
        $this->m['actions'][] = $a;
        $this->capability('actions', ['endpoint' => '/ai2w/actions']);
        return $this;
    }

    /** @param array<string,mixed> $e */
    public function events(array $e): self
    {
        $this->m['events'] = $e;
        $this->capability('events', ['endpoint' => $e['endpoint'] ?? '/ai2w/events']);
        return $this;
    }

    /** @param array<string,mixed> $s */
    public function agentService(array $s): self
    {
        $this->m['agent_service'] = $s;
        return $this;
    }

    /** @param array<string,mixed> $i */
    public function identity(array $i): self
    {
        $this->m['identity'] = $i;
        return $this;
    }

    /** @param array<string,mixed> $c */
    public function contact(array $c): self
    {
        $this->m['contact'] = $c;
        return $this;
    }

    // v0.2 optional modules (all additive; a minimal manifest stays valid without them).

    /** @param array<string,mixed> $g */
    public function governance(array $g): self
    {
        $this->m['governance'] = $g;
        return $this;
    }

    /** @param array<string,mixed> $u */
    public function usagePolicy(array $u): self
    {
        $this->m['usage_policy'] = $u;
        return $this;
    }

    /** @param array<string,mixed> $l */
    public function legal(array $l): self
    {
        $this->m['legal'] = $l;
        return $this;
    }

    /** @param array<string,mixed> $a */
    public function agentIdentity(array $a): self
    {
        $this->m['identity'] = array_merge($this->m['identity'] ?? [], ['agent' => $a]);
        return $this;
    }

    /** @param list<array<string,mixed>> $k */
    public function knowledge(array $k): self
    {
        $this->m['knowledge'] = $k;
        return $this;
    }

    public function extend(string $key, mixed $value): self
    {
        if (!str_starts_with($key, 'x-')) {
            $key = 'x-' . $key;
        }
        $this->m[$key] = $value;
        return $this;
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        return $this->m;
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->m, $flags | JSON_THROW_ON_ERROR);
    }
}
