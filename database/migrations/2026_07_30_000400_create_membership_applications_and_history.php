<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sport_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_period_id')->constrained()->restrictOnDelete();
            $table->string('application_type', 30);
            $table->string('status', 30)->default('draft')->index();
            $table->text('applicant_notes')->nullable();
            $table->text('administrator_notes')->nullable();
            $table->timestamp('information_requested_at')->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedInteger('sport_code_fee_cents')->nullable();
            $table->unsignedInteger('hsc_levy_cents')->nullable();
            $table->unsignedInteger('joining_fee_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_payable_cents')->nullable();
            $table->string('billing_frequency', 30)->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->text('override_reason')->nullable();
            $table->foreignId('override_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('guardian_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_relationship', 80)->nullable();
            $table->string('guardian_email')->nullable();
            $table->string('guardian_mobile', 30)->nullable();
            $table->boolean('guardian_consent')->default(false);
            $table->boolean('independent_access_permitted')->default(false);
            $table->timestamps();
            $table->index(['sport_code_id', 'membership_period_id', 'status'], 'applications_scope_index');
            $table->index(['member_id', 'membership_period_id'], 'applications_member_period_index');
        });

        Schema::create('sport_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sport_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_application_id')->nullable()->unique()->constrained('membership_applications')->nullOnDelete();
            $table->string('status', 30)->default('active')->index();
            $table->string('role', 30)->default('member');
            $table->date('joined_or_renewed_on');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('sport_code_fee_cents');
            $table->unsignedInteger('hsc_levy_cents');
            $table->unsignedInteger('total_cents');
            $table->string('billing_frequency', 30);
            $table->timestamps();
            $table->unique(['member_id', 'sport_code_id', 'membership_period_id'], 'sport_membership_period_unique');
            $table->index(['membership_period_id', 'sport_code_id', 'status'], 'sport_membership_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sport_memberships');
        Schema::dropIfExists('membership_applications');
    }
};
