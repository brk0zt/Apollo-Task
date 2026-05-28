<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\EventStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController
{
    /**
     * Display a listing of projects (paginated and optimized with composite indexes).
     */
    public function index(Request $request)
    {
        $projects = Project::where('user_id', $request->user()->id)
            ->orderBy('updated_at', 'desc')
            ->paginate(15);

        return ProjectResource::collection($projects);
    }

    /**
     * Store a newly created project and append telemetries.
     */
    public function store(ProjectRequest $request)
    {
        $validated = $request->validated();
        
        $project = new Project();
        $project->user_id = $request->user()->id;
        $project->name = $validated['name'];
        $project->description = $validated['description'] ?? null;
        $project->status = $validated['status'] ?? 'active';
        $project->metadata = $validated['metadata'] ?? null;
        $project->save();

        // L1 Event Stream Telemetry Append
        EventStream::create([
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
            'event_type' => 'project_created',
            'event_ts' => now(),
            'event_value' => 1.0,
            'metadata' => ['ip' => $request->ip()],
        ]);

        return response()->json([
            'message' => 'Project created successfully.',
            'project' => new ProjectResource($project)
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
            SELECT id, project_id, title, description, status, priority, estimated_hours, actual_hours, due_date, completed_at, created_at, updated_at,
                ROW_NUMBER() OVER (PARTITION BY project_id ORDER BY priority DESC, created_at) AS rank,
                LAG(completed_at) OVER (PARTITION BY project_id ORDER BY completed_at) AS prev_completed_at
            FROM tasks
            WHERE project_id = ?
        ", [$id]);

        // Hydrate raw database rows into Eloquent models so TaskResource can map them properly
        $hydratedTasks = Task::hydrate($tasks);

        return response()->json([
            'project' => new ProjectResource($project),
            'tasks' => TaskResource::collection($hydratedTasks)
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
        $project->update(\Illuminate\Support\Arr::except($validated, ['user_id']));

        // L1 Event Stream Telemetry Append
        EventStream::create([
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
            'event_type' => 'project_updated',
            'event_ts' => now(),
            'event_value' => 1.0,
            'metadata' => ['updated_fields' => array_keys($validated)],
        ]);

        return response()->json([
            'message' => 'Project updated successfully.',
            'project' => new ProjectResource($project)
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
