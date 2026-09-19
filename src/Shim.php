<?php

declare(strict_types=1);

namespace Sarcio\Shim;

use Sarcio\Shim\Protocol\Client;

/**
 * The framework-neutral PHP shim core. Laravel middleware and the Symfony
 * kernel listener are thin adapters over this: it owns the route-set cache, the
 * synchronous route check, and the fail-closed `evaluate` round-trip to the
 * sidecar. It never blocks the request longer than the evaluate budget, and any
 * failure means original behavior runs.
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

    /**
     * @param string $dsn stream DSN, e.g. "unix:///run/sarcio/sarcio.sock" or "tcp://127.0.0.1:7071"
     */
    public function __construct(
        private readonly string $dsn,
        private readonly string $siteKey,
        private readonly RouteSetCache $cache,
        private readonly float $evaluateTimeoutSec = 0.025,
        private readonly int $routeSetTtlSec = 5,
        private readonly float $connectTimeoutSec = 1.0,
        private readonly string $framework = '',
        private readonly int $bodyCapBytes = 65536,
        private readonly string $shimToken = '',
    ) {
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
     * Ask the sidecar for a verdict. Returns null to fail closed (run original).
     * Callers gate this behind shouldEvaluate; a call for an unpatched route or
     * an unreachable sidecar is a safe null.
     *
     * @param array{headers?:array<string,mixed>,body?:mixed,preview?:string} $context
     */
    public function evaluate(string $method, string $path, array $context = []): ?Verdict
    {
        $routeId = self::routeKey($method, $path);
        if (!in_array($routeId, $this->routeSet(), true)) {
            return null;
        }
        $client = Client::connect($this->dsn, $this->connectTimeoutSec);
        if ($client === null) {
            return null; // fail closed
        }
        // When a shim token is configured the sidecar requires an authenticated
        // hello on the connection before it will evaluate. (Without a token the
        // hello is skipped to save the extra round-trip on the hot path.)
        if ($this->shimToken !== '' && $client->hello($this->siteKey, 'php', $this->framework, '0.1.0', $this->shimToken) === null) {
            $client->close();
            return null; // fail closed (e.g. unauthorized)
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
        $verdict = $client->evaluate($routeId, $envelope, $this->evaluateTimeoutSec);
        $client->close();
        return $verdict;
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
        $client = Client::connect($this->dsn, $this->connectTimeoutSec);
        if ($client === null) {
            return [];
        }
        $res = $client->hello($this->siteKey, 'php', $this->framework, '0.1.0', $this->shimToken);
        $client->close();
        return is_array($res) ? ($res['routes'] ?? []) : [];
    }
}
