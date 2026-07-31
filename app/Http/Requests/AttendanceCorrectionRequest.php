<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->active === true;
    }

    public function rules(): array
    {
        return ['action' => ['required', 'in:reverse_check_in,mark_late,mark_no_show,reverse_no_show,excuse_absence,release_court,keep_reserved,participant_checked_in,participant_absent,participant_excused'], 'reason' => ['required', 'string', 'min:5', 'max:500'], 'idempotency_key' => ['required', 'uuid'], 'participant_attendance_id' => ['nullable', 'exists:booking_participant_attendances,id']];
    }
}
