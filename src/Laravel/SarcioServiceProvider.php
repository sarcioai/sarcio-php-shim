<?php

declare(strict_types=1);

namespace Sarcio\Shim\Laravel;

use Illuminate\Support\ServiceProvider;
use Sarcio\Shim\Cache\ApcuRouteSetCache;
use Sarcio\Shim\Cache\InMemoryRouteSetCache;
use Sarcio\Shim\RouteSetCache;
use Sarcio\Shim\Shim;

/**
 * Wires the shim into a Laravel app. Configure via config/sarcio.php or env:
 *
 *   SARCIO_SIDECAR_DSN=unix:///run/sarcio/sarcio.sock
 *   SARCIO_SITE_KEY=pk_...
 *   SARCIO_EVALUATE_TIMEOUT_MS=25
 *   SARCIO_ROUTESET_TTL_SEC=5
 *
 * Then add SarcioMiddleware to the global HTTP middleware stack. The route-set
 * cache defaults to APCu when available (recommended under FPM), otherwise a
 * per-request in-memory cache.
 */
final class SarcioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RouteSetCache::class, static function (): RouteSetCache {
            return ApcuRouteSetCache::isAvailable() ? new ApcuRouteSetCache() : new InMemoryRouteSetCache();
        });

        $this->app->singleton(Shim::class, function (): Shim {
            return new Shim(
                dsn: (string) env('SARCIO_SIDECAR_DSN', Shim::DEFAULT_DSN),
                siteKey: (string) env('SARCIO_SITE_KEY', ''),
                cache: $this->app->make(RouteSetCache::class),
                evaluateTimeoutSec: ((float) env('SARCIO_EVALUATE_TIMEOUT_MS', 25)) / 1000.0,
                routeSetTtlSec: (int) env('SARCIO_ROUTESET_TTL_SEC', 5),
                framework: 'laravel',
                shimToken: (string) env('SARCIO_SHIM_TOKEN', ''),
            );
        });
    }
}
