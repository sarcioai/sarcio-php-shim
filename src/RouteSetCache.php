<?php

declare(strict_types=1);

namespace Sarcio\Shim;

/**
 * The active route set is cached between requests because PHP-FPM is
 * shared-nothing — there is no resident process for the sidecar to push to. A
 * short TTL bounds staleness; the sidecar's own "retired routes evaluate to
 * proceed" behavior is the backstop that keeps a stale entry safe.
 */
interface RouteSetCache
{
    /** @return array<int,string>|null the cached route set, or null if cold. */
    public function get(): ?array;

    /** @param array<int,string> $routes */
    public function set(array $routes, int $ttlSeconds): void;

    public function forget(): void;
}
