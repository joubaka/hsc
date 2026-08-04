<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('communication_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->boolean('announcements_enabled')->default(true);
            $table->boolean('booking_confirmations_enabled')->default(true);
            $table->boolean('booking_cancellations_enabled')->default(true);
            $table->boolean('booking_reminders_enabled')->default(true);
            $table->unsignedSmallInteger('booking_reminder_hours')->default(24);
            $table->boolean('invoice_emails_enabled')->default(true);
            $table->boolean('payment_emails_enabled')->default(true);
            $table->boolean('respect_consent_for_transactional')->default(false);
            $table->string('sender_name')->default('Hermanus Sports Club');
            $table->string('sender_email')->nullable();
            $table->string('reply_to_email')->nullable();
            $table->string('email_footer', 500)->default('This is an official HSC member communication.');
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('communication_settings'); }
};
