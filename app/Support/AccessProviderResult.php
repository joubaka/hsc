<?php

namespace App\Support;

final readonly class AccessProviderResult
{
    public function __construct(public bool $success, public string $code, public bool $retryable = false, public ?string $state = null) {}
}
