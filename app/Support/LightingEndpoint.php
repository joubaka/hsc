<?php

namespace App\Support;

use App\Models\LightingDeviceChannel;

final readonly class LightingEndpoint
{
    public function __construct(public int $deviceId, public int $channelId, public int $channelNumber, public string $provider, public ?string $host, public ?int $port, public bool $tls, public ?string $username, public ?string $password) {}

    public static function fromChannel(LightingDeviceChannel $channel): self
    {
        $d = $channel->device;

        return new self($d->id, $channel->id, $channel->channel_number, $d->provider, $d->network_host, $d->network_port, $d->use_tls, $d->username, $d->password);
    }
}
