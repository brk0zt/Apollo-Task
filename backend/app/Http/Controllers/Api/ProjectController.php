<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectRequest;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    /**
     * Display a listing of projects (paginated and optimized with composite indexes).
     */
    public function index(Request $request)
    {
        $projects = Project::where('user_id', $request->user()->id)
            ->orderBy('updated_at', 'desc')
            ->paginate(15);

        return response()->json($projects, 200);
    }

    /**
     * Store a newly created project and append telemetries.
     */
    public function store(ProjectRequest $request)
    {
        $validated = $request->validated();
        
        $project = Project::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'metadata' => $validated['metadata'] ?? null,
        ]);

        // L1 Event Stream Telemetry Append
        DB::table('event_stream')->insert([
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
            'event_type' => 'project_created',
            'event_ts' => now(),
            'event_value' => 1.0,
            'metadata' => json_encode(['ip' => $request->ip()]),
        ]);

        return response()->json([
            'message' => 'Project created successfully.',
            'project' => $project
        ], 201);
    }

    /**
     * Display the specified project with tasks ranked close-to-data using window functions.
     */
    public function show(Request $request, int $id)
    {
        $project = Project::where('user_id', $request->user()->id)->find($id);
        
        if (!$project) {
            return response()->json(['error' => 'Project not found.'], 404);
        }

        // Executing close-to-data ranking and delta shifts inside PostgreSQL
        // using window functions OVER (PARTITION BY ... ORDER BY ...) as described in the README!
        // This avoids costly user-space array copy/sorting loops in PHP.
        $tasks = DB::select("
            SELECT id, title, status, priority, estimated_hours, actual_hours, due_date, completed_at,
                ROW_NUMBER() OVER (PARTITION BY project_id ORDER BY priority DESC, created_at) AS rank,
                LAG(completed_at) OVER (PARTITION BY project_id ORDER BY completed_at) AS prev_completed_at
            FROM tasks
            WHERE project_id = ?
        ", [$id]);

        return response()->json([
            'project' => $project,
            'tasks' => $tasks
        ], 200);
    }

    /**
     * Update the specified project in storage and log the event.
     */
    public function update(ProjectRequest $request, int $id)
    {
        $project = Project::where('user_id', $request->user()->id)->find($id);

        if (!$project) {
            return response()->json(['error' => 'Project not found.'], 404);
        }

        $validated = $request->validated();
        $project->update($validated);

        // L1 Event Stream Telemetry Append
        DB::table('event_stream')->insert([
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
            'event_type' => 'project_updated',
            'event_ts' => now(),
            'event_value' => 1.0,
            'metadata' => json_encode(['updated_fields' => array_keys($validated)]),
        ]);

        return response()->json([
            'message' => 'Project updated successfully.',
            'project' => $project
        ], 200);
    }

    /**
     * Remove the specified project from storage.
     */
    public function destroy(Request $request, int $id)
    {
        $project = Project::where('user_id', $request->user()->id)->find($id);

        if (!$project) {
            return response()->json(['error' => 'Project not found.'], 404);
        }

        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully.'
        ], 200);
    }
}
