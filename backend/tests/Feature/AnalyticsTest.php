<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Analytics Tester',
            'email' => 'tester@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->project = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Solar Panel Grid Migration',
            'description' => 'Migrate physical PV grid nodes.',
            'status' => 'active',
        ]);
    }

    /**
     * Test Jacobian Linear Risk Scorer returns complete sensitivity breakdowns.
     */
    public function test_jacobian_risk_score_is_calculated_successfully(): void
    {
        Sanctum::actingAs($this->user);

        // Add incomplete and completed tasks to generate non-zero metrics
        Task::create([
            'project_id' => $this->project->id,
            'title' => 'Install PV Inverters',
            'status' => 'in_progress',
            'priority' => 5, // High priority incomplete -> raises priority_density
        ]);

        Task::create([
            'project_id' => $this->project->id,
            'title' => 'Route cabling',
            'status' => 'completed',
            'estimated_hours' => 12.0,
            'actual_hours' => 10.0,
            'completed_at' => now()->subDay(),
        ]);

        $response = $this->getJson("/api/analytics/risk/{$this->project->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'risk_score',
                'risk_level',
                'linearization_model',
                'breakdown' => [
                    'overdue_ratio' => ['metric_value', 'jacobian_sensitivity', 'risk_contribution', 'percentage_impact'],
                    'velocity_deficit' => ['metric_value', 'jacobian_sensitivity', 'risk_contribution', 'percentage_impact'],
                    'priority_density' => ['metric_value', 'jacobian_sensitivity', 'risk_contribution', 'percentage_impact'],
                    'inactivity_decay' => ['metric_value', 'jacobian_sensitivity', 'risk_contribution', 'percentage_impact'],
                    'backlog_weight' => ['metric_value', 'jacobian_sensitivity', 'risk_contribution', 'percentage_impact'],
                ]
            ]);
    }

    /**
     * Test Newton-Raphson forecasting yields estimates and converges.
     */
    public function test_newton_raphson_forecasting_converges_with_velocity_profile(): void
    {
        Sanctum::actingAs($this->user);

        // Create chronological completed tasks to generate velocity drifts
        Task::create([
            'project_id' => $this->project->id,
            'title' => 'Task A',
            'status' => 'completed',
            'estimated_hours' => 10.0,
            'actual_hours' => 8.0,
            'completed_at' => now()->subDays(5),
        ]);

        Task::create([
            'project_id' => $this->project->id,
            'title' => 'Task B',
            'status' => 'completed',
            'estimated_hours' => 10.0,
            'actual_hours' => 10.0,
            'completed_at' => now()->subDays(3),
        ]);

        Task::create([
            'project_id' => $this->project->id,
            'title' => 'Task C',
            'status' => 'completed',
            'estimated_hours' => 10.0,
            'actual_hours' => 12.0,
            'completed_at' => now()->subDay(),
        ]);

        // Incomplete remaining task workload
        Task::create([
            'project_id' => $this->project->id,
            'title' => 'Task D',
            'status' => 'pending',
            'estimated_hours' => 40.0,
        ]);

        $response = $this->getJson("/api/analytics/forecast/{$this->project->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'base_velocity',
                'velocity_drift_coefficient',
                'total_estimated_remaining_hours',
                'forecasted_remaining_days',
                'estimated_completion_date',
                'newton_raphson_iterations',
                'newton_raphson_converged',
                'method',
                'confidence'
            ]);
    }
}
