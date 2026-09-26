<?php

declare(strict_types=1);

namespace Sarcio\Shim\Transport;

/**
 * Where the pull transport keeps its verified patches between requests, so a
 * pull happens once per refresh window rather than once per request. Under
 * PHP-FPM that wants a store shared across workers (APCu, or the host's own:
 * WordPress uses an option); {@see InMemoryPatchCache} lives for one process.
 * The state is opaque to the store: {@see PullTransport} owns its shape.
 */
interface PatchCache
{
    /** @return array<string,mixed>|null */
    public function get(): ?array;

    /** @param array<string,mixed> $state */
    public function set(array $state): void;

    public function forget(): void;
}
