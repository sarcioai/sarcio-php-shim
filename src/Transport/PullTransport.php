<?php

declare(strict_types=1);

namespace Sarcio\Shim\Transport;

use Sarcio\Shim\Engine\VerdictEngine;
use Sarcio\Shim\Manifest\PatchSet;
use Sarcio\Shim\Manifest\Verifier;
use Sarcio\Shim\Verdict;

/**
 * Server patches without a sidecar. The shim fetches its site's signed
 * patches straight from the control plane with the site's sidecar key,
 * verifies each one against the workspace's published signing key, and
 * decides verdicts in process. Hosts that cannot run a service (shared and
 * managed hosting) get the server tier this way; the trade is that a new
 * approval takes effect within the refresh window rather than in seconds.
 *
 * Refresh: a pull runs when the store is empty or older than `refreshSec`.
 * A pull that fails for any reason keeps the previous verified set (still
 * TTL-checked on every read), so a slow or unreachable control plane never
 * makes a request wait past `pullTimeoutSec` and never drops good patches;
 * with nothing to fall back on, no route is active, which is fail closed.
 * The signing key is re-read on every pull, so a rotation is adopted at the
 * next refresh and a key the control plane cannot serve keeps the last one.
 */
final class PullTransport implements Transport
{
    public const DEFAULT_REFRESH_SEC = 60;

    private readonly Verifier $verifier;
    private ?PatchSet $loaded = null;
    private float $loadedAt = 0.0;

    /**
     * @param string $controlPlaneUrl e.g. "https://acme.sarcio.io"
     * @param string $siteKey the public site key (`pk_…`)
     * @param string $sidecarKey the site's secret sidecar key (`sk_…`)
     */
    public function __construct(
        private readonly string $controlPlaneUrl,
        private readonly string $siteKey,
        private readonly string $sidecarKey,
        private readonly PatchCache $cache,
        private readonly HttpClient $http = new StreamHttpClient(),
        private readonly int $refreshSec = self::DEFAULT_REFRESH_SEC,
        private readonly float $pullTimeoutSec = 5.0,
        private readonly string $shimVersion = '0.1.0',
    ) {
        $this->verifier = new Verifier();
    }

    public function routes(): array
    {
        return $this->patches()->routes();
    }

    public function evaluate(string $routeId, array $envelope, float $budgetSec): ?Verdict
    {
        $ops = $this->patches()->opsForRoute($routeId);
        if ($ops === []) {
            return new Verdict(Verdict::PROCEED);
        }
        return VerdictEngine::evaluate($ops, $envelope['body'] ?? null);
    }

    /** php-file patches are not pulled yet; the file-swap pass needs the sidecar. */
    public function files(float $budgetSec): ?array
    {
        return null;
    }

    public function fileFetch(string $patchId, float $budgetSec): ?array
    {
        return null;
    }

    /** Drop the store so the next read pulls (after a known change, or from a cron). */
    public function refresh(): void
    {
        $this->loaded = null;
        $this->loadedAt = 0.0;
        $this->cache->forget();
    }

    /** Pull now, regardless of age; true when the control plane answered. */
    public function pull(): bool
    {
        $now = microtime(true);
        $previous = $this->cache->get();
        $keyId = is_string($previous['keyId'] ?? null) ? $previous['keyId'] : '';
        if ($keyId !== '') {
            $this->verifier->setKey($keyId);
        }
        $this->refreshSigningKey();
        if (!$this->verifier->enabled()) {
            return false; // nothing can verify: keep whatever was verified before
        }
        $signed = $this->fetchPatches('server');
        if ($signed === null) {
            return false;
        }
        $set = PatchSet::accept($this->verifier, $signed);
        $this->cache->set([
            'fetchedAt' => $now,
            'keyId' => $this->verifier->keyId(),
            'patches' => $set->toArray(),
        ]);
        $this->loaded = $set;
        $this->loadedAt = $now;
        return true;
    }

    /**
     * The verified set: what this process loaded if it is still inside the
     * refresh window, else the shared store if that is, else a pull, else
     * whatever was kept.
     */
    private function patches(): PatchSet
    {
        $now = microtime(true);
        if ($this->loaded !== null && $now - $this->loadedAt < $this->refreshSec) {
            return $this->loaded;
        }
        $state = $this->cache->get();
        $fetchedAt = is_array($state) && is_numeric($state['fetchedAt'] ?? null) ? (float) $state['fetchedAt'] : 0.0;
        if ($now - $fetchedAt < $this->refreshSec || !$this->pull()) {
            $this->loaded = is_array($state) && is_array($state['patches'] ?? null)
                ? PatchSet::fromArray($state['patches'])
                : ($this->loaded ?? PatchSet::empty());
            $this->loadedAt = $fetchedAt;
        }
        return $this->loaded ?? PatchSet::empty();
    }

    private function refreshSigningKey(): void
    {
        $res = $this->http->get(
            $this->url('/api/sites/' . rawurlencode($this->siteKey) . '/signing-key'),
            $this->headers(false),
            $this->pullTimeoutSec,
        );
        if ($res === null || $res['status'] !== 200) {
            return;
        }
        $body = json_decode($res['body'], true);
        $key = is_array($body) && is_string($body['publicKey'] ?? null) ? $body['publicKey'] : '';
        if ($key !== '' && $key !== $this->verifier->keyId()) {
            $this->verifier->setKey($key);
        }
    }

    /** @return array<int,mixed>|null */
    private function fetchPatches(string $target): ?array
    {
        $res = $this->http->get(
            $this->url('/api/sites/' . rawurlencode($this->siteKey) . '/patches/active?target=' . $target),
            $this->headers(true),
            $this->pullTimeoutSec,
        );
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $body = json_decode($res['body'], true);
        return is_array($body) && array_is_list($body) ? $body : null;
    }

    /** @return array<string,string> */
    private function headers(bool $authenticated): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'sarcio-shim-php/' . $this->shimVersion . ' (pull)',
        ];
        if ($authenticated) {
            $headers['Authorization'] = 'Bearer ' . $this->sidecarKey;
        }
        return $headers;
    }

    private function url(string $path): string
    {
        return rtrim($this->controlPlaneUrl, '/') . $path;
    }
}
