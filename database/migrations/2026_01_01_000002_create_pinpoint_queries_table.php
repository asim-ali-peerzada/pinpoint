<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinpoint_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('pinpoint_requests')->cascadeOnDelete();
            $table->string('sql_fingerprint', 64);
            $table->text('sql');
            $table->unsignedInteger('time_ms');
            $table->string('caller_file')->nullable();
            $table->unsignedInteger('caller_line')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinpoint_queries');
    }
};