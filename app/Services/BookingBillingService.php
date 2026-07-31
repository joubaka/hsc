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
            'member_id' => $booking->member_id, 'currency' => $booking->currency, 'status' => 'draft',
            'subtotal_amount' => $booking->gross_amount, 'discount_amount' => $booking->allowance_amount + $booking->discount_amount,
            'total_amount' => $booking->total_amount, 'outstanding_amount' => $booking->total_amount,
            'source_type' => Booking::class, 'source_id' => $booking->id, 'billing_event' => 'facility_booking',
            'notes' => "Booking {$booking->booking_reference}",
        ]);
        $invoice->lines()->create([
            'line_type' => 'facility', 'financial_owner_type' => 'sport_code', 'sport_code_id' => $booking->sport_code_id,
            'description' => "{$booking->facility->name} court rental", 'quantity' => 1, 'unit_amount' => $booking->total_amount,
            'gross_amount' => $booking->gross_amount, 'discount_amount' => $booking->allowance_amount + $booking->discount_amount,
            'net_amount' => $booking->total_amount, 'tax_amount' => 0, 'total_amount' => $booking->total_amount,
            'source_type' => Booking::class, 'source_id' => $booking->id, 'snapshot_metadata' => $booking->price_snapshot,
        ]);
        $invoice = app(InvoiceService::class)->issueBookingInvoice($user, $invoice);
        $booking->update(['invoice_id' => $invoice->id]);
        Audit::record('booking.payment_required', $booking, [], ['invoice_id' => $invoice->id, 'amount' => $invoice->total_amount, 'currency' => $invoice->currency]);

        return $invoice;
    }
}
