<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('championship_events', function (Blueprint $table) {
            $table->json('ranking_rules')->nullable()->after('draw_format');
        });
    }

    public function down(): void
    {
        Schema::table('championship_events', function (Blueprint $table) {
            $table->dropColumn('ranking_rules');
        });
    }
};
