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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->date('estimated_completion')->nullable();
            $table->double('risk_score')->default(0.0);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            // Index for range queries
            $table->index(['user_id', 'updated_at']);
        });

        // Add check constraint to status
        DB::statement("ALTER TABLE projects ADD CONSTRAINT chk_project_status CHECK (status IN ('active','paused','completed','archived'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
