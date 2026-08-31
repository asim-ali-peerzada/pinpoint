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
            $table->index(['request_id', 'sql_fingerprint']);
            $table->text('sql');
            $table->unsignedInteger('time_ms');
            $table->string('caller_file')->nullable();
            $table->unsignedInteger('caller_line')->nullable();
            // char(32): md5() always returns a 32-char hex string.
            // Nullable: empty-bindings queries (e.g. "select 1") store null.
            // Detection treats null as 'unknown' — never falsely classifies.
            $table->char('bindings_hash', 32)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinpoint_queries');
    }
};
