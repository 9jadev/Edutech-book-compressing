<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chapter_bounds', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('file_path');
            $table->text('runtime_error')->nullable()->after('status');
        });

        // Chapters that were already split before this column existed are done.
        DB::table('chapter_bounds')->whereNotNull('file_path')->update(['status' => 'done']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapter_bounds', function (Blueprint $table) {
            $table->dropColumn(['status', 'runtime_error']);
        });
    }
};
