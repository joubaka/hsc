<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_check_in_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_code_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('opens_minutes_before')->default(30);
            $table->unsignedSmallInteger('closes_minutes_after')->default(10);
            $table->boolean('allow_member_self_check_in')->default(true);
            $table->boolean('allow_qr')->default(true);
            $table->boolean('allow_short_code')->default(true);
            $table->boolean('require_all_participants')->default(false);
            $table->unsignedSmallInteger('no_show_grace_minutes')->default(10);
            $table->boolean('automatic_no_show_enabled')->default(true);
            $table->boolean('automatic_release_enabled')->default(false);
            $table->unsignedSmallInteger('automatic_release_delay_minutes')->default(0);
            $table->unsignedSmallInteger('maximum_token_attempts')->default(5);
            $table->unsignedSmallInteger('token_lifetime_minutes')->default(120);
            $table->boolean('allow_without_payment')->default(false);
            $table->unsignedSmallInteger('access_lead_minutes')->default(30);
            $table->unsignedSmallInteger('access_lag_minutes')->default(15);
            $table->timestamps();
        });
        Schema::create('access_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->restrictOnDelete();
            $table->foreignId('sport_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->string('provider_mode')->default('null');
            $table->boolean('public_entry_zone')->default(false);
            $table->boolean('requires_booking')->default(true);
            $table->boolean('allows_permanent_member_access')->default(false);
            $table->boolean('allows_temporary_booking_access')->default(true);
            $table->unsignedSmallInteger('default_access_lead_minutes')->default(30);
            $table->unsignedSmallInteger('default_access_lag_minutes')->default(15);
            $table->timestamps();
        });
        Schema::create('facility_access_zone', function (Blueprint $table) {
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_zone_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['facility_id', 'access_zone_id']);
        });
        Schema::create('access_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_zone_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('provider')->default('null');
            $table->boolean('enabled')->default(false);
            $table->boolean('dry_run')->default(true);
            $table->text('endpoint_host')->nullable();
            $table->unsignedSmallInteger('endpoint_port')->nullable();
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->text('provider_settings')->nullable();
            $table->string('last_health_status')->default('unknown');
            $table->timestamp('last_health_checked_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();
        });
        Schema::create('booking_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('not_open');
            $table->boolean('check_in_required')->default(true);
            $table->timestamp('check_in_opens_at');
            $table->timestamp('check_in_closes_at');
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('check_in_method')->nullable();
            $table->timestamp('no_show_evaluated_at')->nullable();
            $table->timestamp('no_show_marked_at')->nullable();
            $table->foreignId('no_show_marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->json('policy_snapshot');
            $table->timestamps();
            $table->index(['status', 'check_in_closes_at']);
        });
        Schema::create('booking_participant_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_participant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('attendance_status')->default('expected');
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method')->nullable();
            $table->timestamps();
        });
        Schema::create('booking_check_in_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('mode');
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 8);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->unsignedSmallInteger('use_count')->default(0);
            $table->unsignedSmallInteger('maximum_uses')->default(1);
            $table->timestamps();
            $table->index(['booking_id', 'mode', 'revoked_at']);
        });
        Schema::create('booking_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_series_occurrence_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('access_zone_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('eligible');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->string('provider_mode');
            $table->json('zone_snapshot');
            $table->string('credential_type')->default('opaque_reference');
            $table->string('credential_reference')->nullable();
            $table->text('encrypted_credential')->nullable();
            $table->string('credential_hash', 64)->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();
            $table->unique(['booking_id', 'member_id', 'access_zone_id'], 'booking_member_zone_unique');
        });
        Schema::create('access_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('command_uuid')->unique();
            $table->foreignId('booking_access_grant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('access_endpoint_id')->nullable()->constrained()->nullOnDelete();
            $table->string('command_type');
            $table->timestamp('due_at');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('provider_result_code')->nullable();
            $table->string('safe_error_code')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['status', 'due_at', 'next_attempt_at']);
        });
        Schema::create('access_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('alert_type');
            $table->string('severity')->default('warning');
            $table->string('safe_message');
            $table->string('status')->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'alert_type', 'status'], 'access_alert_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_alerts');
        Schema::dropIfExists('access_commands');
        Schema::dropIfExists('booking_access_grants');
        Schema::dropIfExists('booking_check_in_tokens');
        Schema::dropIfExists('booking_participant_attendances');
        Schema::dropIfExists('booking_attendances');
        Schema::dropIfExists('access_endpoints');
        Schema::dropIfExists('facility_access_zone');
        Schema::dropIfExists('access_zones');
        Schema::dropIfExists('booking_check_in_settings');
    }
};
