<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'announcements_enabled' => 'boolean',
            'booking_confirmations_enabled' => 'boolean', 'booking_cancellations_enabled' => 'boolean',
            'booking_reminders_enabled' => 'boolean', 'booking_reminder_hours' => 'integer',
            'invoice_emails_enabled' => 'boolean', 'payment_emails_enabled' => 'boolean',
            'respect_consent_for_transactional' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate([]);
    }
}
