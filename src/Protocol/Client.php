<?php

declare(strict_types=1);

namespace Sarcio\Shim\Protocol;

use Sarcio\Shim\Verdict;

/**
 * A single-connection protocol client. Every method fails closed: a connect
 * failure, write error, malformed reply, or read timeout yields null/false so
 * the caller runs the app's original behavior. The sidecar never receives
 * credentials — the header allowlist is applied by the caller before framing.
 */
final class Client
{
    private int $seq = 0;
    private int $version = 1;

    /** @param resource $stream */
    private function __construct(private $stream)
    {
    }

    /**
     * Open a connection. `$dsn` is a stream DSN: "unix:///run/sarcio/sarcio.sock" or
     * "tcp://127.0.0.1:7071". Returns null on failure (fail closed).
     */
    public static function connect(string $dsn, float $timeout = 1.0): ?self
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($dsn, $errno, $errstr, $timeout);
        if ($stream === false) {
            return null;
        }
        return new self($stream);
    }

    /**
     * Announce and fetch the active route set. Returns
     * ['version' => int, 'routes' => string[]] or null on failure.
     *
     * @return array{version:int,routes:array<int,string>}|null
     */
    public function hello(string $siteKey, string $lang = 'php', string $framework = '', string $shimVersion = '0.1.0', string $token = ''): ?array
    {
        $id = $this->nextId();
        $frame = [
            'v' => $this->version,
            't' => 'hello',
            'id' => $id,
            'siteKey' => $siteKey,
            'lang' => $lang,
            'shimVersion' => $shimVersion,
            'protocol' => ['min' => 1, 'max' => 1],
        ];
        if ($framework !== '') {
            $frame['framework'] = $framework;
        }
        if ($token !== '') {
            $frame['token'] = $token;
        }
        if (!$this->send($frame)) {
            return null;
        }
        $reply = $this->read(1.0);
        if ($reply === null || ($reply['t'] ?? '') !== 'hello_ok') {
            return null;
        }
        $this->version = (int) ($reply['chosen'] ?? 1);
        $routes = [];
        foreach (($reply['routes'] ?? []) as $r) {
            if (is_string($r)) {
                $routes[] = $r;
            }
        }
        return ['version' => (int) ($reply['routeSetVersion'] ?? 0), 'routes' => $routes];
    }

    /**
     * Ask for a verdict on a route, enforcing the hard budget. Returns the
     * Verdict, or null to fail closed.
     *
     * @param array<string,mixed> $context
     */
    public function evaluate(string $routeId, array $context, float $budgetSec = 0.025): ?Verdict
    {
        $frame = [
            'v' => $this->version,
            't' => 'evaluate',
            'id' => $this->nextId(),
            'routeId' => $routeId,
            'context' => $context,
        ];
        if (!$this->send($frame)) {
            return null;
        }
        $reply = $this->read($budgetSec);
        if ($reply === null || ($reply['t'] ?? '') !== 'verdict') {
            return null;
        }
        return Verdict::fromFrame($reply);
    }

    /**
     * List the active file-swap patches (metadata only). Returns
     * ['version' => int, 'files' => [['patchId'=>, 'path'=>, 'hash'=>, 'expiresAt'=>], ...]]
     * or null on failure (fail closed → apply nothing).
     *
     * @return array{version:int,files:array<int,array<string,string>>}|null
     */
    public function files(string $siteKey, float $budgetSec = 1.0): ?array
    {
        if (!$this->send(['v' => $this->version, 't' => 'files', 'id' => $this->nextId(), 'siteKey' => $siteKey])) {
            return null;
        }
        $reply = $this->read($budgetSec);
        if ($reply === null || ($reply['t'] ?? '') !== 'file_set') {
            return null;
        }
        $files = [];
        foreach (($reply['files'] ?? []) as $f) {
            if (is_array($f)) {
                $files[] = $f;
            }
        }
        return ['version' => (int) ($reply['version'] ?? 0), 'files' => $files];
    }

    /**
     * Fetch one file patch's replacement content by id. Returns
     * ['path'=>, 'content'=>, 'hash'=>] or null (error / unknown / timeout).
     *
     * @return array{path:string,content:string,hash:string}|null
     */
    public function fileFetch(string $patchId, float $budgetSec = 1.0): ?array
    {
        if (!$this->send(['v' => $this->version, 't' => 'file_fetch', 'id' => $this->nextId(), 'patchId' => $patchId])) {
            return null;
        }
        $reply = $this->read($budgetSec);
        if ($reply === null || ($reply['t'] ?? '') !== 'file_content') {
            return null;
        }
        return [
            'path' => (string) ($reply['path'] ?? ''),
            'content' => (string) ($reply['content'] ?? ''),
            'hash' => (string) ($reply['hash'] ?? ''),
        ];
    }

    public function health(float $budgetSec = 0.025): bool
    {
        if (!$this->send(['v' => $this->version, 't' => 'health', 'id' => $this->nextId()])) {
            return false;
        }
        $reply = $this->read($budgetSec);
        return $reply !== null && ($reply['t'] ?? '') === 'health_ok';
    }

    /** Send a raw line. */
    public function sendRaw(string $text): void
    {
        @fwrite($this->stream, $text);
    }

    /** Read one frame with the given budget. */
    public function readFrame(float $budgetSec = 1.0): ?array
    {
        return $this->read($budgetSec);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }

    private function nextId(): string
    {
        return 'p' . (++$this->seq);
    }

    private function send(array $frame): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }
        return @fwrite($this->stream, Codec::encode($frame)) !== false;
    }

    private function read(float $budgetSec): ?array
    {
        if (!is_resource($this->stream)) {
            return null;
        }
        $sec = (int) floor($budgetSec);
        $usec = (int) round(($budgetSec - $sec) * 1_000_000);
        stream_set_timeout($this->stream, $sec, $usec);
        return Codec::readFrame($this->stream);
    }
}
