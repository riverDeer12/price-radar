<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('special_price', 10, 2)->nullable();
            $table->date('recorded_date');
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'store_id', 'recorded_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_history');
    }
};
