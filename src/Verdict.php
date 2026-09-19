<?php

declare(strict_types=1);

namespace Sarcio\Shim;

/**
 * A verdict returned by the sidecar. Verdicts are data, never code — the shim
 * applies named overrides or a provided static response, or it proceeds. A null
 * Verdict (returned from the shim) means "fail closed": run original behavior.
 */
final class Verdict
{
    public const PROCEED = 'proceed';
    public const PROCEED_WITH_OVERRIDES = 'proceed_with_overrides';
    public const RESPOND = 'respond';

    /**
     * @param array<string,mixed> $overrides
     * @param array<string,mixed> $response
     */
    public function __construct(
        public readonly string $decision,
        public readonly array $overrides = [],
        public readonly array $response = [],
    ) {
    }

    public static function fromFrame(array $frame): self
    {
        return new self(
            (string) ($frame['decision'] ?? self::PROCEED),
            is_array($frame['overrides'] ?? null) ? $frame['overrides'] : [],
            is_array($frame['response'] ?? null) ? $frame['response'] : [],
        );
    }

    public function isRespond(): bool
    {
        return $this->decision === self::RESPOND;
    }

    public function isProceed(): bool
    {
        return $this->decision === self::PROCEED;
    }

    /** The fully-transformed request body, if the verdict carries one. */
    public function body(): ?array
    {
        return is_array($this->overrides['body'] ?? null) ? $this->overrides['body'] : null;
    }

    /** @return string[] */
    public function skipValidations(): array
    {
        return array_values(array_filter(
            (array) ($this->overrides['skipValidations'] ?? []),
            'is_string',
        ));
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return is_array($this->overrides['config'] ?? null) ? $this->overrides['config'] : [];
    }

    /** @return array<int,array{name:string,value:string}> */
    public function responseHeaders(): array
    {
        return is_array($this->overrides['responseHeaders'] ?? null) ? $this->overrides['responseHeaders'] : [];
    }

    /** @return array<int,array{field:string,value:mixed}> */
    public function responseTransform(): array
    {
        return is_array($this->overrides['responseTransform'] ?? null) ? $this->overrides['responseTransform'] : [];
    }

    public function responseStatus(): int
    {
        return (int) ($this->response['status'] ?? 200);
    }

    /** @return array<int,array{name:string,value:string}> */
    public function responseHeaderList(): array
    {
        return is_array($this->response['headers'] ?? null) ? $this->response['headers'] : [];
    }

    public function responseBody(): array
    {
        return is_array($this->response['body'] ?? null) ? $this->response['body'] : [];
    }
}
