<?php

declare(strict_types=1);

namespace Sarcio\Shim\State;

use Sarcio\Shim\FileSwapState;

/**
 * File-swap state persisted as JSON, for appliers that run as a cron job rather
 * than a resident watcher (WordPress' WP-Cron, a plain FPM crontab). Every read
 * re-reads the file so concurrent runs see each other's work, and every write
 * takes an exclusive lock and replaces the file atomically.
 *
 * Fail-closed like the rest of the file-swap path: an unreadable or corrupt
 * state file reads as "nothing applied", which makes the next sync back up and
 * re-apply rather than restore a file it can no longer account for.
 */
final class JsonFileSwapState implements FileSwapState
{
    public function __construct(private readonly string $path)
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }

    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $entries = [];
        foreach ($decoded as $patchId => $entry) {
            if (is_string($patchId) && $this->isEntry($entry)) {
                $entries[$patchId] = [
                    'backup' => (string) $entry['backup'],
                    'existed' => (bool) $entry['existed'],
                    'hash' => (string) $entry['hash'],
                    'path' => (string) $entry['path'],
                ];
            }
        }
        return $entries;
    }

    public function forget(string $patchId): void
    {
        $entries = $this->all();
        unset($entries[$patchId]);
        $this->write($entries);
    }

    public function get(string $patchId): ?array
    {
        return $this->all()[$patchId] ?? null;
    }

    public function put(string $patchId, array $entry): void
    {
        $entries = $this->all();
        $entries[$patchId] = $entry;
        $this->write($entries);
    }

    private function isEntry(mixed $entry): bool
    {
        return is_array($entry)
            && is_string($entry['backup'] ?? null)
            && is_string($entry['hash'] ?? null)
            && is_string($entry['path'] ?? null)
            && isset($entry['existed']);
    }

    /** @param array<string,array{backup:string,existed:bool,hash:string,path:string}> $entries */
    private function write(array $entries): void
    {
        $encoded = json_encode($entries, JSON_PRETTY_PRINT);
        if ($encoded === false) {
            return;
        }
        // Write-then-rename so a reader never sees a half-written state file.
        $tmp = $this->path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
        }
    }
}
