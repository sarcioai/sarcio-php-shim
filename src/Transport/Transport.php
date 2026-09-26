<?php

declare(strict_types=1);

namespace Sarcio\Shim\Transport;

use Sarcio\Shim\Verdict;

/**
 * Where the shim's route set and verdicts come from. Two implementations: the
 * sidecar over its local socket, and a direct pull from the control plane
 * that verifies and evaluates in process. Either way a failure is a safe
 * answer (no routes, a null verdict), never an exception on the request path.
 */
interface Transport
{
    /** The active route keys ("METHOD /path"); `[]` when unavailable. */
    public function routes(): array;

    /**
     * The verdict for one patched route, or null to run the original code.
     *
     * @param array{method:string,path:string,headers?:array<string,string>,body?:array<string,mixed>,preview?:string} $envelope
     */
    public function evaluate(string $routeId, array $envelope, float $budgetSec): ?Verdict;

    /** The signed php-file patches for the file-swap pass; null when unavailable. */
    public function files(float $budgetSec): ?array;

    /** One php-file patch's replacement content; null when unavailable. */
    public function fileFetch(string $patchId, float $budgetSec): ?array;
}
