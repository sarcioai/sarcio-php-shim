<?php

declare(strict_types=1);

namespace Sarcio\Shim;

/**
 * Where the file-swap applier remembers what it has applied. A long-running
 * watcher keeps this in memory; a cron-driven applier (WordPress, plain FPM
 * cron) is a fresh process every run, so it needs a store that outlives the
 * process — otherwise the second run backs up an ALREADY-PATCHED file as the
 * "original" and a retire can never restore.
 *
 * An entry is ['path' => absolute, 'backup' => absolute, 'existed' => bool,
 * 'hash' => sha256 of the applied content].
 */
interface FileSwapState
{
    /** @return array<string,array{backup:string,existed:bool,hash:string,path:string}> */
    public function all(): array;

    public function forget(string $patchId): void;

    /** @return array{backup:string,existed:bool,hash:string,path:string}|null */
    public function get(string $patchId): ?array;

    /** @param array{backup:string,existed:bool,hash:string,path:string} $entry */
    public function put(string $patchId, array $entry): void;
}
