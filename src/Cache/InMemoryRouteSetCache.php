<?php

declare(strict_types=1);

namespace Sarcio\Shim\Cache;

use Sarcio\Shim\RouteSetCache;

/**
 * Per-process route-set cache. The default when APCu is absent, and what
 * long-running runtimes (Swoole, RoadRunner, FrankenPHP) use — there a single
 * resident process holds it for the connection's lifetime. Under classic FPM
 * this lives only for one request, so the shim re-fetches the route set each
 * request; prefer ApcuRouteSetCache there.
 */
final class InMemoryRouteSetCache implements RouteSetCache
{
    /** @var array<int,string>|null */
    private ?array $routes = null;
    private float $expiresAt = 0.0;

    public function get(): ?array
    {
        if ($this->routes === null || microtime(true) >= $this->expiresAt) {
            return null;
        }
        return $this->routes;
    }

    public function set(array $routes, int $ttlSeconds): void
    {
        $this->routes = $routes;
        $this->expiresAt = microtime(true) + max(1, $ttlSeconds);
    }

    public function forget(): void
    {
        $this->routes = null;
        $this->expiresAt = 0.0;
    }
}
