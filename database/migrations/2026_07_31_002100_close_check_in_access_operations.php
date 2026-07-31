<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_check_in_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 8);
            $table->timestamp('issued_at');
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_attendance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_participant_attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('corrected_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->text('reason');
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
        });
        Schema::create('access_emergency_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('action_uuid')->unique();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('status');
            $table->text('reason');
            $table->unsignedInteger('affected_grants')->default(0);
            $table->foreignId('initiated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('operational_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sport_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('safe_payload');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['event_type', 'subject_type', 'subject_id', 'user_id'], 'operational_notification_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_notifications');
        Schema::dropIfExists('access_emergency_actions');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('facility_check_in_tokens');
    }
};
