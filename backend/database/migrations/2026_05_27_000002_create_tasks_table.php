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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('pending');
            $table->smallInteger('priority')->default(2);
            $table->double('estimated_hours')->nullable();
            $table->double('actual_hours')->nullable();
            $table->date('due_date')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            // Compound index for hot query path
            $table->index(['project_id', 'status']);
        });

        // Add check constraints to task priority and status
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT chk_task_status CHECK (status IN ('pending','in_progress','completed','cancelled'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT chk_task_priority CHECK (priority BETWEEN 1 AND 5)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
