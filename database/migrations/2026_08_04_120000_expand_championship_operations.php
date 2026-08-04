<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('club_championships', function (Blueprint $table) {
            $table->string('timezone')->default('Africa/Johannesburg');
            $table->json('operations_settings')->nullable();
        });
        Schema::table('championship_matches', function (Blueprint $table) {
            $table->timestamp('not_before_at')->nullable();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('entry1_checked_in_at')->nullable();
            $table->timestamp('entry2_checked_in_at')->nullable();
            $table->foreignId('loser_entry_id')->nullable()->constrained('championship_entries')->nullOnDelete();
            $table->json('score_data')->nullable();
            $table->string('result_status')->default('unverified');
            $table->foreignId('result_verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('result_verified_at')->nullable();
            $table->text('desk_notes')->nullable();
        });
        Schema::create('championship_playing_sessions', function (Blueprint $table) {
            $table->id(); $table->foreignId('club_championship_id')->constrained()->cascadeOnDelete();
            $table->string('name'); $table->date('play_date'); $table->time('starts_at'); $table->time('ends_at');
            $table->json('facility_ids'); $table->unsignedInteger('slot_minutes')->default(30); $table->timestamps();
        });
        Schema::create('championship_member_availability', function (Blueprint $table) {
            $table->id(); $table->foreignId('club_championship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete(); $table->timestamp('unavailable_from');
            $table->timestamp('unavailable_until'); $table->string('reason')->nullable(); $table->timestamps();
        });
        Schema::create('championship_court_blocks', function (Blueprint $table) {
            $table->id(); $table->foreignId('club_championship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete(); $table->timestamp('starts_at');
            $table->timestamp('ends_at'); $table->string('reason'); $table->timestamps();
        });
        Schema::create('championship_schedule_publications', function (Blueprint $table) {
            $table->id(); $table->foreignId('club_championship_id')->constrained()->cascadeOnDelete();
            $table->date('play_date'); $table->unsignedInteger('version'); $table->json('snapshot');
            $table->text('change_summary')->nullable(); $table->boolean('is_current')->default(true);
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at'); $table->timestamps(); $table->unique(['club_championship_id','play_date','version'],'champ_schedule_version_unique');
        });
        Schema::create('championship_announcements', function (Blueprint $table) {
            $table->id(); $table->foreignId('club_championship_id')->constrained()->cascadeOnDelete();
            $table->string('title'); $table->text('message'); $table->string('audience')->default('all');
            $table->string('priority')->default('normal'); $table->json('channels')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable(); $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['championship_announcements','championship_schedule_publications','championship_court_blocks','championship_member_availability','championship_playing_sessions'] as $table) Schema::dropIfExists($table);
        Schema::table('championship_matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('result_verified_by_user_id'); $table->dropConstrainedForeignId('loser_entry_id');
            $table->dropColumn(['not_before_at','called_at','started_at','completed_at','entry1_checked_in_at','entry2_checked_in_at','score_data','result_status','result_verified_at','desk_notes']);
        });
        Schema::table('club_championships', fn (Blueprint $table) => $table->dropColumn(['timezone','operations_settings']));
    }
};
