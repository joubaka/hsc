<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLightingConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->active && $this->user()->isCentralAdministrator();
    }

    public function rules(): array
    {
        return ['automation_enabled' => 'required|boolean', 'dry_run' => 'required|boolean', 'pre_start_minutes' => 'integer|between:0,60', 'post_end_minutes' => 'integer|between:0,60', 'maximum_continuous_on_minutes' => 'integer|between:15,480', 'minimum_billable_minutes' => 'integer|between:1,240', 'billing_increment_minutes' => 'integer|between:1,60', 'require_paid' => 'boolean', 'retry_count' => 'integer|between:0,10', 'retry_delay_seconds' => 'integer|between:5,3600', 'command_timeout_seconds' => 'integer|between:1,30', 'merge_window_minutes' => 'integer|between:0,60', 'manual_override_max_minutes' => 'integer|between:1,480', 'members_may_select' => 'boolean', 'live_confirmation' => 'nullable|string'];
    }
}
