<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scratch table truncated and refilled on every archive import. Bulk price
        // rows land here first so the diff-against-current-prices step (for
        // price_history) and the final upsert can run as set-based SQL instead of
        // looping in PHP over millions of rows.
        Schema::create('price_import_staging', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('store_id');
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('best_price_30', 10, 2)->nullable();
            $table->decimal('anchor_price', 10, 2)->nullable();
            $table->decimal('special_price', 10, 2)->nullable();

            $table->index(['product_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_import_staging');
    }
};
