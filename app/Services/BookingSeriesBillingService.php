<?php

namespace App\Services;

use App\Models\BookingSeries;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class BookingSeriesBillingService
{
    public function createSeriesInvoice(User $actor, BookingSeries $series): ?Invoice
    {
        if ($series->billing_strategy !== 'one_invoice_for_series') {
            return null;
        }

        return DB::transaction(function () use ($actor, $series) {
            $series = BookingSeries::lockForUpdate()->findOrFail($series->id);
            $billingEvent = "initial_series_invoice_v{$series->version}";
            if ($existing = Invoice::where([
                'source_type' => BookingSeries::class,
                'source_id' => $series->id,
                'billing_event' => $billingEvent,
            ])->first()) {
                return $existing->load('lines');
            }

            $occurrences = $series->occurrences()
                ->where('series_version', $series->version)
                ->where('status', 'confirmed')
                ->whereHas('booking', fn ($query) => $query->whereIn('status', ['confirmed', 'pending_payment'])->where('total_amount', '>', 0))
                ->with(['booking.facility'])
                ->orderBy('occurrence_number')
                ->lockForUpdate()
                ->get();
            if ($occurrences->isEmpty()) {
                return null;
            }

            $total = (int) $occurrences->sum(fn ($occurrence) => $occurrence->booking->total_amount);
            $gross = (int) $occurrences->sum(fn ($occurrence) => $occurrence->booking->gross_amount);
            $invoice = Invoice::create([
                'member_id' => $series->member_id, 'currency' => $occurrences->first()->booking->currency,
                'status' => 'draft', 'subtotal_amount' => $gross, 'discount_amount' => $gross - $total,
                'total_amount' => $total, 'outstanding_amount' => $total,
                'source_type' => BookingSeries::class, 'source_id' => $series->id, 'billing_event' => $billingEvent,
                'notes' => "Recurring booking series {$series->series_reference}, version {$series->version}",
            ]);
            foreach ($occurrences as $occurrence) {
                $booking = $occurrence->booking;
                $lightingAmount = (int) ($booking->lighting_snapshot['amount'] ?? 0);
                $facilityTotal = $booking->total_amount - $lightingAmount;
                $invoice->lines()->create([
                    'line_type' => 'facility', 'financial_owner_type' => 'sport_code', 'sport_code_id' => $booking->sport_code_id,
                    'description' => "{$occurrence->scheduled_date->format('d M Y')} {$booking->starts_at->format('H:i')} {$booking->facility->name} ({$booking->booking_reference})",
                    'quantity' => 1, 'unit_amount' => $facilityTotal, 'gross_amount' => $booking->gross_amount - $lightingAmount,
                    'discount_amount' => $booking->allowance_amount + $booking->discount_amount,
                    'net_amount' => $facilityTotal, 'tax_amount' => 0, 'total_amount' => $facilityTotal,
                    'source_type' => $booking::class, 'source_id' => $booking->id,
                    'snapshot_metadata' => [...$booking->price_snapshot, 'booking_series_id' => $series->id, 'occurrence_id' => $occurrence->id, 'series_version' => $series->version],
                ]);
                if ($lightingAmount > 0) {
                    $invoice->lines()->create([
                        'line_type' => 'lighting', 'financial_owner_type' => $booking->lighting_snapshot['financial_owner_type'] ?? 'sport_code',
                        'sport_code_id' => $booking->sport_code_id,
                        'description' => "{$occurrence->scheduled_date->format('d M Y')} ".($booking->lighting_snapshot['zone']['name'] ?? 'Court').' lighting',
                        'quantity' => 1, 'unit_amount' => $lightingAmount, 'gross_amount' => $lightingAmount, 'discount_amount' => 0,
                        'net_amount' => $lightingAmount, 'tax_amount' => 0, 'total_amount' => $lightingAmount,
                        'source_type' => $booking::class, 'source_id' => $booking->id,
                        'snapshot_metadata' => [...$booking->lighting_snapshot, 'booking_series_id' => $series->id, 'occurrence_id' => $occurrence->id],
                    ]);
                }
            }
            abort_unless((int) $invoice->lines()->sum('total_amount') === $total, 500, 'Series invoice line reconciliation failed.');
            $invoice = app(InvoiceService::class)->issue($actor, $invoice);
            foreach ($occurrences as $occurrence) {
                $occurrence->booking->update(['invoice_id' => $invoice->id, 'payment_status' => 'unpaid']);
            }
            Audit::record('booking_series.invoice_created', $series, [], [
                'invoice_id' => $invoice->id, 'amount' => $total, 'occurrences' => $occurrences->pluck('id')->all(),
            ]);

            return $invoice;
        }, 3);
    }
}
