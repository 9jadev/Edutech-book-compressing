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
        Schema::create('government_offices', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('current_holder_id')->nullable()->constrained('players')->nullOnDelete();
            $table->decimal('signature_fee', 15, 2)->default(0.00);
            $table->timestamp('tenure_expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('government_offices');
    }
};
