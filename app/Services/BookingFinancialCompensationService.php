<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingCancellationCompensation;
use App\Models\BookingRefundRequest;
use App\Models\CreditNote;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class BookingFinancialCompensationService
{
    public function __construct(
        private BookingCancellationDecisionService $decisions,
        private DocumentNumberService $numbers,
    ) {}

    public function preview(User $actor, Booking $booking): array
    {
        $this->authorizeCancellation($actor, $booking);

        return [
            'booking' => [
                'id' => $booking->id, 'booking_reference' => $booking->booking_reference,
                'facility' => $booking->facility?->name, 'starts_at' => $booking->starts_at, 'ends_at' => $booking->ends_at,
            ],
            'decision' => $booking->cancellationCompensation?->decision_snapshot ?? $this->decisions->decide($booking)->toArray(),
            'existing' => $booking->cancellationCompensation?->load(['creditNote', 'refundRequest']),
        ];
    }

    public function cancel(User $actor, Booking $booking, string $reason, string $idempotencyKey, string $source = 'member_portal'): BookingCancellationCompensation
    {
        $this->authorizeCancellation($actor, $booking);
        if ($existing = BookingCancellationCompensation::where('booking_id', $booking->id)->first()) {
            return $existing->load(['booking.facility', 'creditNote', 'refundRequest']);
        }

        return DB::transaction(function () use ($actor, $booking, $reason, $idempotencyKey, $source) {
            $booking = Booking::lockForUpdate()->findOrFail($booking->id);
            if ($existing = BookingCancellationCompensation::where('booking_id', $booking->id)->first()) {
                return $existing->load(['booking.facility', 'creditNote', 'refundRequest']);
            }
            abort_unless(in_array($booking->status, ['confirmed', 'pending_payment'], true), 422, 'This booking cannot be cancelled.');
            $invoice = $booking->invoice_id ? Invoice::with('lines')->lockForUpdate()->findOrFail($booking->invoice_id) : null;
            if ($invoice) {
                $invoice->allocations()->whereNull('reversed_at')->lockForUpdate()->get();
                $invoice->creditNotes()->lockForUpdate()->get();
            }
            $decision = $this->decisions->decide($booking->fresh());
            $compensation = BookingCancellationCompensation::create([
                'booking_id' => $booking->id, 'invoice_id' => $invoice?->id, 'member_id' => $booking->member_id,
                'initiated_by_user_id' => $actor->id, 'request_source' => $source, 'idempotency_key' => $decision->idempotencyKey,
                'reason' => $reason, 'outcome_type' => $decision->outcomeType,
                'status' => $decision->requiresFinanceReview ? 'pending' : 'completed', 'currency' => $decision->currency,
                'amount_originally_charged' => $decision->amountOriginallyCharged, 'amount_paid' => $decision->amountPaid,
                'amount_previously_refunded' => $decision->amountPreviouslyRefunded,
                'amount_previously_credited' => $decision->amountPreviouslyCredited, 'eligible_amount' => $decision->eligibleAmount,
                'compensation_percentage' => $decision->compensationPercentage,
                'allowance_minutes_restored' => $decision->allowanceMinutesToRestore,
                'policy_id' => $decision->policyId, 'policy_version' => $decision->policyVersion,
                'policy_band' => $decision->policyBand, 'financial_owner_breakdown' => $decision->financialOwnerBreakdown,
                'decision_snapshot' => $decision->toArray(), 'requires_finance_review' => $decision->requiresFinanceReview,
                'explanation' => $decision->explanation, 'requested_at' => now(),
            ]);

            if (in_array($decision->outcomeType, ['credited', 'partially_credited'], true)) {
                $compensation->update(['credit_note_id' => $this->createBookingCredit(
                    $actor, $booking, $invoice, $decision->financialOwnerBreakdown, $decision->eligibleAmount, $reason,
                )->id]);
            } elseif ($decision->outcomeType === 'refund_pending') {
                $paymentId = $invoice->allocations()->whereNull('reversed_at')->value('payment_id');
                BookingRefundRequest::create([
                    'booking_cancellation_compensation_id' => $compensation->id, 'booking_id' => $booking->id,
                    'invoice_id' => $invoice->id, 'payment_id' => $paymentId, 'member_id' => $booking->member_id,
                    'currency' => $decision->currency, 'entitlement_amount' => $decision->eligibleAmount,
                    'financial_owner_breakdown' => $decision->financialOwnerBreakdown,
                    'idempotency_key' => "booking-refund-request:{$booking->id}",
                ]);
                Audit::record('booking.refund_entitlement_created', $compensation, [], [
                    'booking_id' => $booking->id, 'invoice_id' => $invoice->id, 'amount' => $decision->eligibleAmount,
                    'currency' => $decision->currency, 'owners' => $decision->financialOwnerBreakdown,
                ]);
            } elseif ($decision->outcomeType === 'invoice_cancelled' && $invoice?->status === 'issued' && $invoice->source_type === Booking::class) {
                app(InvoiceService::class)->voidBookingInvoice($actor, $invoice, $booking, "Booking cancelled: {$reason}");
            }

            if ($decision->allowanceMinutesToRestore > 0) {
                $booking->allowanceUsage()->whereNull('restored_at')->update(['restored_at' => now()]);
                Audit::record('booking.allowance_restored', $compensation, [], ['booking_id' => $booking->id, 'minutes' => $decision->allowanceMinutesToRestore]);
            }
            $booking->update([
                'status' => 'cancelled', 'payment_status' => $decision->outcomeType, 'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id, 'cancellation_reason' => $reason,
            ]);
            Audit::record('booking.cancelled', $booking, [], [
                'reason' => $reason, 'outcome' => $decision->outcomeType, 'amount' => $decision->eligibleAmount,
                'policy_id' => $decision->policyId, 'policy_version' => $decision->policyVersion, 'idempotency_key' => $idempotencyKey,
            ]);
            event(new \App\Events\BookingCancelled($booking->id));

            return $compensation->fresh(['booking.facility', 'creditNote', 'refundRequest']);
        }, 3);
    }

    public function completeRefund(User $actor, BookingRefundRequest $request, string $externalReference): BookingRefundRequest
    {
        abort_unless($actor->isCentralAdministrator(), 403);

        return DB::transaction(function () use ($actor, $request, $externalReference) {
            $request = BookingRefundRequest::lockForUpdate()->findOrFail($request->id);
            if ($request->status === 'processed') {
                return $request->load(['booking', 'invoice', 'payment']);
            }
            abort_unless($request->status === 'pending_finance_review', 422, 'This refund request is not pending.');
            $invoice = Invoice::lockForUpdate()->findOrFail($request->invoice_id);
            $payment = $request->payment_id ? Payment::lockForUpdate()->findOrFail($request->payment_id) : null;
            abort_unless($invoice->member_id === $request->member_id && (! $payment || $payment->member_id === $request->member_id), 422);
            $alreadyRefunded = (int) abs($invoice->transactions()->where('transaction_type', 'booking_refund')->sum('amount'));
            abort_unless($alreadyRefunded + $request->entitlement_amount <= $invoice->paid_amount, 422, 'Refund exceeds the paid booking amount.');

            foreach ($request->financial_owner_breakdown as $index => $owner) {
                FinancialTransaction::firstOrCreate(['idempotency_key' => "booking-refund:{$request->id}:owner:{$index}"], [
                    'member_id' => $request->member_id, 'invoice_id' => $invoice->id, 'payment_id' => $payment?->id,
                    'membership_period_id' => $invoice->membership_period_id, 'sport_code_id' => $owner['sport_code_id'],
                    'financial_owner_type' => $owner['financial_owner_type'], 'transaction_type' => 'booking_refund',
                    'currency' => $request->currency, 'amount' => -$owner['amount'], 'occurred_on' => now()->toDateString(),
                    'reference' => $externalReference, 'reason' => 'Booking cancellation refund', 'created_by_user_id' => $actor->id,
                ]);
            }
            $request->update([
                'status' => 'processed', 'processed_amount' => $request->entitlement_amount,
                'external_reference' => $externalReference, 'processed_by_user_id' => $actor->id, 'processed_at' => now(),
            ]);
            $partial = $request->entitlement_amount < $invoice->paid_amount;
            if ($payment) {
                $paymentRefunded = (int) abs($payment->transactions()->where('transaction_type', 'booking_refund')->sum('amount'));
                $payment->update(['status' => $paymentRefunded >= $payment->amount ? 'refunded' : 'partially_refunded']);
            }
            $request->compensation()->update(['status' => 'completed', 'outcome_type' => $partial ? 'partially_refunded' : 'refunded']);
            $request->booking()->update(['payment_status' => $partial ? 'partially_refunded' : 'refunded']);
            Audit::record('booking.refund_completed', $request, [], [
                'booking_id' => $request->booking_id, 'invoice_id' => $invoice->id, 'payment_id' => $payment?->id,
                'amount' => $request->entitlement_amount, 'currency' => $request->currency,
                'owners' => $request->financial_owner_breakdown, 'external_reference' => $externalReference,
            ]);

            return $request->fresh(['booking', 'invoice', 'payment']);
        }, 3);
    }

    private function createBookingCredit(User $actor, Booking $booking, Invoice $invoice, array $owners, int $amount, string $reason): CreditNote
    {
        $bookingOwned = $invoice->source_type === Booking::class && (int) $invoice->source_id === $booking->id;
        $seriesOwned = $invoice->source_type === \App\Models\BookingSeries::class
            && $invoice->lines()->where('source_type', Booking::class)->where('source_id', $booking->id)->exists();
        abort_unless($bookingOwned || $seriesOwned, 422);
        abort_unless($invoice->member_id === $booking->member_id && $invoice->currency === $booking->currency, 422);
        abort_unless($amount > 0 && collect($owners)->sum('amount') === $amount, 422);
        $note = CreditNote::create([
            'credit_note_number' => $this->numbers->next('credit_note'), 'invoice_id' => $invoice->id,
            'member_id' => $booking->member_id, 'currency' => $invoice->currency, 'status' => 'issued',
            'total_amount' => $amount, 'applied_amount' => 0, 'reason' => "Booking {$booking->booking_reference}: {$reason}",
            'issued_by_user_id' => $actor->id, 'issued_on' => now()->toDateString(),
        ]);
        foreach ($owners as $owner) {
            $note->lines()->create([
                'invoice_line_id' => $owner['invoice_line_id'], 'financial_owner_type' => $owner['financial_owner_type'],
                'sport_code_id' => $owner['sport_code_id'], 'description' => "Booking cancellation credit: {$booking->booking_reference}",
                'amount' => $owner['amount'],
            ]);
        }
        foreach ($owners as $index => $owner) {
            FinancialTransaction::firstOrCreate(['idempotency_key' => "booking-credit:{$booking->id}:owner:{$index}"], [
                'member_id' => $booking->member_id, 'invoice_id' => $invoice->id, 'credit_note_id' => $note->id,
                'membership_period_id' => $invoice->membership_period_id, 'sport_code_id' => $owner['sport_code_id'],
                'financial_owner_type' => $owner['financial_owner_type'], 'transaction_type' => 'booking_credit',
                'currency' => $invoice->currency, 'amount' => -$owner['amount'], 'occurred_on' => now()->toDateString(),
                'reference' => $note->credit_note_number, 'reason' => $reason, 'created_by_user_id' => $actor->id,
            ]);
        }
        Audit::record('booking.credit_created', $note, [], [
            'booking_id' => $booking->id, 'invoice_id' => $invoice->id, 'amount' => $amount,
            'currency' => $invoice->currency, 'owners' => $owners,
        ]);

        return $note;
    }

    private function authorizeCancellation(User $actor, Booking $booking): void
    {
        abort_unless($actor->active, 403);
        $own = $actor->member_id && (int) $booking->member_id === (int) $actor->member_id;
        abort_unless($own || app(BookingAccessService::class)->canManage($actor, $booking->facility), 403);
    }
}
