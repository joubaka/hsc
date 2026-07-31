<?php

namespace App\Support;

use App\Models\AccessEndpoint;

final readonly class AccessEndpointData
{
    public function __construct(public int $id, public string $provider, public bool $dryRun) {}

    public static function fromModel(AccessEndpoint $endpoint): self
    {
        return new self($endpoint->id, $endpoint->provider, $endpoint->dry_run);
    }
}
