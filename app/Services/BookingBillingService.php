<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Audit;

class BookingBillingService
{
    public function createInvoice(User $user, Booking $booking): ?Invoice
    {
        if ($booking->total_amount === 0) {
            return null;
        }
        if ($existing = Invoice::where(['source_type' => Booking::class, 'source_id' => $booking->id, 'billing_event' => 'facility_booking'])->first()) {
            return $existing;
        }
        $invoice = Invoice::create([
            'member_id' => $booking->member_id, 'public_visitor_id' => $booking->public_visitor_id, 'currency' => $booking->currency, 'status' => 'draft',
            'subtotal_amount' => $booking->gross_amount, 'discount_amount' => $booking->allowance_amount + $booking->discount_amount,
            'total_amount' => $booking->total_amount, 'outstanding_amount' => $booking->total_amount,
            'source_type' => Booking::class, 'source_id' => $booking->id, 'billing_event' => 'facility_booking',
            'notes' => "Booking {$booking->booking_reference}",
        ]);
        $lightingAmount = (int) ($booking->lighting_snapshot['amount'] ?? 0);
        $facilityTotal = $booking->total_amount - $lightingAmount;
        $invoice->lines()->create([
            'line_type' => 'facility', 'financial_owner_type' => 'sport_code', 'sport_code_id' => $booking->sport_code_id,
            'description' => "{$booking->facility->name} court rental", 'quantity' => 1, 'unit_amount' => $facilityTotal,
            'gross_amount' => $booking->gross_amount - $lightingAmount, 'discount_amount' => $booking->allowance_amount + $booking->discount_amount,
            'net_amount' => $facilityTotal, 'tax_amount' => 0, 'total_amount' => $facilityTotal,
            'source_type' => Booking::class, 'source_id' => $booking->id, 'snapshot_metadata' => $booking->price_snapshot,
        ]);
        if ($lightingAmount > 0) {
            $invoice->lines()->create([
                'line_type' => 'lighting', 'financial_owner_type' => $booking->lighting_snapshot['financial_owner_type'] ?? 'sport_code',
                'sport_code_id' => $booking->sport_code_id, 'description' => ($booking->lighting_snapshot['zone']['name'] ?? 'Court').' lighting',
                'quantity' => 1, 'unit_amount' => $lightingAmount, 'gross_amount' => $lightingAmount, 'discount_amount' => 0,
                'net_amount' => $lightingAmount, 'tax_amount' => 0, 'total_amount' => $lightingAmount,
                'source_type' => Booking::class, 'source_id' => $booking->id, 'snapshot_metadata' => $booking->lighting_snapshot,
            ]);
        }
        $invoice = app(InvoiceService::class)->issueBookingInvoice($user, $invoice);
        $booking->update(['invoice_id' => $invoice->id]);
        Audit::record('booking.payment_required', $booking, [], ['invoice_id' => $invoice->id, 'amount' => $invoice->total_amount, 'currency' => $invoice->currency]);

        return $invoice;
    }
}
