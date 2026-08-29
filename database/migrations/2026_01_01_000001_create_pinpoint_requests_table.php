<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinpoint_requests', function (Blueprint $table) {
            $table->id();
            $table->string('route_name')->nullable();
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedInteger('duration_ms');
            $table->unsignedSmallInteger('query_count');
            $table->unsignedInteger('query_time_ms');
            $table->boolean('has_n_plus_one')->default(false);
            $table->timestamp('created_at');
            $table->index(['route_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinpoint_requests');
    }
};