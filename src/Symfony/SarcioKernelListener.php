<?php

declare(strict_types=1);

namespace Sarcio\Shim\Symfony;

use Sarcio\Shim\Shim;
use Sarcio\Shim\Verdict;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Symfony kernel listener ported onto the v1 protocol, sharing the same
 * framework-neutral core as the Laravel middleware. On kernel.request it applies
 * request-side overrides (or short-circuits with a static response); on
 * kernel.response it applies response headers and declarative transforms. Any
 * sidecar failure fails closed — the request runs unchanged.
 *
 * Register both listeners:
 *   kernel.request  => onKernelRequest  (priority before the controller)
 *   kernel.response => onKernelResponse
 */
final class SarcioKernelListener
{
    private const ATTR = '_sarcio_verdict';

    public function __construct(private readonly Shim $shim)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $method = $request->getMethod();
        $path = $request->getPathInfo();

        if (!$this->shim->shouldEvaluate($method, $path)) {
            return;
        }

        $verdict = $this->shim->evaluate($method, $path, [
            'headers' => $this->headerMap($request),
            'body' => $this->body($request),
            'preview' => $request->headers->get('x-sarcio-preview') ?? '',
        ]);

        if ($verdict === null) {
            return; // fail closed
        }

        if ($verdict->isRespond()) {
            $response = new JsonResponse($verdict->responseBody(), $verdict->responseStatus());
            foreach ($verdict->responseHeaderList() as $header) {
                $response->headers->set((string) $header['name'], (string) $header['value']);
            }
            $event->setResponse($response); // short-circuit the controller
            return;
        }

        if ($verdict->decision === Verdict::PROCEED_WITH_OVERRIDES) {
            if (($body = $verdict->body()) !== null) {
                $request->request->replace($body);
            }
            $request->attributes->set('sarcio', [
                'skipValidations' => $verdict->skipValidations(),
                'config' => $verdict->config(),
            ]);
            // Stash for the response listener.
            $request->attributes->set(self::ATTR, $verdict);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $verdict = $event->getRequest()->attributes->get(self::ATTR);
        if (!$verdict instanceof Verdict) {
            return;
        }
        $response = $event->getResponse();
        foreach ($verdict->responseHeaders() as $header) {
            $response->headers->set((string) $header['name'], (string) $header['value']);
        }
        $ops = $verdict->responseTransform();
        if ($ops !== [] && $response instanceof JsonResponse) {
            $data = json_decode((string) $response->getContent(), true);
            if (is_array($data)) {
                foreach ($ops as $op) {
                    $data[(string) $op['field']] = $op['value'] ?? null;
                }
                $response->setData($data);
            }
        }
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

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        if (str_contains((string) $request->headers->get('content-type'), 'json')) {
            $decoded = json_decode((string) $request->getContent(), true);
            return is_array($decoded) ? $decoded : [];
        }
        return $request->request->all();
    }
}
