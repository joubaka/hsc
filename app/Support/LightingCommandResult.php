<?php

namespace App\Support;

final readonly class LightingCommandResult
{
    public function __construct(public bool $success, public string $code, public ?string $state = null, public bool $retryable = false, public ?int $latencyMs = null) {}

    public static function success(string $code = 'ok', ?string $state = null, ?int $latencyMs = null): self
    {
        return new self(true, $code, $state, false, $latencyMs);
    }

    public static function failure(string $code, bool $retryable = false): self
    {
        return new self(false, $code, null, $retryable);
    }
}
