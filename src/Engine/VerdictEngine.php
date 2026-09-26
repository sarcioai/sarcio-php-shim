<?php

declare(strict_types=1);

namespace Sarcio\Shim\Engine;

use Sarcio\Shim\Verdict;

/**
 * Turns the verified, unexpired ops for one route plus the request body into a
 * verdict. Pure: no I/O and no code, data to data, decision for decision the
 * same as the sidecar, so a patch behaves identically whichever transport
 * delivered it.
 */
final class VerdictEngine
{
    /**
     * @param array<int,array<string,mixed>> $ops the route's ops, in manifest order
     */
    public static function evaluate(array $ops, mixed $body): Verdict
    {
        // A gate short-circuits everything else: the first gate op wins.
        foreach ($ops as $op) {
            if (($op['op'] ?? null) === 'gateRoute') {
                $responseBody = is_array($op['body'] ?? null) ? $op['body'] : [];
                return new Verdict(Verdict::RESPOND, [], [
                    'status' => (int) ($op['status'] ?? 0),
                    'headers' => [],
                    'body' => $responseBody,
                ]);
            }
        }

        $overrides = [];
        $touched = false;

        $bodyOps = array_values(array_filter(
            $ops,
            static fn (array $op): bool => in_array($op['op'] ?? null, ['defaultValue', 'stripField'], true),
        ));
        if ($bodyOps !== [] && is_array($body) && ($body === [] || !array_is_list($body))) {
            $overrides['body'] = self::applyBodyOps($bodyOps, $body);
            $touched = true;
        }

        foreach ($ops as $op) {
            switch ($op['op'] ?? null) {
                case 'skipValidation':
                    $overrides['skipValidations'][] = (string) ($op['name'] ?? '');
                    $touched = true;
                    break;
                case 'overrideConfig':
                    $overrides['config'][(string) ($op['key'] ?? '')] = $op['value'] ?? null;
                    $touched = true;
                    break;
                case 'setResponseHeader':
                    $value = $op['value'] ?? null;
                    $overrides['responseHeaders'][] = [
                        'name' => (string) ($op['name'] ?? ''),
                        'value' => is_string($value) ? $value : '',
                    ];
                    $touched = true;
                    break;
                case 'transformResponse':
                    // An explicit null reaches the verdict, so a field can be nulled out.
                    $overrides['responseTransform'][] = [
                        'field' => (string) ($op['field'] ?? ''),
                        'value' => $op['value'] ?? null,
                    ];
                    $touched = true;
                    break;
            }
        }

        if (!$touched) {
            return new Verdict(Verdict::PROCEED);
        }
        return new Verdict(Verdict::PROCEED_WITH_OVERRIDES, $overrides);
    }

    /**
     * defaultValue fills only a missing, null or empty-string field; stripField
     * removes one. Applied to a copy, in op order.
     *
     * @param array<int,array<string,mixed>> $ops
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private static function applyBodyOps(array $ops, array $body): array
    {
        $next = $body;
        foreach ($ops as $op) {
            $field = (string) ($op['field'] ?? '');
            if ($op['op'] === 'defaultValue') {
                $current = $next[$field] ?? null;
                if (!array_key_exists($field, $next) || $current === null || $current === '') {
                    $next[$field] = $op['value'] ?? null;
                }
            } else {
                unset($next[$field]);
            }
        }
        return $next;
    }
}
