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
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_bound_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('title');
            $table->unsignedInteger('start_page');
            $table->unsignedInteger('end_page');
            $table->unsignedInteger('printed_start_page')->nullable();
            $table->unsignedInteger('printed_end_page')->nullable();
            $table->string('file_path')->nullable();
            $table->longText('content')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->unique(['chapter_bound_id', 'position']);
        });

        Schema::table('chapter_bounds', function (Blueprint $table) {
            $table->string('lessons_status')->default('pending')->after('runtime_error');
            $table->text('lessons_error')->nullable()->after('lessons_status');
            $table->string('lessons_strategy')->nullable()->after('lessons_error');
        });

        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->json('header_patterns')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->dropColumn('header_patterns');
        });

        Schema::table('chapter_bounds', function (Blueprint $table) {
            $table->dropColumn(['lessons_status', 'lessons_error', 'lessons_strategy']);
        });

        Schema::dropIfExists('lessons');
    }
};
