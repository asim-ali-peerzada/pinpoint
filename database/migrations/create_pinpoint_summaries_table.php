<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinpoint_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('route_name')->unique();
            $table->unsignedInteger('p50_ms');
            $table->unsignedInteger('p95_ms');
            $table->unsignedInteger('p99_ms');
            $table->unsignedInteger('avg_ms');
            $table->unsignedInteger('sample_count');
            $table->string('tier', 30);
            $table->timestamp('last_computed_at');
        });

        // Baseline snapshots for regression diffing (pinpoint:snapshot / pinpoint:diff).
        // Unique tag: the --no-overwrite race (two CI jobs writing the same tag)
        // must fail on a constraint, not silently produce two rows.
        Schema::create('pinpoint_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('tag', 100)->unique();
            $table->json('snapshot'); // serialised array of per-route metrics
            $table->unsignedInteger('route_count');
            $table->timestamp('created_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinpoint_baselines');
        Schema::dropIfExists('pinpoint_summaries');
    }
};
