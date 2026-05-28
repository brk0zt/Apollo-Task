<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\EventStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController 
{
    /**
     * Display a listing of tasks for a project.
     */
    public function index(Request $request, int $projectId)
    {
        $project = Project::where('user_id', $request->user()->id)->find($projectId);

        if (!$project) {
            return response()->json(['error' => 'Project not found.'], 404);
        }

        $query = Task::where('project_id', $projectId);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $tasks = $query->orderBy('priority', 'desc')->get();

        return TaskResource::collection($tasks);
    }

    /**
     * Store a newly created task and append L1 telemetry logs.
     */
    public function store(TaskRequest $request, int $projectId)
    {
        $project = Project::where('user_id', $request->user()->id)->find($projectId);

        if (!$project) {
            return response()->json(['error' => 'Project not found.'], 404);
        }

        $validated = $request->validated();
        
        $task = Task::create([
            'project_id' => $projectId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'pending',
            'priority' => $validated['priority'] ?? 2,
            'estimated_hours' => $validated['estimated_hours'] ?? null,
            'actual_hours' => $validated['actual_hours'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        // L1 Event Stream Telemetry Append
        EventStream::create([
            'user_id' => $request->user()->id,
            'project_id' => $projectId,
            'task_id' => $task->id,
            'event_type' => 'task_created',
            'event_ts' => now(),
            'event_value' => 1.0,
            'metadata' => ['priority' => $task->priority],
        ]);

        return response()->json([
            'message' => 'Task created successfully.',
            'task' => new TaskResource($task)
        ], 201);
    }

    /**
     * Update the specified task and log the event.
     */
    public function update(TaskRequest $request, int $id)
    {
        $task = Task::whereHas('project', function ($q) use ($request) {
            $q->where('user_id', $request->user()->id);
        })->find($id);

        if (!$task) {
            return response()->json(['error' => 'Task not found.'], 404);
        }

        $validated = $request->validated();
        $task->update($validated);

        // L1 Event Stream Telemetry Append
        EventStream::create([
            'user_id' => $request->user()->id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'event_type' => 'task_updated',
            'event_ts' => now(),
            'event_value' => 1.0,
            'metadata' => ['updated_fields' => array_keys($validated)],
        ]);

        return response()->json([
            'message' => 'Task updated successfully.',
            'task' => new TaskResource($task)
        ], 200);
    }

    /**
     * Complete a task, calculate actual_hours, and append a telemetry event to L1 event stream.
     */
    public function complete(Request $request, int $id)
    {
        $task = Task::whereHas('project', function ($q) use ($request) {
            $q->where('user_id', $request->user()->id);
        })->find($id);

        if (!$task) {
            return response()->json(['error' => 'Task not found.'], 404);
        }

        $request->validate([
            'actual_hours' => ['required', 'numeric', 'min:0'],
        ]);

        $actualHours = (float) $request->input('actual_hours');

        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
            'actual_hours' => $actualHours,
        ]);

        // L1 Event Stream Telemetry Append
        // In task_completed events, the event_value stores actual_hours
        // This is a major requirement for Newton-Raphson completion velocity calculation!
        EventStream::create([
            'user_id' => $request->user()->id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'event_type' => 'task_completed',
            'event_ts' => now(),
            'event_value' => $actualHours, // L1 value captures work volume
            'metadata' => [
                'estimated_hours' => $task->estimated_hours,
                'actual_hours' => $actualHours
            ],
        ]);

        return response()->json([
            'message' => 'Task marked as completed.',
            'task' => new TaskResource($task)
        ], 200);
    }

    /**
     * Remove the specified task from storage.
     */
    public function destroy(Request $request, int $id)
    {
        $task = Task::whereHas('project', function ($q) use ($request) {
            $q->where('user_id', $request->user()->id);
        })->find($id);

        if (!$task) {
            return response()->json(['error' => 'Task not found.'], 404);
        }

        $task->delete();

        return response()->json([
            'message' => 'Task deleted successfully.'
        ], 200);
    }
}
