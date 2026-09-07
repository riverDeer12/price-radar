<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('best_price_30', 10, 2)->nullable();
            $table->decimal('anchor_price', 10, 2)->nullable();
            $table->decimal('special_price', 10, 2)->nullable();
            $table->date('archive_date');
            $table->timestamps();

            $table->unique(['product_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
