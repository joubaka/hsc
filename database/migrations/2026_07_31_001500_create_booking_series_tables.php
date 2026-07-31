<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_series', function (Blueprint $table) {
            $table->id();
            $table->string('series_reference')->unique();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('sport_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('predecessor_series_id')->nullable()->constrained('booking_series')->restrictOnDelete();
            $table->string('series_type', 40);
            $table->string('status', 30)->default('draft')->index();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->time('start_time');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('timezone', 64);
            $table->string('recurrence_frequency', 20);
            $table->unsignedSmallInteger('recurrence_interval')->default(1);
            $table->json('days_of_week')->nullable();
            $table->json('monthly_pattern')->nullable();
            $table->unsignedSmallInteger('occurrence_limit')->nullable();
            $table->string('booking_type', 40)->default('block');
            $table->json('participant_template')->nullable();
            $table->unsignedSmallInteger('guest_count')->default(0);
            $table->text('member_notes')->nullable();
            $table->text('administrator_notes')->nullable();
            $table->string('billing_strategy', 30)->default('invoice_each_occurrence');
            $table->string('approval_status', 30)->default('draft')->index();
            $table->text('approval_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('rules_snapshot')->nullable();
            $table->json('pricing_strategy_snapshot')->nullable();
            $table->json('cancellation_policy_snapshot')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('idempotency_key')->unique();
            $table->timestamps();
        });
        Schema::create('booking_series_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_series_id')->constrained('booking_series')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('series_version');
            $table->unsignedSmallInteger('occurrence_number');
            $table->date('scheduled_date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 30)->default('planned')->index();
            $table->string('exception_type', 30)->nullable();
            $table->foreignId('superseded_by_occurrence_id')->nullable()->constrained('booking_series_occurrences')->restrictOnDelete();
            $table->json('preview_snapshot')->nullable();
            $table->text('outcome_message')->nullable();
            $table->timestamps();
            $table->unique(['booking_series_id', 'series_version', 'occurrence_number'], 'series_version_occurrence_unique');
            $table->unique(['booking_series_id', 'scheduled_date', 'starts_at'], 'series_scheduled_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_series_occurrences');
        Schema::dropIfExists('booking_series');
    }
};
