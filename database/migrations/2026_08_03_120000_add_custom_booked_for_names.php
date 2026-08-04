<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_holds', fn (Blueprint $table) => $table->string('booked_for_name', 120)->nullable()->after('member_id'));
        Schema::table('bookings', fn (Blueprint $table) => $table->string('booked_for_name', 120)->nullable()->after('member_id'));
        Schema::table('booking_series', fn (Blueprint $table) => $table->string('booked_for_name', 120)->nullable()->after('member_id'));
    }

    public function down(): void
    {
        Schema::table('booking_holds', fn (Blueprint $table) => $table->dropColumn('booked_for_name'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('booked_for_name'));
        Schema::table('booking_series', fn (Blueprint $table) => $table->dropColumn('booked_for_name'));
    }
};
