<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('hold_reference')->unique();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamp('expires_at');
            $table->string('status')->default('active');
            $table->json('rule_snapshot');
            $table->json('price_snapshot');
            $table->json('allowance_snapshot')->nullable();
            $table->json('cancellation_snapshot');
            $table->string('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['facility_id', 'status', 'starts_at', 'ends_at', 'expires_at'], 'holds_conflict_index');
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_reference')->unique();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('sport_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('booking_hold_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('payment_status');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('guest_count')->default(0);
            $table->string('profile');
            $table->string('currency', 3);
            $table->unsignedInteger('gross_amount')->default(0);
            $table->unsignedInteger('allowance_amount')->default(0);
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('total_amount')->default(0);
            $table->json('rule_snapshot');
            $table->json('price_snapshot');
            $table->json('allowance_snapshot')->nullable();
            $table->json('cancellation_snapshot');
            $table->string('confirmation_key')->unique();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['facility_id', 'status', 'starts_at', 'ends_at'], 'bookings_conflict_index');
            $table->index(['member_id', 'status', 'starts_at']);
        });
        Schema::create('booking_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('participant_type');
            $table->string('display_name');
            $table->timestamps();
        });
        Schema::create('booking_allowance_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_allowance_policy_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('minutes');
            $table->unsignedInteger('value_cents');
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'period_starts_on', 'period_ends_on'], 'allowance_usage_member_period_index');
        });
        Schema::create('booking_rejection_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason_code');
            $table->date('requested_on');
            $table->unsignedInteger('count')->default(1);
            $table->timestamps();
            $table->unique(['facility_id', 'reason_code', 'requested_on'], 'booking_rejection_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_rejection_logs');
        Schema::dropIfExists('booking_allowance_usages');
        Schema::dropIfExists('booking_participants');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('booking_holds');
    }
};
