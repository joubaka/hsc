<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('credit_note_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('membership_period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sport_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('financial_owner_type', 30)->index();
            $table->string('transaction_type', 40)->index();
            $table->string('currency', 3);
            $table->bigInteger('amount');
            $table->date('occurred_on')->index();
            $table->string('reference');
            $table->text('reason')->nullable();
            $table->string('idempotency_key')->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sport_code_id', 'membership_period_id', 'occurred_on'], 'ledger_sport_period_index');
            $table->index(['member_id', 'occurred_on'], 'ledger_member_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
