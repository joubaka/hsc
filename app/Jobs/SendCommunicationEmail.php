<?php

namespace App\Jobs;

use App\Mail\CommunicationMail;
use App\Models\CommunicationDelivery;
use App\Models\CommunicationSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendCommunicationEmail implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public function __construct(public int $deliveryId) { $this->onQueue('communications'); }
    public function handle(): void
    {
        $delivery = CommunicationDelivery::findOrFail($this->deliveryId);
        if ($delivery->sent_at) return;
        $settings = CommunicationSetting::current();
        $feature = [
            'announcement' => 'announcements_enabled', 'booking_confirmation' => 'booking_confirmations_enabled',
            'booking_cancellation' => 'booking_cancellations_enabled', 'booking_reminder' => 'booking_reminders_enabled',
            'invoice_issued' => 'invoice_emails_enabled', 'payment_confirmation' => 'payment_emails_enabled',
        ][$delivery->campaign->type] ?? null;
        if (! $settings->enabled || ($feature && ! $settings->{$feature})) {
            $delivery->update(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => 'Disabled in Site Settings before delivery.']);
            return;
        }
        $delivery->increment('attempts');
        Mail::to($delivery->recipient_email, $delivery->recipient_name)->send(new CommunicationMail($delivery));
        $delivery->update(['status' => 'sent', 'sent_at' => now(), 'failed_at' => null, 'failure_reason' => null]);
        if (! $delivery->campaign->deliveries()->whereNotIn('status', ['sent', 'failed'])->exists()) {
            $delivery->campaign->update(['status' => 'sent', 'sent_at' => now()]);
        }
    }
    public function failed(Throwable $exception): void
    {
        CommunicationDelivery::whereKey($this->deliveryId)->update(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => mb_substr($exception->getMessage(), 0, 2000)]);
    }
}
