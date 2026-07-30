<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_reference')->unique();
            $table->foreignId('member_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payer_name');
            $table->string('currency', 3)->default('ZAR');
            $table->bigInteger('amount');
            $table->date('received_on')->index();
            $table->string('payment_method', 40);
            $table->string('external_reference')->nullable();
            $table->string('bank_reference')->nullable();
            $table->string('import_fingerprint')->nullable()->unique();
            $table->string('status', 30)->default('pending')->index();
            $table->bigInteger('allocated_amount')->default(0);
            $table->bigInteger('unallocated_amount')->default(0);
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->string('source', 30)->default('manual');
            $table->timestamps();
            $table->unique(['source', 'external_reference'], 'payment_source_external_unique');
            $table->index(['member_id', 'status', 'received_on'], 'payment_member_status_index');
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('idempotency_key')->unique();
            $table->foreignId('allocated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'reversed_at'], 'allocation_invoice_active_index');
            $table->index(['payment_id', 'reversed_at'], 'allocation_payment_active_index');
        });

        Schema::create('credit_note_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('idempotency_key')->unique();
            $table->foreignId('applied_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->json('snapshot');
            $table->foreignId('generated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('credit_note_allocations');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
