<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([10, 12, 14, 16, 18] as $age) {
            DB::table('championship_age_categories')
                ->where('name', "Under {$age}")
                ->update(['maximum_age' => $age, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach ([10, 12, 14, 16, 18] as $age) {
            DB::table('championship_age_categories')
                ->where('name', "Under {$age}")
                ->update(['maximum_age' => $age - 1, 'updated_at' => now()]);
        }
    }
};
