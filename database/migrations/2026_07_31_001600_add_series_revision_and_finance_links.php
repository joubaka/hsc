<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('invoice_id', 'bookings_invoice_id_shared_index');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_invoice_id_unique');
        });
        Schema::table('booking_series', function (Blueprint $table) {
            $table->foreignId('root_series_id')->nullable()->after('predecessor_series_id')->constrained('booking_series')->restrictOnDelete();
            $table->foreignId('successor_series_id')->nullable()->after('root_series_id')->constrained('booking_series')->restrictOnDelete();
            $table->string('revision_idempotency_key')->nullable()->unique()->after('idempotency_key');
        });
        Schema::table('booking_series_occurrences', function (Blueprint $table) {
            $table->foreignId('superseded_booking_id')->nullable()->after('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->string('mutation_idempotency_key')->nullable()->unique()->after('exception_type');
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('bookings')
            ->whereIn('invoice_id', \Illuminate\Support\Facades\DB::table('invoices')->where('source_type', \App\Models\BookingSeries::class)->select('id'))
            ->update(['invoice_id' => null]);
        Schema::table('booking_series_occurrences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_booking_id');
            $table->dropColumn('mutation_idempotency_key');
        });
        Schema::table('booking_series', function (Blueprint $table) {
            $table->dropConstrainedForeignId('successor_series_id');
            $table->dropConstrainedForeignId('root_series_id');
            $table->dropColumn('revision_idempotency_key');
        });
        $indexes = collect(Schema::getIndexes('bookings'))->pluck('name');
        Schema::table('bookings', function (Blueprint $table) use ($indexes) {
            if ($indexes->contains('bookings_invoice_id_shared_index')) {
                $table->dropIndex('bookings_invoice_id_shared_index');
            }
            if (! $indexes->contains('bookings_invoice_id_unique')) {
                $table->unique('invoice_id');
            }
        });
    }
};
