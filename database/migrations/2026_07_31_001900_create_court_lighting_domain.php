<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lighting_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_code_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->boolean('automation_enabled')->default(false);
            $table->boolean('dry_run')->default(true);
            $table->unsignedSmallInteger('pre_start_minutes')->default(10);
            $table->unsignedSmallInteger('post_end_minutes')->default(5);
            $table->unsignedSmallInteger('maximum_continuous_on_minutes')->default(240);
            $table->unsignedSmallInteger('minimum_billable_minutes')->default(30);
            $table->unsignedSmallInteger('billing_increment_minutes')->default(30);
            $table->string('default_financial_owner_type')->default('sport_code');
            $table->boolean('require_paid')->default(true);
            $table->boolean('allow_allowance')->default(true);
            $table->boolean('allow_waived')->default(true);
            $table->unsignedTinyInteger('retry_count')->default(3);
            $table->unsignedSmallInteger('retry_delay_seconds')->default(60);
            $table->unsignedSmallInteger('command_timeout_seconds')->default(5);
            $table->unsignedSmallInteger('merge_window_minutes')->default(10);
            $table->unsignedSmallInteger('manual_override_max_minutes')->default(120);
            $table->boolean('members_may_select')->default(true);
            $table->timestamps();
        });
        Schema::create('lighting_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('provider');
            $table->string('network_host')->nullable();
            $table->unsignedSmallInteger('network_port')->nullable();
            $table->boolean('use_tls')->default(false);
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->text('provider_settings')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('dry_run')->default(true);
            $table->string('last_health_status')->default('unknown');
            $table->timestamp('last_health_checked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('lighting_device_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lighting_device_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('channel_number');
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('maximum_continuous_on_minutes')->default(240);
            $table->string('last_known_state')->default('unknown');
            $table->timestamp('last_state_checked_at')->nullable();
            $table->string('manual_lock_state')->nullable();
            $table->timestamp('manual_lock_expires_at')->nullable();
            $table->timestamps();
            $table->unique(['lighting_device_id', 'channel_number'], 'lighting_device_channel_unique');
        });
        Schema::create('lighting_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->restrictOnDelete();
            $table->foreignId('sport_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('lighting_device_channel_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('automatic_control_enabled')->default(false);
            $table->string('provider_mode')->default('dry_run');
            $table->unsignedSmallInteger('pre_start_minutes')->default(10);
            $table->unsignedSmallInteger('post_end_minutes')->default(5);
            $table->unsignedSmallInteger('minimum_billable_minutes')->default(30);
            $table->unsignedSmallInteger('billing_increment_minutes')->default(30);
            $table->unsignedSmallInteger('maximum_continuous_on_minutes')->default(240);
            $table->string('financial_owner_type')->default('sport_code');
            $table->timestamps();
        });
        Schema::create('facility_lighting_zone', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('lighting_zone_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['facility_id', 'lighting_zone_id']);
        });
        Schema::create('lighting_requirement_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lighting_zone_id')->constrained()->cascadeOnDelete();
            $table->string('strategy');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('profile')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('lighting_tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lighting_zone_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('pricing_method');
            $table->unsignedBigInteger('amount_cents');
            $table->string('profile')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->unsignedSmallInteger('minimum_minutes')->default(30);
            $table->unsignedSmallInteger('increment_minutes')->default(30);
            $table->string('financial_owner_type')->default('sport_code');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::table('booking_holds', function (Blueprint $table) {
            $table->json('lighting_snapshot')->nullable()->after('cancellation_snapshot');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('lighting_snapshot')->nullable()->after('cancellation_snapshot');
        });
        Schema::table('booking_series', function (Blueprint $table) {
            $table->boolean('lighting_requested')->default(false)->after('billing_strategy');
        });
        Schema::create('lighting_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_series_id')->nullable()->constrained('booking_series')->nullOnDelete();
            $table->foreignId('booking_series_occurrence_id')->nullable()->constrained('booking_series_occurrences')->nullOnDelete();
            $table->foreignId('lighting_zone_id')->constrained()->restrictOnDelete();
            $table->string('status')->index();
            $table->timestamp('scheduled_on_at');
            $table->timestamp('scheduled_off_at');
            $table->timestamp('actual_on_at')->nullable();
            $table->timestamp('actual_off_at')->nullable();
            $table->unsignedSmallInteger('billable_minutes');
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 3)->default('ZAR');
            $table->json('pricing_snapshot');
            $table->json('zone_snapshot');
            $table->json('provider_snapshot');
            $table->string('payment_requirement');
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'lighting_zone_id'], 'lighting_reservation_booking_zone_unique');
        });
        Schema::create('lighting_schedule_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lighting_device_channel_id')->constrained()->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status')->default('planned');
            $table->char('planning_key', 64)->unique();
            $table->timestamps();
        });
        Schema::create('lighting_interval_reservation', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lighting_schedule_interval_id');
            $table->foreign('lighting_schedule_interval_id', 'lighting_interval_fk')->references('id')->on('lighting_schedule_intervals')->cascadeOnDelete();
            $table->foreignId('lighting_reservation_id')->constrained()->restrictOnDelete();
            $table->unique(['lighting_schedule_interval_id', 'lighting_reservation_id'], 'lighting_interval_reservation_unique');
        });
        Schema::create('lighting_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('command_uuid')->unique();
            $table->foreignId('lighting_reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('lighting_schedule_interval_id')->nullable();
            $table->foreign('lighting_schedule_interval_id', 'lighting_command_interval_fk')->references('id')->on('lighting_schedule_intervals')->nullOnDelete();
            $table->foreignId('lighting_device_channel_id')->constrained()->restrictOnDelete();
            $table->string('command_type');
            $table->timestamp('due_at');
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('provider_result_code')->nullable();
            $table->string('safe_error_code')->nullable();
            $table->string('idempotency_key')->unique();
            $table->foreignId('superseded_by_command_id')->nullable()->constrained('lighting_commands')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_at', 'next_attempt_at']);
        });
        Schema::create('lighting_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('alert_type');
            $table->string('severity')->default('warning');
            $table->string('status')->default('open');
            $table->text('safe_message');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'alert_type', 'status'], 'lighting_alert_source_status_unique');
        });
        Schema::create('lighting_manual_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lighting_device_channel_id')->constrained()->restrictOnDelete();
            $table->string('state');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->text('reason');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lighting_manual_overrides');
        Schema::dropIfExists('lighting_alerts');
        Schema::dropIfExists('lighting_commands');
        Schema::dropIfExists('lighting_interval_reservation');
        Schema::dropIfExists('lighting_schedule_intervals');
        Schema::dropIfExists('lighting_reservations');
        Schema::table('booking_series', fn (Blueprint $table) => $table->dropColumn('lighting_requested'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('lighting_snapshot'));
        Schema::table('booking_holds', fn (Blueprint $table) => $table->dropColumn('lighting_snapshot'));
        Schema::dropIfExists('lighting_tariffs');
        Schema::dropIfExists('lighting_requirement_policies');
        Schema::dropIfExists('facility_lighting_zone');
        Schema::dropIfExists('lighting_zones');
        Schema::dropIfExists('lighting_device_channels');
        Schema::dropIfExists('lighting_devices');
        Schema::dropIfExists('lighting_settings');
    }
};
