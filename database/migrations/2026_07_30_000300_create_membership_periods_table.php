<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('applications_open_on')->nullable();
            $table->date('applications_close_on')->nullable();
            $table->date('renewal_deadline')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_periods');
    }
};
