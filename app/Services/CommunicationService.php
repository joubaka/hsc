<?php

namespace App\Services;

use App\Jobs\SendCommunicationEmail;
use App\Models\Booking;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationDelivery;
use App\Models\CommunicationSetting;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class CommunicationService
{
    public function bookingConfirmed(int $bookingId): ?CommunicationDelivery
    {
        if (! $this->enabled('booking_confirmations_enabled')) return null;
        $booking = Booking::with(['member', 'facility.venue'])->find($bookingId);
        if (! $booking?->member?->email) return null;
        return $this->transactional('booking_confirmation', $booking->member, $booking, "Booking confirmed: {$booking->booking_reference}", $this->bookingBody($booking, '<strong>Your court booking is confirmed.</strong>'), "booking-confirmed:{$booking->id}");
    }

    public function bookingCancelled(int $bookingId): ?CommunicationDelivery
    {
        if (! $this->enabled('booking_cancellations_enabled')) return null;
        $booking = Booking::with(['member', 'facility.venue'])->find($bookingId);
        if (! $booking?->member?->email) return null;
        return $this->transactional('booking_cancellation', $booking->member, $booking, "Booking cancelled: {$booking->booking_reference}", $this->bookingBody($booking, '<strong>Your court booking has been cancelled.</strong>'), "booking-cancelled:{$booking->id}");
    }

    public function bookingReminder(Booking $booking): ?CommunicationDelivery
    {
        if (! $this->enabled('booking_reminders_enabled')) return null;
        $booking->loadMissing(['member', 'facility.venue']);
        if (! $booking->member?->email) return null;
        $hours = CommunicationSetting::current()->booking_reminder_hours;
        return $this->transactional('booking_reminder', $booking->member, $booking, "Upcoming booking reminder: {$booking->facility->name}", $this->bookingBody($booking, '<strong>This is a reminder about your upcoming booking.</strong>'), "booking-reminder-{$hours}h:{$booking->id}");
    }

    public function invoiceIssued(int $invoiceId): ?CommunicationDelivery
    {
        if (! $this->enabled('invoice_emails_enabled')) return null;
        $invoice = Invoice::with('member')->find($invoiceId);
        if (! $invoice?->member?->email || $invoice->total_amount <= 0) return null;
        $amount = number_format($invoice->total_amount / 100, 2);
        $body = "<p><strong>A new invoice has been issued to your HSC account.</strong></p><table cellpadding=\"6\"><tr><td><strong>Invoice</strong></td><td>{$invoice->invoice_number}</td></tr><tr><td><strong>Amount</strong></td><td>{$invoice->currency} {$amount}</td></tr><tr><td><strong>Due</strong></td><td>{$invoice->due_on?->format('j F Y')}</td></tr></table><p>View full account details in the member portal.</p>";
        return $this->transactional('invoice_issued', $invoice->member, $invoice, "Invoice issued: {$invoice->invoice_number}", $body, "invoice-issued:{$invoice->id}");
    }

    public function paymentConfirmed(int $paymentId): ?CommunicationDelivery
    {
        if (! $this->enabled('payment_emails_enabled')) return null;
        $payment = Payment::with(['member', 'receipt'])->find($paymentId);
        if (! $payment?->member?->email) return null;
        $amount = number_format($payment->amount / 100, 2);
        $receipt = $payment->receipt?->receipt_number ?: $payment->payment_reference;
        $body = "<p><strong>Thank you. Your payment has been recorded.</strong></p><table cellpadding=\"6\"><tr><td><strong>Receipt</strong></td><td>{$receipt}</td></tr><tr><td><strong>Amount</strong></td><td>{$payment->currency} {$amount}</td></tr><tr><td><strong>Date</strong></td><td>{$payment->received_on->format('j F Y')}</td></tr></table>";
        return $this->transactional('payment_confirmation', $payment->member, $payment, "Payment received: {$receipt}", $body, "payment-confirmed:{$payment->id}");
    }

    public function announcement(User $actor, array $data): CommunicationCampaign
    {
        abort_unless($this->enabled('announcements_enabled'), 422, 'Announcement emails are disabled in Site Settings.');
        $campaign = CommunicationCampaign::create([
            'type' => 'announcement', 'name' => $data['name'], 'subject' => $data['subject'],
            'body_html' => nl2br(e($data['body'])), 'audience_type' => $data['audience_type'],
            'audience_filter' => ['sport_code_id' => $data['sport_code_id'] ?? null, 'member_ids' => $data['member_ids'] ?? []],
            'status' => ! empty($data['scheduled_at']) && now()->lt($data['scheduled_at']) ? 'scheduled' : 'queued',
            'scheduled_at' => $data['scheduled_at'] ?? now(), 'created_by_user_id' => $actor->id,
        ]);
        foreach ($this->recipients($data) as $member) {
            CommunicationDelivery::create([
                'communication_campaign_id' => $campaign->id, 'member_id' => $member->id,
                'recipient_name' => $member->display_name, 'recipient_email' => $member->email,
                'subject' => $campaign->subject, 'body_html' => $campaign->body_html,
                'idempotency_key' => "campaign:{$campaign->id}:member:{$member->id}",
            ]);
        }
        if ($campaign->scheduled_at->isPast()) $this->queueCampaign($campaign);
        return $campaign->fresh('deliveries');
    }

    public function queueCampaign(CommunicationCampaign $campaign): void
    {
        if (! CommunicationSetting::current()->enabled) return;
        $campaign->deliveries()->whereIn('status', ['pending', 'failed'])->each(function ($delivery) {
            $delivery->update(['status' => 'queued', 'queued_at' => now(), 'failed_at' => null, 'failure_reason' => null]);
            SendCommunicationEmail::dispatch($delivery->id)->afterCommit();
        });
        $campaign->update(['status' => 'queued']);
    }

    public function retry(CommunicationDelivery $delivery): void
    {
        abort_unless(CommunicationSetting::current()->enabled, 422, 'Email communications are disabled in Site Settings.');
        $delivery->update(['status' => 'queued', 'queued_at' => now(), 'failed_at' => null, 'failure_reason' => null]);
        SendCommunicationEmail::dispatch($delivery->id);
    }

    private function transactional(string $type, Member $member, Model $related, string $subject, string $body, string $key): ?CommunicationDelivery
    {
        $settings = CommunicationSetting::current();
        if ($settings->respect_consent_for_transactional && ! $member->email_consent) {
            return null;
        }
        $campaign = CommunicationCampaign::firstOrCreate(['type' => $type, 'name' => Str::headline($type)], ['subject' => $subject, 'body_html' => $body, 'audience_type' => 'system', 'status' => 'active']);
        $delivery = CommunicationDelivery::firstOrCreate(['idempotency_key' => $key], [
            'communication_campaign_id' => $campaign->id, 'member_id' => $member->id, 'recipient_name' => $member->display_name,
            'recipient_email' => $member->email, 'subject' => $subject, 'body_html' => $body,
            'related_type' => $related::class, 'related_id' => $related->id,
        ]);
        if ($delivery->wasRecentlyCreated) {
            $delivery->update(['status' => 'queued', 'queued_at' => now()]);
            SendCommunicationEmail::dispatch($delivery->id)->afterCommit();
        }
        return $delivery;
    }

    private function enabled(string $feature): bool
    {
        $settings = CommunicationSetting::current();
        return $settings->enabled && $settings->{$feature};
    }

    private function recipients(array $data): Collection
    {
        return Member::query()->where('status', 'active')->whereNotNull('email')->where('email_consent', true)
            ->when($data['audience_type'] === 'sport_code', fn ($q) => $q->whereHas('sportCodes', fn ($s) => $s->where('sport_codes.id', $data['sport_code_id'])->where('member_sport_code.status', 'active')))
            ->when($data['audience_type'] === 'selected', fn ($q) => $q->whereIn('id', $data['member_ids'] ?? []))
            ->orderBy('last_name')->orderBy('first_name')->get();
    }

    private function bookingBody(Booking $booking, string $lead): string
    {
        $start = $booking->starts_at->copy()->setTimezone('Africa/Johannesburg');
        $end = $booking->ends_at->copy()->setTimezone('Africa/Johannesburg');
        return "<p>{$lead}</p><table cellpadding=\"6\"><tr><td><strong>Reference</strong></td><td>{$booking->booking_reference}</td></tr><tr><td><strong>Facility</strong></td><td>".e($booking->facility->name)."</td></tr><tr><td><strong>Date</strong></td><td>{$start->format('l, j F Y')}</td></tr><tr><td><strong>Time</strong></td><td>{$start->format('H:i')}–{$end->format('H:i')}</td></tr></table>";
    }
}
