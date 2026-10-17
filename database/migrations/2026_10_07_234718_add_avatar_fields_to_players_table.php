<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->boolean('has_setup_character')->default(false)->after('balance');
            $table->string('avatar_body')->nullable()->after('has_setup_character');
            $table->string('avatar_hairstyle')->nullable()->after('avatar_body');
            $table->string('avatar_outfit')->nullable()->after('avatar_hairstyle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['has_setup_character', 'avatar_body', 'avatar_hairstyle', 'avatar_outfit']);
        });
    }
};
