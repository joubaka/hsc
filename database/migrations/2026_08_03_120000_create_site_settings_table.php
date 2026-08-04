<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('club_name')->default('Hermanus Sports Club');
            $table->string('short_name')->default('HSC');
            $table->string('support_email')->nullable();
            $table->string('support_phone', 40)->nullable();
            $table->string('website_url')->nullable();
            $table->text('address')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('primary_colour', 7)->default('#575271');
            $table->string('accent_colour', 7)->default('#2f7d66');
            $table->string('terms_url')->nullable();
            $table->string('privacy_url')->nullable();
            $table->string('facility_rules_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('site_settings'); }
};
