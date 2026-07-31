<?php

namespace App\Http\Requests;

use App\Models\LightingDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLightingDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LightingDevice::class);
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:120', 'code' => ['required', 'string', 'max:50', Rule::unique('lighting_devices')->ignore($this->route('lightingDevice'))], 'provider' => 'required|in:dry_run,manual,shelly', 'network_host' => 'nullable|string|max:253', 'network_port' => 'nullable|integer|between:1,65535', 'use_tls' => 'boolean', 'username' => 'nullable|string|max:100', 'password' => 'nullable|string|max:500', 'provider_settings' => 'nullable|array', 'enabled' => 'boolean', 'dry_run' => 'required|boolean', 'live_confirmation' => 'nullable|string|max:40'];
    }
}
