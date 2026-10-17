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
        Schema::create('parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->integer('grid_x');
            $table->integer('grid_y');
            $table->foreignId('owner_id')->nullable()->constrained('players')->nullOnDelete();
            $table->decimal('price', 15, 2)->default(0.00);
            $table->string('status')->default('available');
            $table->string('deed_number')->nullable();
            $table->foreignId('signed_by_director_id')->nullable()->constrained('players')->nullOnDelete();
            $table->timestamp('c_of_o_signed_at')->nullable();
            $table->string('facility_name')->nullable();
            $table->string('facility_type')->nullable();
            $table->decimal('visitor_fee', 15, 2)->default(0.00);
            $table->boolean('for_sale')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parcels');
    }
};
