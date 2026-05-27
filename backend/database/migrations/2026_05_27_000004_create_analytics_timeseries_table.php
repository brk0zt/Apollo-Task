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
        Schema::create('analytics_timeseries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('metric_name', 64);
            $table->timestampTz('bucket_ts');
            $table->string('bucket_size', 16)->default('1 hour');
            $table->double('value')->default(0.0);
            $table->timestampsTz();

            // Composite unique constraint to guarantee perfect idempotency
            $table->unique(['user_id', 'metric_name', 'bucket_ts', 'bucket_size'], 'idx_timeseries_unique');
            // Optimization index for range scans
            $table->index(['user_id', 'metric_name', 'bucket_ts'], 'idx_timeseries_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_timeseries');
    }
};
