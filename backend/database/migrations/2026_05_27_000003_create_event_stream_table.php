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
        // Define PostgreSQL ENUM type for telemetry events
        DB::statement("
            CREATE TYPE event_type_enum AS ENUM (
                'task_created', 'task_completed', 'task_updated',
                'project_created', 'project_updated',
                'login', 'logout'
            )
        ");

        Schema::create('event_stream', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('set null');
            $table->foreignId('task_id')->nullable()->constrained('tasks')->onDelete('set null');
            
            // Set type to event_type_enum
            $table->timestampTz('event_ts')->useCurrent();
            $table->double('event_value')->default(1.0);
            $table->jsonb('metadata')->nullable();

            // Index for chronological telemetry scans
            $table->index(['user_id', 'event_ts']);
        });

        // Set event_type column to the enum type
        DB::statement("ALTER TABLE event_stream ADD COLUMN event_type event_type_enum NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_stream');
        DB::statement("DROP TYPE IF EXISTS event_type_enum");
    }
};
