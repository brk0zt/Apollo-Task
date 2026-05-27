<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;
    protected Project $project;
    protected Project $otherProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Burak Task Owner',
            'email' => 'taskowner@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->otherUser = User::create([
            'name' => 'Other Task Owner',
            'email' => 'otherowner@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->project = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Main Asset Project',
            'status' => 'active',
        ]);

        $this->otherProject = Project::create([
            'user_id' => $this->otherUser->id,
            'name' => 'Foreign Asset Project',
            'status' => 'active',
        ]);
    }

    public function test_user_can_create_and_list_tasks_under_owned_project(): void
    {
        Sanctum::actingAs($this->user);

        // Create
        $responseCreate = $this->postJson("/api/projects/{$this->project->id}/tasks", [
            'title' => 'Calibrate Solar Inverter',
            'priority' => 4,
            'estimated_hours' => 5.5,
        ]);

        $responseCreate->assertStatus(201)
            ->assertJsonPath('task.title', 'Calibrate Solar Inverter');

        $this->assertDatabaseHas('tasks', [
            'project_id' => $this->project->id,
            'title' => 'Calibrate Solar Inverter',
            'priority' => 4,
        ]);

        // List
        $responseList = $this->getJson("/api/projects/{$this->project->id}/tasks");
        $responseList->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_user_cannot_access_tasks_of_other_users_projects(): void
    {
        Sanctum::actingAs($this->user);

        // Try to create under another user's project
        $responseCreate = $this->postJson("/api/projects/{$this->otherProject->id}/tasks", [
            'title' => 'Unauthorized Task',
            'priority' => 2,
        ]);
        $responseCreate->assertStatus(404);

        // Try to list another user's project's tasks
        $responseList = $this->getJson("/api/projects/{$this->otherProject->id}/tasks");
        $responseList->assertStatus(404);
    }

    public function test_marking_task_completed_logs_telemetry_with_volume_value(): void
    {
        Sanctum::actingAs($this->user);

        $task = Task::create([
            'project_id' => $this->project->id,
            'title' => 'Cabling Maintenance',
            'status' => 'pending',
            'estimated_hours' => 10.0,
        ]);

        // Mark completed with actual hours spent
        $response = $this->patchJson("/api/tasks/{$task->id}/complete", [
            'actual_hours' => 8.5,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('task.status', 'completed')
            ->assertJsonPath('task.actual_hours', 8.5);

        // Assert Task is updated in DB
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'completed',
            'actual_hours' => 8.5,
        ]);

        // Assert L1 telemetry log is appended with event_value = actual_hours
        $this->assertDatabaseHas('event_stream', [
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'event_type' => 'task_completed',
            'event_value' => 8.5, // Captures actual work volume for EWMA/Newton-Raphson
        ]);
    }
}
