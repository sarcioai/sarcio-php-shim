<?php

declare(strict_types=1);

namespace Sarcio\Shim\Protocol;

/**
 * NDJSON framing for the v1 shim<->sidecar protocol: one UTF-8 JSON object per
 * line, terminated by a single "\n".
 */
final class Codec
{
    /** Hard cap on a single frame, matching the other implementations. */
    public const MAX_FRAME_BYTES = 256 * 1024;

    /** Encode a frame to a wire line (with trailing newline). */
    public static function encode(array $frame): string
    {
        // Unescaped slashes/unicode keep frames compact and human-readable; the
        // receiver parses JSON either way.
        return json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * Read one frame from a stream, honouring a read timeout. Returns the decoded
     * associative array, or null on timeout / EOF / malformed line (the caller
     * then fails closed).
     */
    public static function readFrame($stream): ?array
    {
        $line = stream_get_line($stream, self::MAX_FRAME_BYTES + 1, "\n");
        if ($line === false || $line === '') {
            return null;
        }
        $meta = stream_get_meta_data($stream);
        if (!empty($meta['timed_out'])) {
            return null;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }
}
