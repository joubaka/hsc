<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSeries;
use App\Support\BookingCancellationFinancialDecision;
use Illuminate\Support\Carbon;

class BookingCancellationDecisionService
{
    public function decide(Booking $booking): BookingCancellationFinancialDecision
    {
        $booking->loadMissing(['invoice.lines', 'invoice.allocations', 'invoice.creditNotes', 'allowanceUsage']);
        $invoice = $booking->invoice;
        $snapshot = $booking->cancellation_snapshot ?: [];
        $beforeDeadline = isset($snapshot['cancellation_deadline'])
            && now()->lte(Carbon::parse($snapshot['cancellation_deadline']));
        $percentage = max(0, min(100, $beforeDeadline ? (int) ($snapshot['refund_percentage'] ?? 0) : 0));
        $seriesInvoice = $invoice?->source_type === BookingSeries::class;
        $bookingLines = $seriesInvoice
            ? $invoice->lines->where('source_type', Booking::class)->where('source_id', $booking->id)->values()
            : ($invoice?->lines ?? collect());
        $charged = (int) ($seriesInvoice ? $bookingLines->sum('total_amount') : ($invoice?->total_amount ?? $booking->total_amount));
        $invoicePaid = (int) ($invoice?->allocations()->whereNull('reversed_at')->sum('amount') ?? 0);
        $preceding = $seriesInvoice
            ? (int) $invoice->lines->where('id', '<', $bookingLines->min('id'))->sum('total_amount')
            : 0;
        $paid = $seriesInvoice ? max(0, min($charged, $invoicePaid - $preceding)) : $invoicePaid;
        $lineIds = $bookingLines->pluck('id');
        $credited = $seriesInvoice
            ? (int) \App\Models\CreditNoteLine::whereIn('invoice_line_id', $lineIds)->whereHas('creditNote', fn ($query) => $query->whereIn('status', ['issued', 'partially_applied', 'applied']))->sum('amount')
            : (int) ($invoice?->creditNotes()->whereIn('status', ['issued', 'partially_applied', 'applied'])->sum('total_amount') ?? 0);
        $refunded = (int) \App\Models\BookingRefundRequest::where('booking_id', $booking->id)->where('status', 'processed')->sum('processed_amount');
        $method = $snapshot['refund_method'] ?? 'credit';
        $policyAmount = intdiv($charged * $percentage, 100);
        $eligible = $seriesInvoice && $method === 'credit'
            ? min($policyAmount, max(0, $charged - $credited - $refunded))
            : min($policyAmount, $paid, max(0, $paid - $credited - $refunded));
        $allowanceMinutes = $booking->allowanceUsage && ! $booking->allowanceUsage->restored_at
            ? (int) $booking->allowanceUsage->minutes : 0;
        [$outcome, $review, $explanation] = match (true) {
            ! $invoice || $charged === 0 => [$allowanceMinutes > 0 ? 'allowance_restored' : 'not_applicable', false, $allowanceMinutes > 0 ? 'Your booking allowance will be restored.' : 'No payment adjustment is required.'],
            $paid === 0 && $seriesInvoice && $eligible > 0 => ['credited', false, 'The cancelled occurrence will be credited against the shared series invoice.'],
            $paid === 0 => ['invoice_cancelled', false, 'The unpaid booking invoice will be cancelled.'],
            $eligible === 0 => ['no_compensation', false, 'This booking is inside the non-refundable cancellation period.'],
            $method === 'refund' => ['refund_pending', true, 'Your refund entitlement will be recorded for finance processing.'],
            default => [$eligible < $paid ? 'partially_credited' : 'credited', false, 'Club credit will be issued automatically.'],
        };

        return new BookingCancellationFinancialDecision(
            $booking->id, $invoice?->id, $charged, $paid, $refunded, $credited, $eligible, $outcome,
            $percentage, $allowanceMinutes, ($snapshot['policy_ids'] ?? [null])[0] ?? null,
            $snapshot['version'] ?? null, $beforeDeadline ? ($snapshot['policy_band'] ?? 'before_deadline') : 'late_cancellation',
            $this->allocateOwners($bookingLines, $eligible), $review, $explanation,
            "booking-cancellation:{$booking->id}", $invoice?->currency ?? $booking->currency,
        );
    }

    private function allocateOwners($lines, int $amount): array
    {
        $positive = $lines->filter(fn ($line) => $line->total_amount > 0)->values();
        $base = max(1, (int) $positive->sum('total_amount'));
        $remaining = $amount;

        return $positive->map(function ($line, $index) use ($positive, $amount, $base, &$remaining) {
            $share = $index === $positive->count() - 1 ? $remaining : min($remaining, intdiv($amount * (int) $line->total_amount, $base));
            $remaining -= $share;

            return ['invoice_line_id' => $line->id, 'financial_owner_type' => $line->financial_owner_type, 'sport_code_id' => $line->sport_code_id, 'amount' => $share];
        })->filter(fn ($owner) => $owner['amount'] > 0)->values()->all();
    }
}
