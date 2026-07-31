<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_settings', function (Blueprint $table) {
            $table->id();
            $table->string('timezone')->default('Africa/Johannesburg');
            $table->string('currency', 3)->default('ZAR');
            $table->unsignedSmallInteger('hold_minutes')->default(10);
            $table->boolean('financial_standing_enabled')->default(false);
            $table->unsignedSmallInteger('default_advance_days')->default(7);
            $table->unsignedSmallInteger('default_minimum_notice_minutes')->default(60);
            $table->unsignedSmallInteger('default_min_duration_minutes')->default(30);
            $table->unsignedSmallInteger('default_max_duration_minutes')->default(120);
            $table->unsignedSmallInteger('default_start_interval_minutes')->default(30);
            $table->unsignedSmallInteger('default_max_future_bookings')->default(4);
            $table->unsignedInteger('default_hourly_rate_cents')->default(0);
            $table->timestamps();
        });
        Schema::create('booking_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('policy_type');
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('profile')->nullable();
            $table->foreignId('membership_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('rules');
            $table->unsignedInteger('priority')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();
            $table->index(['policy_type', 'scope_type', 'scope_id', 'active']);
        });
        Schema::create('booking_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('profile')->nullable();
            $table->foreignId('membership_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('pricing_method')->default('hourly');
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('guest_fee_cents')->default(0);
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('peak')->default(false);
            $table->boolean('active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();
            $table->index(['scope_type', 'scope_id', 'active']);
        });
        Schema::create('booking_allowance_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('sport_code_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('facility_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('profile')->nullable();
            $table->foreignId('membership_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('included_minutes')->default(0);
            $table->unsignedSmallInteger('included_bookings')->default(0);
            $table->string('recurrence')->default('week');
            $table->string('peak_scope')->default('all');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_allowance_policies');
        Schema::dropIfExists('booking_pricing_rules');
        Schema::dropIfExists('booking_policies');
        Schema::dropIfExists('booking_settings');
    }
};
