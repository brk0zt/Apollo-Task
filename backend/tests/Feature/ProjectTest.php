<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    protected User $user1;
    protected User $user2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user1 = User::create([
            'name' => 'Burak User 1',
            'email' => 'burak1@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->user2 = User::create([
            'name' => 'Burak User 2',
            'email' => 'burak2@example.com',
            'password' => bcrypt('password123'),
        ]);
    }

    public function test_user_can_create_project_with_explicit_user_id(): void
    {
        Sanctum::actingAs($this->user1);

        $response = $this->postJson('/api/projects', [
            'name' => 'Project Alpha',
            'description' => 'Test project description',
            'status' => 'active',
            'user_id' => $this->user2->id, // Attempt to bypass ownership
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('project.name', 'Project Alpha');

        // Verify that the created project belongs to user1, NOT user2 (defended against ownership bypass!)
        $this->assertDatabaseHas('projects', [
            'name' => 'Project Alpha',
            'user_id' => $this->user1->id,
        ]);
        $this->assertDatabaseMissing('projects', [
            'name' => 'Project Alpha',
            'user_id' => $this->user2->id,
        ]);
    }

    public function test_user_cannot_view_another_users_projects(): void
    {
        // User 2 owns this project
        $project = Project::create([
            'user_id' => $this->user2->id,
            'name' => 'Secret Project',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user1);

        // Try to show
        $responseShow = $this->getJson("/api/projects/{$project->id}");
        $responseShow->assertStatus(404);

        // Try to list
        $responseList = $this->getJson('/api/projects');
        $responseList->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_user_cannot_update_another_users_project_or_transfer_ownership(): void
    {
        $project = Project::create([
            'user_id' => $this->user1->id,
            'name' => 'Project User 1',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user1);

        $response = $this->putJson("/api/projects/{$project->id}", [
            'name' => 'Updated Project Name',
            'user_id' => $this->user2->id, // Attempt to transfer ownership via update
        ]);

        $response->assertStatus(200);

        // Verify project updated its name, but user_id is STILL user1 (unaffected by ownership transfer!)
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Updated Project Name',
            'user_id' => $this->user1->id,
        ]);
    }
}
