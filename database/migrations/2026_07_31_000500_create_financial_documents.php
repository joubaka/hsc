<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_settings', function (Blueprint $table) {
            $table->id();
            $table->string('default_currency', 3)->default('ZAR');
            $table->string('invoice_prefix', 20)->default('INV-');
            $table->string('receipt_prefix', 20)->default('RCT-');
            $table->string('credit_note_prefix', 20)->default('CN-');
            $table->unsignedTinyInteger('financial_year_start_month')->default(1);
            $table->unsignedSmallInteger('default_invoice_due_days')->default(30);
            $table->text('banking_reference_instructions')->nullable();
            $table->string('payment_reference_format')->default('{member_number}');
            $table->string('joining_fee_owner_type', 30)->default('sport_code');
            $table->boolean('generate_zero_value_invoices')->default(false);
            $table->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 30)->unique();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->nullable()->unique();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->default('ZAR');
            $table->string('status', 30)->default('draft')->index();
            $table->date('issued_on')->nullable()->index();
            $table->date('due_on')->nullable()->index();
            $table->bigInteger('subtotal_amount')->default(0);
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('total_amount')->default(0);
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('credited_amount')->default(0);
            $table->bigInteger('written_off_amount')->default(0);
            $table->bigInteger('outstanding_amount')->default(0)->index();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('billing_event', 80)->default('default');
            $table->text('notes')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'billing_event'], 'invoice_source_event_unique');
            $table->index(['membership_period_id', 'status', 'due_on'], 'invoice_reporting_index');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('line_type', 40);
            $table->string('financial_owner_type', 30)->index();
            $table->foreignId('sport_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_amount');
            $table->bigInteger('gross_amount');
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('net_amount');
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total_amount');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('snapshot_metadata')->nullable();
            $table->timestamps();
            $table->index(['sport_code_id', 'financial_owner_type'], 'invoice_line_owner_index');
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('credit_note_number')->nullable()->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->string('status', 30)->default('draft')->index();
            $table->bigInteger('total_amount')->default(0);
            $table->bigInteger('applied_amount')->default(0);
            $table->text('reason');
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('issued_on')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('financial_owner_type', 30);
            $table->foreignId('sport_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->bigInteger('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('financial_settings');
    }
};
