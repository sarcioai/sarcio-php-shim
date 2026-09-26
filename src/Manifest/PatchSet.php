<?php

declare(strict_types=1);

namespace Sarcio\Shim\Manifest;

/**
 * The verified server patches, reduced to what evaluation needs: each patch's
 * ops and its hard expiry. Expiry is re-checked on every read, so a patch
 * stops applying the moment its TTL passes even if the control plane cannot
 * be reached. Only patches whose signature verifies get in; an unparseable
 * expiry is dropped rather than trusted.
 */
final class PatchSet
{
    /** @var array<int,array{ops:array<int,array<string,mixed>>,expires:float}> */
    private array $entries;

    /** @param array<int,array{ops:array<int,array<string,mixed>>,expires:float}> $entries */
    private function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Keep the patches that verify against the key and carry a usable expiry.
     *
     * @param array<int,mixed> $signed the wire shape: `[{manifest, signature}, ...]`
     */
    public static function accept(Verifier $verifier, array $signed): self
    {
        $entries = [];
        foreach ($signed as $patch) {
            if (!is_array($patch) || !$verifier->verify($patch)) {
                continue;
            }
            $manifest = $patch['manifest'];
            $expires = self::epoch($manifest['expiresAt'] ?? null);
            if ($expires === null) {
                continue;
            }
            $ops = is_array($manifest['ops'] ?? null) ? array_values(array_filter($manifest['ops'], 'is_array')) : [];
            $entries[] = ['ops' => $ops, 'expires' => $expires];
        }
        return new self($entries);
    }

    /** Rehydrate from {@see toArray()} (a cache hit); already verified when stored. */
    public static function fromArray(array $entries): self
    {
        $clean = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_array($entry['ops'] ?? null) && is_numeric($entry['expires'] ?? null)) {
                $clean[] = ['ops' => $entry['ops'], 'expires' => (float) $entry['expires']];
            }
        }
        return new self($clean);
    }

    /** @return array<int,array{ops:array<int,array<string,mixed>>,expires:float}> */
    public function toArray(): array
    {
        return $this->entries;
    }

    /**
     * The active route keys ("METHOD /path"), sorted, TTL-filtered.
     *
     * @return array<int,string>
     */
    public function routes(?float $now = null): array
    {
        $now ??= microtime(true);
        $set = [];
        foreach ($this->entries as $entry) {
            if ($now >= $entry['expires']) {
                continue;
            }
            foreach ($entry['ops'] as $op) {
                if (is_string($op['route'] ?? null)) {
                    $set[$op['route']] = true;
                }
            }
        }
        $routes = array_keys($set);
        sort($routes, SORT_STRING);
        return $routes;
    }

    /**
     * The unexpired ops scoped to one route, in patch then op order.
     *
     * @return array<int,array<string,mixed>>
     */
    public function opsForRoute(string $routeId, ?float $now = null): array
    {
        $now ??= microtime(true);
        $ops = [];
        foreach ($this->entries as $entry) {
            if ($now >= $entry['expires']) {
                continue;
            }
            foreach ($entry['ops'] as $op) {
                if (($op['route'] ?? null) === $routeId) {
                    $ops[] = $op;
                }
            }
        }
        return $ops;
    }

    /** RFC 3339 to epoch seconds; null when it does not parse (which fails closed). */
    private static function epoch(mixed $iso): ?float
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        try {
            $t = new \DateTimeImmutable($iso);
        } catch (\Throwable) {
            return null;
        }
        return (float) $t->format('U.u');
    }
}
