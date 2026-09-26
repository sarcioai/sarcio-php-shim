<?php

declare(strict_types=1);

namespace Sarcio\Shim;

use Sarcio\Shim\Transport\SidecarTransport;
use Sarcio\Shim\Transport\Transport;

/**
 * The framework-neutral PHP shim core. Laravel middleware and the Symfony
 * kernel listener are thin adapters over this: it owns the route-set cache, the
 * synchronous route check, and the fail-closed `evaluate` round-trip to its
 * transport: the sidecar over a local socket, or a direct pull from the
 * control plane verified and decided in process ({@see Transport}). It never
 * blocks the request longer than the evaluate budget, and any failure means
 * original behavior runs.
 */
final class Shim
{
    /** Headers never forwarded to the sidecar (auth/session critical). */
    public const FORBIDDEN_HEADERS = [
        'authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'www-authenticate',
    ];

    /** Default allowlist of request headers forwarded to the sidecar. */
    public const DEFAULT_HEADER_ALLOWLIST = [
        'content-type', 'accept', 'accept-language', 'user-agent', 'x-request-id', 'x-correlation-id',
    ];

    /** The sidecar's conventional socket (the packaged systemd unit's RuntimeDirectory), as a stream DSN. */
    public const DEFAULT_DSN = 'unix:///run/sarcio/sarcio.sock';

    private readonly Transport $transport;

    /**
     * @param string $dsn stream DSN, e.g. "unix:///run/sarcio/sarcio.sock" or "tcp://127.0.0.1:7071"
     *                    (ignored when a transport is given)
     * @param Transport|null $transport where routes and verdicts come from; the sidecar at `$dsn` by default
     */
    public function __construct(
        string $dsn,
        private readonly string $siteKey,
        private readonly RouteSetCache $cache,
        private readonly float $evaluateTimeoutSec = 0.025,
        private readonly int $routeSetTtlSec = 5,
        float $connectTimeoutSec = 1.0,
        string $framework = '',
        private readonly int $bodyCapBytes = 65536,
        string $shimToken = '',
        ?Transport $transport = null,
    ) {
        $this->transport = $transport ?? new SidecarTransport($dsn, $siteKey, $connectTimeoutSec, $framework, $shimToken);
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public static function routeKey(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $path;
    }

    /** @return array<int,string> */
    public function routeSet(): array
    {
        $cached = $this->cache->get();
        if ($cached !== null) {
            return $cached;
        }
        $routes = $this->fetchRouteSet();
        $this->cache->set($routes, $this->routeSetTtlSec);
        return $routes;
    }

    /** Force a route-set refresh on the next check (e.g. after a known change). */
    public function refreshRouteSet(): void
    {
        $this->cache->forget();
    }

    public function shouldEvaluate(string $method, string $path): bool
    {
        return in_array(self::routeKey($method, $path), $this->routeSet(), true);
    }

    /**
     * Ask the transport for a verdict. Returns null to fail closed (run original).
     * Callers gate this behind shouldEvaluate; a call for an unpatched route or
     * an unreachable transport is a safe null.
     *
     * @param array{headers?:array<string,mixed>,body?:mixed,preview?:string} $context
     */
    public function evaluate(string $method, string $path, array $context = []): ?Verdict
    {
        $routeId = self::routeKey($method, $path);
        if (!in_array($routeId, $this->routeSet(), true)) {
            return null;
        }
        $envelope = ['method' => strtoupper($method), 'path' => $path];
        if (isset($context['headers']) && is_array($context['headers'])) {
            $envelope['headers'] = $this->scrubHeaders($context['headers']);
        }
        $body = $this->capBody($context['body'] ?? null);
        if ($body !== null) {
            $envelope['body'] = $body;
        }
        if (!empty($context['preview']) && is_string($context['preview'])) {
            $envelope['preview'] = $context['preview'];
        }
        return $this->transport->evaluate($routeId, $envelope, $this->evaluateTimeoutSec);
    }

    /**
     * Drop forbidden headers and keep only the allowlist. Values are flattened
     * to strings.
     *
     * @param array<string,mixed> $headers
     * @return array<string,string>
     */
    public function scrubHeaders(array $headers, ?array $allowlist = null): array
    {
        $allow = array_flip($allowlist ?? self::DEFAULT_HEADER_ALLOWLIST);
        $forbidden = array_flip(self::FORBIDDEN_HEADERS);
        $out = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if (isset($forbidden[$lower]) || !isset($allow[$lower])) {
                continue;
            }
            $out[$lower] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }
        return $out;
    }

    /** Return the body if within the size cap, else null (omit → fail closed on body ops). */
    private function capBody(mixed $body): ?array
    {
        if (!is_array($body) || $body === []) {
            return is_array($body) ? $body : null;
        }
        $encoded = json_encode($body);
        if ($encoded === false || strlen($encoded) > $this->bodyCapBytes) {
            return null;
        }
        return $body;
    }

    private function fetchRouteSet(): array
    {
        return $this->transport->routes();
    }
}
