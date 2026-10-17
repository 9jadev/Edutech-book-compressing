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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('receiver_id')->nullable()->constrained('players')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('tax_deducted', 15, 2)->default(0.00);
            $table->string('type');
            $table->string('description')->nullable();
            $table->decimal('sender_balance_after', 15, 2)->nullable();
            $table->decimal('receiver_balance_after', 15, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
