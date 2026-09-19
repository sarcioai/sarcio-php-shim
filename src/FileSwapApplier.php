<?php

declare(strict_types=1);

namespace Sarcio\Shim;

use Sarcio\Shim\Protocol\Client;
use Sarcio\Shim\State\InMemoryFileSwapState;

/**
 * The PHP opcache fast-path applier. Unlike the request-path
 * shim, this is an out-of-band process (a watcher or cron) that materializes
 * file-swap patches on disk: for each active php-file patch it backs up the
 * original, writes the signed replacement content, and invalidates opcache; on
 * retire or TTL expiry it restores the original. The sidecar has already
 * verified the signature and enforced the TTL — the applier additionally checks
 * the sha256 before writing and refuses to escape the app root.
 *
 * Fail-closed: an unreachable sidecar or a fetch failure means "change nothing",
 * so a broken sidecar never corrupts the app's files.
 *
 * What it has applied lives in a `FileSwapState`. The default is process-local,
 * which is what the resident watcher wants; a cron-driven applier (WordPress)
 * passes a `JsonFileSwapState` so a fresh process still knows which originals
 * it owes a restore.
 */
final class FileSwapApplier
{
    private readonly FileSwapState $state;

    public function __construct(
        private readonly string $dsn,
        private readonly string $siteKey,
        private readonly string $appRoot,
        private readonly string $backupDir,
        private readonly float $connectTimeoutSec = 1.0,
        private readonly string $shimToken = '',
        ?FileSwapState $state = null,
    ) {
        $this->state = $state ?? new InMemoryFileSwapState();
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0700, true);
        }
    }

    /**
     * One reconciliation pass: apply newly-active/changed file patches, restore
     * retired ones. Returns ['applied'=>string[], 'restored'=>string[]].
     *
     * @return array{applied:array<int,string>,restored:array<int,string>,error?:string}
     */
    public function sync(): array
    {
        $client = Client::connect($this->dsn, $this->connectTimeoutSec);
        if ($client === null) {
            return ['applied' => [], 'restored' => [], 'error' => 'sidecar unreachable'];
        }
        try {
            // Authenticate the connection when a shim token is configured; the
            // sidecar requires a valid hello before it serves files/file_fetch.
            if ($this->shimToken !== '' && $client->hello($this->siteKey, 'php', 'file-swap', '0.1.0', $this->shimToken) === null) {
                return ['applied' => [], 'restored' => [], 'error' => 'unauthorized'];
            }
            $set = $client->files($this->siteKey);
            if ($set === null) {
                return ['applied' => [], 'restored' => [], 'error' => 'no file_set'];
            }
            $active = [];
            foreach ($set['files'] as $meta) {
                if (isset($meta['patchId'])) {
                    $active[(string) $meta['patchId']] = $meta;
                }
            }

            $applied = [];
            foreach ($active as $patchId => $meta) {
                $known = $this->state->get($patchId);
                if ($known !== null && $known['hash'] === ($meta['hash'] ?? '')) {
                    continue; // already applied, unchanged
                }
                $content = $client->fileFetch($patchId);
                if ($content === null) {
                    continue; // fail closed: skip
                }
                if (hash('sha256', $content['content']) !== ($meta['hash'] ?? '')) {
                    continue; // integrity check failed: refuse to write
                }
                if ($this->applyFile($patchId, $content['path'], $content['content'], (string) $meta['hash'])) {
                    $applied[] = $patchId;
                }
            }

            $restored = [];
            foreach (array_keys($this->state->all()) as $patchId) {
                if (!isset($active[$patchId])) {
                    $this->restoreFile($patchId);
                    $restored[] = $patchId;
                }
            }

            return ['applied' => $applied, 'restored' => $restored];
        } finally {
            $client->close();
        }
    }

    /** Restore every applied file (for graceful shutdown). */
    public function restoreAll(): void
    {
        foreach (array_keys($this->state->all()) as $patchId) {
            $this->restoreFile($patchId);
        }
    }

    /** Poll on an interval until $shouldStop() returns true. */
    public function watch(int $intervalSeconds, callable $shouldStop): void
    {
        while (!$shouldStop()) {
            $this->sync();
            sleep(max(1, $intervalSeconds));
        }
    }

    private function applyFile(string $patchId, string $relPath, string $content, string $hash): bool
    {
        $abs = $this->resolve($relPath);
        if ($abs === null) {
            return false; // refused: outside the app root
        }
        // Back up the original ONCE per patch id (keep the true original across
        // content updates for the same patch).
        $entry = $this->state->get($patchId);
        if ($entry === null) {
            $backup = $this->backupDir . '/' . $patchId . '.bak';
            $existed = is_file($abs);
            if ($existed) {
                @copy($abs, $backup);
            } else {
                @file_put_contents($backup . '.absent', '1'); // marker: no original
            }
            $entry = ['backup' => $backup, 'existed' => $existed, 'hash' => $hash, 'path' => $abs];
        }
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (@file_put_contents($abs, $content) === false) {
            return false;
        }
        $entry['hash'] = $hash;
        $this->state->put($patchId, $entry);
        $this->invalidate($abs);
        return true;
    }

    private function restoreFile(string $patchId): void
    {
        $state = $this->state->get($patchId);
        if ($state === null) {
            return;
        }
        if ($state['existed']) {
            @copy($state['backup'], $state['path']);
            @unlink($state['backup']);
        } else {
            @unlink($state['path']);
            @unlink($state['backup'] . '.absent');
        }
        $this->invalidate($state['path']);
        $this->state->forget($patchId);
    }

    /** Resolve an app-relative path, refusing traversal outside the app root. */
    private function resolve(string $relPath): ?string
    {
        if ($relPath === '' || $relPath[0] === '/' || str_contains($relPath, '..')) {
            return null;
        }
        $base = rtrim($this->appRoot, '/');
        $abs = $base . '/' . $relPath;
        // The parent directory (which may need creating) must stay under the root.
        $realBase = realpath($base);
        if ($realBase === false) {
            return null;
        }
        $normalizedParent = $this->normalize(dirname($abs));
        if (!str_starts_with($normalizedParent, $realBase)) {
            return null;
        }
        return $abs;
    }

    /** Lexically normalize a path (no filesystem access needed). */
    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
            } else {
                $parts[] = $seg;
            }
        }
        return '/' . implode('/', $parts);
    }

    private function invalidate(string $abs): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($abs, true);
        }
    }
}
