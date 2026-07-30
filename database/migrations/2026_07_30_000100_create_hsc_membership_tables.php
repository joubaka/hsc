<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sport_codes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('colour', 20)->default('#7367F0');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('membership_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_code_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('billing_period', 30)->default('annual');
            $table->unsignedInteger('code_fee_cents')->default(0);
            $table->unsignedInteger('hsc_levy_cents')->default(0);
            $table->unsignedTinyInteger('minimum_age')->nullable();
            $table->unsignedTinyInteger('maximum_age')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('billing_email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('member_number', 20)->unique();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('preferred_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->date('date_of_birth')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('mobile', 30)->nullable()->index();
            $table->string('status', 30)->default('active')->index();
            $table->string('household_role', 80)->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_mobile', 30)->nullable();
            $table->boolean('email_consent')->default(true);
            $table->boolean('whatsapp_consent')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('member_sport_code', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sport_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('active');
            $table->string('role', 30)->default('member');
            $table->date('joined_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->timestamps();
            $table->unique(['member_id', 'sport_code_id']);
        });

        Schema::create('access_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('credential_type', 30)->default('pin');
            $table->string('status', 30)->default('not_issued');
            $table->json('areas')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_credentials');
        Schema::dropIfExists('member_sport_code');
        Schema::dropIfExists('members');
        Schema::dropIfExists('households');
        Schema::dropIfExists('membership_categories');
        Schema::dropIfExists('sport_codes');
    }
};
