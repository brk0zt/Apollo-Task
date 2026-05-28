<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\FFTAnalysisService;
use App\Services\Analytics\ProjectForecastService;
use App\Services\Analytics\RiskScoringService;
use App\Models\AnalyticsTimeseries;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /**
     * Get Newton-Raphson velocity convergence project completion forecast.
     */
    public function forecast(int $projectId, ProjectForecastService $service)
    {
        return response()->json($service->forecastCompletion($projectId), 200);
    }

    /**
     * Get Jacobian linearized multi-metric risk scoring breakdown.
     */
    public function risk(int $projectId, RiskScoringService $service)
    {
        return response()->json($service->computeRiskScore($projectId), 200);
    }

    /**
     * Get Cooley-Tukey FFT dominant activity pattern insights for the current user.
     */
    public function patterns(Request $request, FFTAnalysisService $service)
    {
        $userId = $request->user()->id;
        $metric = $request->query('metric', 'task_completion_rate');

        return response()->json($service->analyzePattern($userId, $metric), 200);
    }

    /**
     * Get raw bucketed time-series values of L2 analytics_timeseries for dashboard rendering.
     */
    public function timeseries(Request $request)
    {
        $userId = $request->user()->id;
        $metric = $request->query('metric', 'task_completion_rate');

        $timeseries = AnalyticsTimeseries::where('user_id', $userId)
            ->where('metric_name', $metric)
            ->orderBy('bucket_ts', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'metric_name' => $metric,
            'count' => $timeseries->count(),
            'data' => $timeseries->map(function ($row) {
                return [
                    'timestamp' => $row->bucket_ts->toIso8601String(),
                    'value' => $row->value,
                    'bucket_size' => $row->bucket_size
                ];
            })
        ], 200);
    }
}