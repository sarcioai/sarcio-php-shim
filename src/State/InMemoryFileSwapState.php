<?php

declare(strict_types=1);

namespace Sarcio\Shim\State;

use Sarcio\Shim\FileSwapState;

/**
 * Process-local file-swap state — the default, and the right store for the
 * long-running `sarcio-apply-files` watcher, which restores everything it
 * applied on shutdown anyway.
 */
final class InMemoryFileSwapState implements FileSwapState
{
    /** @var array<string,array{backup:string,existed:bool,hash:string,path:string}> */
    private array $entries = [];

    public function all(): array
    {
        return $this->entries;
    }

    public function forget(string $patchId): void
    {
        unset($this->entries[$patchId]);
    }

    public function get(string $patchId): ?array
    {
        return $this->entries[$patchId] ?? null;
    }

    public function put(string $patchId, array $entry): void
    {
        $this->entries[$patchId] = $entry;
    }
}
