<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chain_id')->constrained()->cascadeOnDelete();
            $table->string('store_code');
            $table->string('type')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('zipcode')->nullable();
            $table->timestamps();

            $table->unique(['chain_id', 'store_code']);
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
