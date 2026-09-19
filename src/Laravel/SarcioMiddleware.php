<?php

declare(strict_types=1);

namespace Sarcio\Shim\Laravel;

use Closure;
use Illuminate\Http\Request;
use Sarcio\Shim\Shim;
use Sarcio\Shim\Verdict;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel middleware ported onto the v1 protocol. A no-op on unpatched routes
 * (a synchronous in-memory route-set check). For a patched route it asks the
 * sidecar for a verdict and applies it; if the sidecar is unreachable, slow,
 * or errors, it FAILS CLOSED and the request runs unchanged.
 *
 * Register app-wide, after Laravel's own input handling. Read the patch context
 * the shim attaches (skip-validation names, config overrides) from the request:
 *
 *   $ctx = $request->attributes->get('sarcio'); // ['skipValidations'=>[], 'config'=>[]]
 */
final class SarcioMiddleware
{
    public function __construct(private readonly Shim $shim)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $method = $request->getMethod();
        $path = $request->getPathInfo();

        if (!$this->shim->shouldEvaluate($method, $path)) {
            return $next($request);
        }

        $verdict = $this->shim->evaluate($method, $path, [
            'headers' => $this->headerMap($request),
            'body' => $request->all(),
            'preview' => $request->headers->get('x-sarcio-preview') ?? '',
        ]);

        if ($verdict === null) {
            return $next($request); // fail closed
        }

        if ($verdict->isRespond()) {
            return $this->staticResponse($verdict);
        }

        if ($verdict->decision === Verdict::PROCEED_WITH_OVERRIDES) {
            if (($body = $verdict->body()) !== null) {
                $request->replace($body);
                if ($request->isJson()) {
                    $request->setJson(new \Symfony\Component\HttpFoundation\InputBag($body));
                }
            }
            $request->attributes->set('sarcio', [
                'skipValidations' => $verdict->skipValidations(),
                'config' => $verdict->config(),
            ]);

            $response = $next($request);

            foreach ($verdict->responseHeaders() as $header) {
                $response->headers->set((string) $header['name'], (string) $header['value']);
            }
            $this->applyResponseTransform($response, $verdict->responseTransform());
            return $response;
        }

        return $next($request);
    }

    /** @return array<string,string> */
    private function headerMap(Request $request): array
    {
        $out = [];
        foreach ($request->headers->all() as $name => $values) {
            $out[(string) $name] = is_array($values) ? implode(', ', $values) : (string) $values;
        }
        return $out;
    }

    private function staticResponse(Verdict $verdict): Response
    {
        $response = new \Illuminate\Http\JsonResponse($verdict->responseBody(), $verdict->responseStatus());
        foreach ($verdict->responseHeaderList() as $header) {
            $response->headers->set((string) $header['name'], (string) $header['value']);
        }
        return $response;
    }

    /**
     * Apply declarative response-field ops to a JSON response body. Data only:
     * each op sets a named field to a constant (including null).
     *
     * @param array<int,array{field:string,value:mixed}> $ops
     */
    private function applyResponseTransform(Response $response, array $ops): void
    {
        if ($ops === [] || !$response instanceof \Illuminate\Http\JsonResponse) {
            return;
        }
        $data = $response->getData(true);
        if (!is_array($data)) {
            return;
        }
        foreach ($ops as $op) {
            $data[(string) $op['field']] = $op['value'] ?? null;
        }
        $response->setData($data);
    }
}
