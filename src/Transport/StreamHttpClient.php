<?php

declare(strict_types=1);

namespace Sarcio\Shim\Transport;

/**
 * HTTP over PHP's stream wrapper: no extension beyond what PHP ships with.
 * Needs `allow_url_fopen`; hosts that disable it supply their own
 * {@see HttpClient}. TLS is verified with the system trust store.
 */
final class StreamHttpClient implements HttpClient
{
    public function get(string $url, array $headers, float $timeoutSec): ?array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $lines),
                'timeout' => max(0.1, $timeoutSec),
                'ignore_errors' => true, // a 4xx/5xx is a response, not a failure
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $status, 'body' => $body];
    }
}
