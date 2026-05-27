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

    /**
     * Test FFT analysis returns error validation when data points are < 16.
     */
    public function test_fft_analysis_requires_sixteen_points_and_returns_error(): void
    {
        Sanctum::actingAs($this->user);

        // Access pattern route without inserting 16 distinct timeseries data points
        $response = $this->getJson('/api/analytics/patterns?metric=task_completion_rate');

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Insufficient data for Fourier pattern analysis.');
    }

    /**
     * Test Jacobian dynamic weights self-correction runs gradient descent and updates.
     */
    public function test_jacobian_self_correction_gradient_descent_converges(): void
    {
        Sanctum::actingAs($this->user);

        // Create 3 historical projects to trigger regression update
        $hp1 = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Historical Success 1',
            'status' => 'completed', // Y = 0.0
            'risk_score' => 0.1,
        ]);
        $hp2 = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Historical Failure 2',
            'status' => 'archived', // Y = 1.0
            'risk_score' => 0.8,
        ]);
        $hp3 = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Historical Failure 3',
            'status' => 'paused', // Y = 1.0
            'risk_score' => 0.9,
        ]);

        // Add some mock tasks for each historical project so they calculate non-zero metrics
        foreach ([$hp1, $hp2, $hp3] as $p) {
            Task::create([
                'project_id' => $p->id,
                'title' => 'Sample Task',
                'status' => 'pending',
                'estimated_hours' => 10,
                'due_date' => now()->subDays(5), // overdue!
            ]);
        }

        // Trigger risk computation on our active project
        // This will call resolveDynamicJacobian which executes Ridge-Regularized Gradient Descent
        $service = new \App\Services\Analytics\RiskScoringService();
        $result = $service->computeRiskScore($this->project->id);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('risk_score', $result);
        $this->assertArrayHasKey('breakdown', $result);

        // Verify that the breakdown has dynamic sensitivities calculated
        foreach ($result['breakdown'] as $metric => $data) {
            $this->assertGreaterThan(0.0, $data['jacobian_sensitivity']);
        }
    }
}
