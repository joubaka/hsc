<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_series_occurrences', function (Blueprint $table) {
            $table->timestamp('operational_conflict_at')->nullable()->after('exception_type')->index();
            $table->string('operational_conflict_reason')->nullable()->after('operational_conflict_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_series_occurrences', function (Blueprint $table) {
            $table->dropColumn(['operational_conflict_at', 'operational_conflict_reason']);
        });
    }
};
