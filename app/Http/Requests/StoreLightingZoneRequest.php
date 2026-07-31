<?php

namespace App\Http\Requests;

use App\Models\LightingZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLightingZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $z = $this->route('lightingZone');

        return $z ? $this->user()->can('update', $z) : $this->user()->can('create', LightingZone::class);
    }

    public function rules(): array
    {
        return ['venue_id' => 'required|exists:venues,id', 'sport_code_id' => 'required|exists:sport_codes,id', 'lighting_device_channel_id' => 'nullable|exists:lighting_device_channels,id', 'name' => 'required|string|max:120', 'code' => ['required', 'string', 'max:50', Rule::unique('lighting_zones')->ignore($this->route('lightingZone'))], 'description' => 'nullable|string|max:1000', 'active' => 'boolean', 'automatic_control_enabled' => 'boolean', 'provider_mode' => 'required|in:dry_run,manual,shelly', 'pre_start_minutes' => 'required|integer|between:0,60', 'post_end_minutes' => 'required|integer|between:0,60', 'minimum_billable_minutes' => 'required|integer|between:1,240', 'billing_increment_minutes' => 'required|integer|between:1,60', 'maximum_continuous_on_minutes' => 'required|integer|between:15,480', 'financial_owner_type' => 'required|in:sport_code,central_hsc', 'facility_ids' => 'array', 'facility_ids.*' => 'integer|exists:facilities,id'];
    }
}
