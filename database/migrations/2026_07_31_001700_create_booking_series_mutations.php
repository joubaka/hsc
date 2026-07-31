<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_series_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_series_id')->constrained('booking_series')->restrictOnDelete();
            $table->foreignId('booking_series_occurrence_id')->nullable()->constrained('booking_series_occurrences')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('mutation_type', 40)->index();
            $table->string('scope', 30)->nullable();
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('series_version');
            $table->string('status', 30)->default('completed')->index();
            $table->json('request_snapshot')->nullable();
            $table->json('result_snapshot')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_series_mutations');
    }
};
