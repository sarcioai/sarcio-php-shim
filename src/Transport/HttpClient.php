<?php

declare(strict_types=1);

namespace Sarcio\Shim\Transport;

/**
 * The one HTTP call the pull transport makes: a GET with headers and a time
 * budget. Hosts provide their own (WordPress wraps `wp_remote_get`, a PSR-18
 * client fits behind this in a few lines); {@see StreamHttpClient} is the
 * dependency-free default.
 */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}|null null on a transport failure (no response at all)
     */
    public function get(string $url, array $headers, float $timeoutSec): ?array;
}
