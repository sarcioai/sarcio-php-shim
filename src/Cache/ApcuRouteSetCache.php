<?php

declare(strict_types=1);

namespace Sarcio\Shim\Cache;

use Sarcio\Shim\RouteSetCache;

/**
 * APCu-backed route-set cache: the recommended store under PHP-FPM. Shared
 * across the pool's worker processes on one host, with a short TTL so a retired
 * patch's route drops out quickly. Falls back to a cold cache (null) wherever
 * APCu is unavailable, so the shim still works — it just re-fetches the route
 * set via `hello` more often.
 */
final class ApcuRouteSetCache implements RouteSetCache
{
    public function __construct(private readonly string $key = 'sarcio.routeset')
    {
    }

    public static function isAvailable(): bool
    {
        return function_exists('apcu_fetch') && function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }
        $ok = false;
        $value = apcu_fetch($this->key, $ok);
        return ($ok && is_array($value)) ? $value : null;
    }

    public function set(array $routes, int $ttlSeconds): void
    {
        if (!self::isAvailable()) {
            return;
        }
        apcu_store($this->key, $routes, max(1, $ttlSeconds));
    }

    public function forget(): void
    {
        if (self::isAvailable()) {
            apcu_delete($this->key);
        }
    }
}
