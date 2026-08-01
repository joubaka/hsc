<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_visitors', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_reference')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->index();
            $table->string('mobile')->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->string('status')->default('pending_verification')->index();
            $table->string('preferred_language')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('last_booking_at')->nullable();
            $table->string('session_token_hash', 64)->nullable()->unique();
            $table->timestamp('session_expires_at')->nullable();
            $table->timestamps();
            $table->unique(['email', 'mobile']);
        });
        Schema::create('visitor_verification_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('public_visitor_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('purpose');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('maximum_attempts')->default(5);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->string('request_fingerprint', 64)->nullable()->index();
            $table->timestamps();
        });
        Schema::create('public_booking_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_code_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('dry_run_checkout_enabled')->default(false);
            $table->boolean('email_verification_required')->default(true);
            $table->boolean('mobile_verification_required')->default(false);
            $table->unsignedSmallInteger('hold_minutes')->default(10);
            $table->unsignedSmallInteger('maximum_active_holds')->default(2);
            $table->unsignedSmallInteger('maximum_future_bookings')->default(3);
            $table->unsignedSmallInteger('maximum_participants')->default(4);
            $table->boolean('payment_required')->default(true);
            $table->boolean('temporary_access_eligible')->default(true);
            $table->string('terms_version')->default('1.0');
            $table->string('privacy_version')->default('1.0');
            $table->string('facility_rules_version')->default('1.0');
            $table->timestamps();
        });
        Schema::table('booking_holds', function (Blueprint $table) {
            $table->foreignId('public_visitor_id')->nullable()->after('member_id')->constrained()->nullOnDelete();
            $table->json('public_consent_snapshot')->nullable();
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('public_visitor_id')->nullable()->after('member_id')->index()->constrained()->nullOnDelete();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('public_visitor_id')->nullable()->after('member_id')->index()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->change();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('public_visitor_id')->nullable()->after('member_id')->index()->constrained()->nullOnDelete();
        });
        Schema::table('booking_participants', function (Blueprint $table) {
            $table->string('age_group')->nullable();
            $table->string('role')->default('guest');
            $table->boolean('guardian_consent')->default(false);
        });
        Schema::table('booking_refund_requests', fn (Blueprint $table) => $table->foreignId('member_id')->nullable()->change());
        Schema::table('booking_access_grants', fn (Blueprint $table) => $table->foreignId('member_id')->nullable()->change());
        Schema::create('public_booking_checkouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('checkout_reference')->unique();
            $table->foreignId('booking_hold_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('public_visitor_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('dry_run');
            $table->string('status')->default('pending')->index();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->string('provider_reference')->nullable()->unique();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('public_booking_management_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('public_visitor_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 8);
            $table->string('purpose')->default('manage');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedSmallInteger('use_count')->default(0);
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['booking_id', 'purpose', 'revoked_at'], 'public_management_lookup');
        });
        Schema::create('public_booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('public_visitor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sport_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->json('safe_payload')->nullable();
            $table->string('request_fingerprint', 64)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_booking_events');
        Schema::dropIfExists('public_booking_management_tokens');
        Schema::dropIfExists('public_booking_checkouts');
        Schema::table('booking_participants', fn (Blueprint $t) => $t->dropColumn(['age_group', 'role', 'guardian_consent']));
        Schema::table('payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('public_visitor_id'));
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('public_visitor_id');
        });
        Schema::table('bookings', fn (Blueprint $t) => $t->dropConstrainedForeignId('public_visitor_id'));
        Schema::table('booking_holds', function (Blueprint $t) {
            $t->dropConstrainedForeignId('public_visitor_id');
            $t->dropColumn('public_consent_snapshot');
        });
        Schema::dropIfExists('public_booking_settings');
        Schema::dropIfExists('visitor_verification_challenges');
        Schema::dropIfExists('public_visitors');
    }
};
