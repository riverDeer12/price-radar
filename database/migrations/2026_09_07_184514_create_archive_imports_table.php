<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_imports', function (Blueprint $table) {
            $table->id();
            $table->date('archive_date')->unique();
            $table->string('source_url')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status')->default('pending'); // pending, downloading, importing, completed, failed
            $table->string('triggered_by')->default('schedule'); // schedule, manual
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('stats')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_imports');
    }
};
