<?php

declare(strict_types=1);

namespace Sarcio\Shim\Manifest;

/**
 * The canonical byte string a patch signature covers: keys sorted recursively,
 * JavaScript JSON scalar formatting, no whitespace. Every Sarcio verifier
 * (browser, Node, Go, Java, this one) computes the same bytes for the same
 * manifest, which is what lets one signature verify everywhere. A single byte
 * of drift here would fail every signature, so this mirrors the reference
 * implementation exactly.
 */
final class Canonical
{
    /** @param array<string,mixed> $manifest */
    public static function stringify(array $manifest): string
    {
        return self::stable($manifest);
    }

    private static function stable(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return self::number($v);
        }
        if (is_string($v)) {
            return self::quote($v);
        }
        if (is_array($v)) {
            if ($v === [] || array_keys($v) === range(0, count($v) - 1)) {
                return '[' . implode(',', array_map([self::class, 'stable'], $v)) . ']';
            }
            $keys = array_keys($v);
            sort($keys, SORT_STRING);
            $parts = [];
            foreach ($keys as $k) {
                $parts[] = self::quote((string) $k) . ':' . self::stable($v[$k]);
            }
            return '{' . implode(',', $parts) . '}';
        }
        return 'null';
    }

    /** JavaScript's JSON.stringify string escaping, byte for byte. */
    private static function quote(string $s): string
    {
        $out = '"';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            switch ($c) {
                case '"':
                    $out .= '\\"';
                    break;
                case '\\':
                    $out .= '\\\\';
                    break;
                case "\x08":
                    $out .= '\\b';
                    break;
                case "\t":
                    $out .= '\\t';
                    break;
                case "\n":
                    $out .= '\\n';
                    break;
                case "\x0c":
                    $out .= '\\f';
                    break;
                case "\r":
                    $out .= '\\r';
                    break;
                default:
                    $o = ord($c);
                    $out .= $o < 0x20 ? sprintf('\\u%04x', $o) : $c;
            }
        }
        return $out . '"';
    }

    private static function number(float $v): string
    {
        if (is_finite($v) && floor($v) === $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(sprintf('%.17g', $v), '0'), '.');
    }
}
