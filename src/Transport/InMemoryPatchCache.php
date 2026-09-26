<?php

declare(strict_types=1);

namespace Sarcio\Shim\Transport;

/**
 * Per-process patch store: the default when nothing shared is available, and
 * what a long-running runtime (Swoole, RoadRunner, FrankenPHP) keeps for the
 * process's lifetime. Under classic FPM it lasts one request, so every
 * request would pull; prefer a shared store there.
 */
final class InMemoryPatchCache implements PatchCache
{
    /** @var array<string,mixed>|null */
    private ?array $state = null;

    public function get(): ?array
    {
        return $this->state;
    }

    public function set(array $state): void
    {
        $this->state = $state;
    }

    public function forget(): void
    {
        $this->state = null;
    }
}
