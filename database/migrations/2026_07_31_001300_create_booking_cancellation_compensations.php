<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_cancellation_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('request_source', 30)->default('member_portal');
            $table->string('idempotency_key')->unique();
            $table->text('reason');
            $table->string('outcome_type', 40)->index();
            $table->string('status', 30)->default('completed')->index();
            $table->string('currency', 3);
            $table->bigInteger('amount_originally_charged')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->bigInteger('amount_previously_refunded')->default(0);
            $table->bigInteger('amount_previously_credited')->default(0);
            $table->bigInteger('eligible_amount')->default(0);
            $table->unsignedTinyInteger('compensation_percentage')->default(0);
            $table->unsignedInteger('allowance_minutes_restored')->default(0);
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->unsignedInteger('policy_version')->nullable();
            $table->string('policy_band', 50)->nullable();
            $table->json('financial_owner_breakdown')->nullable();
            $table->json('decision_snapshot');
            $table->foreignId('credit_note_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->boolean('requires_finance_review')->default(false);
            $table->text('explanation');
            $table->timestamp('requested_at');
            $table->timestamps();
        });

        Schema::create('booking_refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_cancellation_compensation_id')->unique('refund_compensation_unique');
            $table->foreign('booking_cancellation_compensation_id', 'refund_compensation_fk')
                ->references('id')->on('booking_cancellation_compensations')->restrictOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->bigInteger('entitlement_amount');
            $table->bigInteger('processed_amount')->default(0);
            $table->json('financial_owner_breakdown');
            $table->string('status', 30)->default('pending_finance_review')->index();
            $table->string('idempotency_key')->unique();
            $table->string('external_reference')->nullable();
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('exception_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_refund_requests');
        Schema::dropIfExists('booking_cancellation_compensations');
    }
};
