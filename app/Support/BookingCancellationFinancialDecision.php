<?php

namespace App\Support;

final readonly class BookingCancellationFinancialDecision
{
    public function __construct(
        public int $bookingId, public ?int $invoiceId, public int $amountOriginallyCharged,
        public int $amountPaid, public int $amountPreviouslyRefunded, public int $amountPreviouslyCredited,
        public int $eligibleAmount, public string $outcomeType, public int $compensationPercentage,
        public int $allowanceMinutesToRestore, public ?int $policyId, public ?int $policyVersion,
        public string $policyBand, public array $financialOwnerBreakdown, public bool $requiresFinanceReview,
        public string $explanation, public string $idempotencyKey, public string $currency,
    ) {}

    public function toArray(): array
    {
        return [
            'booking_id' => $this->bookingId, 'invoice_id' => $this->invoiceId,
            'amount_originally_charged' => $this->amountOriginallyCharged, 'amount_paid' => $this->amountPaid,
            'amount_previously_refunded' => $this->amountPreviouslyRefunded,
            'amount_previously_credited' => $this->amountPreviouslyCredited, 'eligible_amount' => $this->eligibleAmount,
            'outcome_type' => $this->outcomeType, 'compensation_percentage' => $this->compensationPercentage,
            'allowance_minutes_to_restore' => $this->allowanceMinutesToRestore, 'policy_id' => $this->policyId,
            'policy_version' => $this->policyVersion, 'policy_band' => $this->policyBand,
            'financial_owner_breakdown' => $this->financialOwnerBreakdown,
            'requires_finance_review' => $this->requiresFinanceReview, 'explanation' => $this->explanation,
            'idempotency_key' => $this->idempotencyKey, 'currency' => $this->currency,
        ];
    }
}
