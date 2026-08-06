<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Task;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Dashboard "task" widget data source. Read gated by tasks.view; write by tasks.manage.
class TaskController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $tasks = Task::query()
            ->where(fn ($q) => $q->where('assigned_to', $userId)->orWhere('created_by', $userId))
            ->orderBy('due_date')
            ->get();

        return $this->sendOk($tasks->map(fn ($t) => $this->row($t)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTask($request);
        $task = Task::create([...$data, 'created_by' => $request->user()->id]);
        $this->audit->log('task.created', Task::class, $task->id);

        return $this->sendCreated($this->row($task));
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $data = $this->validateTask($request);
        $task->update($data);
        $this->audit->log('task.updated', Task::class, $task->id);

        return $this->sendOk($this->row($task->fresh()));
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $task->delete();
        $this->audit->log('task.deleted', Task::class, $task->id);

        return $this->sendNoContent();
    }

    private function validateTask(Request $request): array
    {
        return $request->validate([
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'due_date'    => 'nullable|date',
            'status'      => ['nullable', Rule::in(Task::STATUSES)],
            'priority'    => ['nullable', Rule::in(Task::PRIORITIES)],
        ]);
    }

    private function row(Task $t): array
    {
        return [
            'id'          => $t->id,
            'title'       => $t->title,
            'description' => $t->description,
            'assigned_to' => $t->assigned_to,
            'due_date'    => $t->due_date,
            'status'      => $t->status,
            'priority'    => $t->priority,
            'created_by'  => $t->created_by,
            'created_at'  => $t->created_at,
            'updated_at'  => $t->updated_at,
        ];
    }
}
