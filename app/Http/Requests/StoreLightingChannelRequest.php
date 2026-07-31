<?php

namespace App\Http\Requests;

use App\Models\LightingDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLightingChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lightingDevice'));
    }

    public function rules(): array
    {
        $device = $this->route('lightingDevice');
        $channel = $this->route('channel');
        $maximum = $device instanceof LightingDevice && $device->provider === 'shelly' ? 3 : 15;

        return [
            'channel_number' => ['required', 'integer', "between:0,{$maximum}", Rule::unique('lighting_device_channels')->where('lighting_device_id', $device->id)->ignore($channel)],
            'name' => 'required|string|max:120',
            'enabled' => 'required|boolean',
            'maximum_continuous_on_minutes' => 'required|integer|between:15,480',
        ];
    }
}
