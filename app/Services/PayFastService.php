<?php

namespace App\Services;

use App\Models\PublicBookingCheckout;
use Illuminate\Support\Facades\Http;

class PayFastService
{
    public function configured(): bool
    {
        return (bool) config('services.payfast.enabled') && filled(config('services.payfast.merchant_id')) && filled(config('services.payfast.merchant_key'));
    }

    public function checkout(PublicBookingCheckout $checkout): array
    {
        abort_unless($this->configured(), 422, 'PayFast payment is not configured yet.');
        $visitor = $checkout->visitor;
        $fields = [
            'merchant_id' => config('services.payfast.merchant_id'), 'merchant_key' => config('services.payfast.merchant_key'),
            'return_url' => url('/book-a-court?payment=return&hold='.$checkout->hold->hold_reference),
            'cancel_url' => url('/book-a-court?payment=cancelled&hold='.$checkout->hold->hold_reference),
            'notify_url' => url('/api/public/booking/payfast/notify'),
            'name_first' => $visitor->first_name, 'name_last' => $visitor->last_name, 'email_address' => $visitor->email,
            'm_payment_id' => $checkout->checkout_reference, 'amount' => number_format($checkout->amount / 100, 2, '.', ''),
            'item_name' => 'HSC court booking '.$checkout->hold->hold_reference,
        ];
        $fields['signature'] = $this->signature($fields);
        $checkout->update(['provider' => 'payfast']);
        return ['status' => 'redirect', 'provider' => 'payfast', 'action_url' => $this->processUrl(), 'fields' => $fields];
    }

    public function verify(array $payload): PublicBookingCheckout
    {
        abort_unless($this->configured(), 503, 'PayFast is not configured.');
        $signature = (string) ($payload['signature'] ?? ''); unset($payload['signature']);
        abort_unless($signature !== '' && hash_equals($this->signature($payload), $signature), 400, 'Invalid PayFast signature.');
        $checkout = PublicBookingCheckout::where('checkout_reference', $payload['m_payment_id'] ?? null)->firstOrFail();
        abort_unless(hash_equals((string) config('services.payfast.merchant_id'), (string) ($payload['merchant_id'] ?? '')), 400, 'Invalid PayFast merchant.');
        abort_unless(abs((float) ($payload['amount_gross'] ?? 0) - ($checkout->amount / 100)) < 0.01, 400, 'PayFast amount does not match.');
        abort_unless(($payload['payment_status'] ?? '') === 'COMPLETE', 400, 'PayFast payment is not complete.');
        $response = Http::asForm()->timeout(10)->post($this->validateUrl(), $payload + ['signature' => $signature]);
        abort_unless($response->successful() && trim($response->body()) === 'VALID', 400, 'PayFast could not validate this payment.');
        return $checkout;
    }

    private function signature(array $fields): string
    {
        $parts = [];
        foreach ($fields as $key => $value) if ($value !== '' && $value !== null) $parts[] = $key.'='.urlencode(trim((string) $value));
        if (filled(config('services.payfast.passphrase'))) $parts[] = 'passphrase='.urlencode(trim((string) config('services.payfast.passphrase')));
        return md5(implode('&', $parts));
    }

    private function processUrl(): string { return config('services.payfast.sandbox') ? 'https://sandbox.payfast.co.za/eng/process' : 'https://www.payfast.co.za/eng/process'; }
    private function validateUrl(): string { return config('services.payfast.sandbox') ? 'https://sandbox.payfast.co.za/eng/query/validate' : 'https://www.payfast.co.za/eng/query/validate'; }
}
