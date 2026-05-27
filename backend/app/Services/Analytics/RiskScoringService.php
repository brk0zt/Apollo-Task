<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Models\Task;
use App\Models\EventStream;
use Carbon\Carbon;

class RiskScoringService
{
    /**
     * Baseline Jacobian sensitivities representing prior weights:
     * J = [ dRisk/dm1, dRisk/dm2, dRisk/dm3, dRisk/dm4, dRisk/dm5 ]
     */
    protected array $baselineJacobian = [
        'overdue_ratio'    => 0.35, // High correlation with project blockages
        'velocity_deficit' => 0.25, // Velocity drops indicate sprint delay
        'priority_density' => 0.15, // Outstanding critical priorities raise risk
        'inactivity_decay' => 0.15, // System abandonment risk
        'backlog_weight'   => 0.10  // General backlog weight
    ];

    /**
     * Compute the data-driven Jacobian risk score, dynamically adjusting weights
     * from historical project outcomes (self-correcting from history).
     *
     * @param int $projectId
     * @return array
     */
    public function computeRiskScore(int $projectId): array
    {
        $project = Project::find($projectId);
        if (!$project) {
            return ['success' => false, 'message' => 'Project not found.'];
        }

        $tasks = Task::where('project_id', $projectId)->get();
        $totalTasks = $tasks->count();

        if ($totalTasks === 0) {
            return [
                'success' => true,
                'risk_score' => 0.0,
                'breakdown' => [],
                'message' => 'No tasks present; risk score is zero.'
            ];
        }

        $incompleteTasks = $tasks->where('status', '!=', 'completed');
        $completedTasks = $tasks->where('status', 'completed');

        // Metric 1: Overdue Task Ratio (m1)
        $overdueCount = 0;
        foreach ($incompleteTasks as $task) {
            if ($task->due_date && Carbon::parse($task->due_date)->isPast()) {
                $overdueCount++;
            }
        }
        $m1 = $incompleteTasks->count() > 0 ? (float) ($overdueCount / $incompleteTasks->count()) : 0.0;

        // Metric 2: Velocity Deficit (m2)
        $m2 = $this->calculateVelocityDeficit($completedTasks);

        // Metric 3: Priority Density (m3)
        $prioritySum = 0.0;
        foreach ($incompleteTasks as $task) {
            $prioritySum += ($task->priority * 0.2); // Priority 5 -> 1.0, Priority 1 -> 0.2
        }
        $m3 = $incompleteTasks->count() > 0 ? (float) ($prioritySum / $incompleteTasks->count()) : 0.0;

        // Metric 4: Inactivity Decay (m4)
        $m4 = $this->calculateInactivityDecay($projectId);

        // Metric 5: Backlog Weight (m5)
        $m5 = (float) ($incompleteTasks->count() / $totalTasks);

        $metrics = [
            'overdue_ratio'    => $m1,
            'velocity_deficit' => $m2,
            'priority_density' => $m3,
            'inactivity_decay' => $m4,
            'backlog_weight'   => $m5
        ];

        // DYNAMIC JACOBIAN WEIGHT RESOLUTION (Self-corrects from historical projects!)
        $activeJacobian = $this->resolveDynamicJacobian($metrics);

        $riskScore = 0.0;
        $contributions = [];

        foreach ($metrics as $key => $value) {
            $partialDerivative = $activeJacobian[$key];
            $contribution = $partialDerivative * $value;
            $riskScore += $contribution;

            $contributions[$key] = [
                'metric_value' => round($value, 4),
                'jacobian_sensitivity' => round($partialDerivative, 4),
                'risk_contribution' => round($contribution, 4),
                'percentage_impact' => 0.0
            ];
        }

        $riskScore = max(0.0, min(1.0, $riskScore));

        if ($riskScore > 0) {
            foreach ($contributions as $key => &$data) {
                $data['percentage_impact'] = round(($data['risk_contribution'] / $riskScore) * 100, 2);
            }
        }

        $project->update(['risk_score' => $riskScore]);

        return [
            'success' => true,
            'project_id' => $projectId,
            'risk_score' => round($riskScore, 4),
            'risk_level' => $this->getRiskLevelString($riskScore),
            'linearization_model' => 'Jacobian first-order Taylor expansion (data-driven)',
            'breakdown' => $contributions
        ];
    }

    /**
     * Compute historical metrics for any project (completed, archived, or paused)
     * based on its original task state and events.
     */
    public function computeMetricsForProject(Project $project): array
    {
        $tasks = Task::where('project_id', $project->id)->get();
        $totalTasks = $tasks->count();

        if ($totalTasks === 0) {
            return [
                'overdue_ratio'    => 0.0,
                'velocity_deficit' => 0.0,
                'priority_density' => 0.0,
                'inactivity_decay' => 0.0,
                'backlog_weight'   => 0.0
            ];
        }

        $incompleteTasks = $tasks->where('status', '!=', 'completed');
        $completedTasks = $tasks->where('status', 'completed');

        // Metric 1: Overdue Task Ratio
        $overdueCount = 0;
        foreach ($incompleteTasks as $task) {
            if ($task->due_date && Carbon::parse($task->due_date)->isPast()) {
                $overdueCount++;
            }
        }
        $m1 = $incompleteTasks->count() > 0 ? (float) ($overdueCount / $incompleteTasks->count()) : 0.0;

        // Metric 2: Velocity Deficit
        $m2 = $this->calculateVelocityDeficit($completedTasks);

        // Metric 3: Priority Density
        $prioritySum = 0.0;
        foreach ($incompleteTasks as $task) {
            $prioritySum += ($task->priority * 0.2);
        }
        $m3 = $incompleteTasks->count() > 0 ? (float) ($prioritySum / $incompleteTasks->count()) : 0.0;

        // Metric 4: Inactivity Decay
        $m4 = $this->calculateInactivityDecay($project->id);

        // Metric 5: Backlog Weight
        $m5 = (float) ($incompleteTasks->count() / $totalTasks);

        return [
            'overdue_ratio'    => $m1,
            'velocity_deficit' => $m2,
            'priority_density' => $m3,
            'inactivity_decay' => $m4,
            'backlog_weight'   => $m5
        ];
    }

    /**
     * Dynamically resolve Jacobian sensitivities using historical project regressions.
     * Computes correlation of each metric to project failures (paused/archived states)
     * to self-correct weights dynamically as project history grows.
     */
    protected function resolveDynamicJacobian(array $currentMetrics): array
    {
        // Fetch completed, archived, and paused projects representing history
        $history = Project::whereIn('status', ['completed', 'archived', 'paused'])->get();

        if ($history->count() < 3) {
            // Fallback to baseline prior weights if history is sparse
            return $this->baselineJacobian;
        }

        $failedStates = ['archived', 'paused']; // Proxy for failed/abandoned projects
        $projectData = [];

        foreach ($history as $proj) {
            $projectData[] = [
                'metrics' => $this->computeMetricsForProject($proj),
                'outcome' => in_array($proj->status, $failedStates) ? 1.0 : 0.0
            ];
        }

        $N = count($projectData);
        $w = $this->baselineJacobian; // Start weights at baseline priors
        $learningRate = 0.05;
        $lambda = 0.15; // Regularization coefficient to maintain stability under low N
        $iterations = 100;

        // Execute batch gradient descent to fit multi-metric sensitivities
        for ($iter = 0; $iter < $iterations; $iter++) {
            $gradients = [
                'overdue_ratio'    => 0.0,
                'velocity_deficit' => 0.0,
                'priority_density' => 0.0,
                'inactivity_decay' => 0.0,
                'backlog_weight'   => 0.0
            ];

            foreach ($projectData as $data) {
                $metrics = $data['metrics'];
                $Y = $data['outcome'];

                // Compute prediction: w . m
                $prediction = 0.0;
                foreach ($w as $k => $val) {
                    $prediction += $val * $metrics[$k];
                }

                $error = $prediction - $Y;

                // Accumulate gradients
                foreach ($w as $k => $val) {
                    $gradients[$k] += $error * $metrics[$k];
                }
            }

            // Average gradients and apply L2 prior regularization
            foreach ($w as $k => $val) {
                $gradients[$k] = ($gradients[$k] / $N) + $lambda * ($val - $this->baselineJacobian[$k]);
                // Update weights
                $w[$k] -= $learningRate * $gradients[$k];
                // Keep weight strictly positive to represent positive risk sensitivity
                $w[$k] = max(0.01, $w[$k]);
            }
        }

        // Normalize weights so they strictly sum to 1.0 (Taylor boundary condition)
        $sum = array_sum($w);
        if ($sum > 0) {
            foreach ($w as $k => $val) {
                $w[$k] = $val / $sum;
            }
        } else {
            $w = $this->baselineJacobian;
        }

        return $w;
    }

    /**
     * Compute velocity deficit compared to target baseline.
     */
    protected function calculateVelocityDeficit($completedTasks): float
    {
        if ($completedTasks->isEmpty()) {
            return 0.5;
        }

        $totalEst = $completedTasks->sum('estimated_hours');
        $totalAct = $completedTasks->sum('actual_hours');

        if ($totalAct <= 0) {
            return 0.0;
        }

        $currentVelocity = $totalEst / $totalAct;
        $targetVelocity = 1.0;

        if ($currentVelocity >= $targetVelocity) {
            return 0.0;
        }

        return (float) min(1.0, ($targetVelocity - $currentVelocity) / $targetVelocity);
    }

    /**
     * Compute inactivity decay.
     */
    protected function calculateInactivityDecay(int $projectId): float
    {
        $lastEvent = EventStream::where('project_id', $projectId)
            ->orderBy('event_ts', 'desc')
            ->first();

        if (!$lastEvent) {
            return 0.8;
        }

        $daysSince = Carbon::parse($lastEvent->event_ts)->diffInDays(Carbon::now());
        return (float) min(1.0, $daysSince / 14.0);
    }

    /**
     * Get descriptive risk level.
     */
    protected function getRiskLevelString(float $score): string
    {
        if ($score >= 0.70) {
            return 'CRITICAL (CRITICAL RISK OF SPRINT FAILURE)';
        }
        if ($score >= 0.40) {
            return 'MEDIUM (ELEVATED RISK - MONITOR SYSTEM)';
        }
        return 'LOW (ON TRACK - NOMINAL STATE)';
    }
}
