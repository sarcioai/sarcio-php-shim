<?php

declare(strict_types=1);

namespace Sarcio\Shim\Transport;

use Sarcio\Shim\Protocol\Client;
use Sarcio\Shim\Verdict;

/**
 * The sidecar over its local socket: one short connection per call, an
 * authenticated hello first when a shim token is configured. This is the
 * fast, isolated transport: the sidecar holds the verified patches and
 * decides, and the request only pays a local round trip.
 */
final class SidecarTransport implements Transport
{
    public function __construct(
        private readonly string $dsn,
        private readonly string $siteKey,
        private readonly float $connectTimeoutSec = 1.0,
        private readonly string $framework = '',
        private readonly string $shimToken = '',
    ) {
    }

    public function dsn(): string
    {
        return $this->dsn;
    }

    public function routes(): array
    {
        $client = Client::connect($this->dsn, $this->connectTimeoutSec);
        if ($client === null) {
            return [];
        }
        $res = $client->hello($this->siteKey, 'php', $this->framework, '0.1.0', $this->shimToken);
        $client->close();
        return is_array($res) && is_array($res['routes'] ?? null) ? $res['routes'] : [];
    }

    public function evaluate(string $routeId, array $envelope, float $budgetSec): ?Verdict
    {
        $client = $this->open();
        if ($client === null) {
            return null;
        }
        $verdict = $client->evaluate($routeId, $envelope, $budgetSec);
        $client->close();
        return $verdict;
    }

    public function files(float $budgetSec): ?array
    {
        $client = $this->open();
        if ($client === null) {
            return null;
        }
        $files = $client->files($this->siteKey, $budgetSec);
        $client->close();
        return $files;
    }

    public function fileFetch(string $patchId, float $budgetSec): ?array
    {
        $client = $this->open();
        if ($client === null) {
            return null;
        }
        $file = $client->fileFetch($patchId, $budgetSec);
        $client->close();
        return $file;
    }

    /**
     * Connect and, when a shim token is configured, authenticate. Without a
     * token the hello is skipped to save the round trip on the hot path.
     */
    private function open(): ?Client
    {
        $client = Client::connect($this->dsn, $this->connectTimeoutSec);
        if ($client === null) {
            return null;
        }
        if ($this->shimToken !== '' && $client->hello($this->siteKey, 'php', $this->framework, '0.1.0', $this->shimToken) === null) {
            $client->close();
            return null;
        }
        return $client;
    }
}
