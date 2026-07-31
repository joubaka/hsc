<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('address')->nullable();
            $table->text('description')->nullable();
            $table->string('timezone')->default('Africa/Johannesburg');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });
        Schema::create('facility_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->timestamps();
        });
        Schema::create('facility_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->restrictOnDelete();
            $table->foreignId('sport_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('facility_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('facility_type');
            $table->string('surface_type')->nullable();
            $table->boolean('indoor')->default(false);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->text('description')->nullable();
            $table->text('booking_instructions')->nullable();
            $table->string('image_path')->nullable();
            $table->string('access_zone')->nullable();
            $table->boolean('lighting_available')->default(false);
            $table->boolean('publicly_visible')->default(true);
            $table->boolean('bookable')->default(true);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->unsignedSmallInteger('default_duration_minutes')->default(60);
            $table->unsignedSmallInteger('start_interval_minutes')->default(30);
            $table->timestamps();
            $table->index(['sport_code_id', 'active', 'bookable']);
        });
        Schema::create('facility_facility_tag', function (Blueprint $table) {
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['facility_id', 'facility_tag_id']);
        });
        Schema::create('facility_opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['facility_id', 'day_of_week', 'active']);
        });
        Schema::create('facility_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->string('closure_type');
            $table->string('title');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('public_message')->nullable();
            $table->text('private_notes')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['facility_id', 'active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_closures');
        Schema::dropIfExists('facility_opening_hours');
        Schema::dropIfExists('facility_facility_tag');
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('facility_tags');
        Schema::dropIfExists('facility_groups');
        Schema::dropIfExists('venues');
    }
};
