<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role', 40)->default('sport_viewer')->after('password')->index();
            $table->boolean('active')->default(true)->after('role');
            $table->timestamp('last_login_at')->nullable()->after('active');
            $table->string('api_token_hash', 64)->nullable()->unique()->after('remember_token');
        });

        Schema::create('sport_code_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sport_code_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30)->default('viewer');
            $table->timestamps();
            $table->unique(['user_id', 'sport_code_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('sport_code_user');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
            $table->dropColumn(['role', 'active', 'last_login_at', 'api_token_hash']);
        });
    }
};
