<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Template;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Template::with('project:id,code,name', 'creator:id,name');

        // Global (project_id IS NULL) + project-scoped visible to current user
        if ($pid = $request->input('project_id')) {
            $query->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $pid));
        }

        return $this->sendOk($query->orderBy('name')->get()->map(fn ($t) => $this->row($t)));
    }

    public function show(Template $template): JsonResponse
    {
        return $this->sendOk($this->row($template->load('project:id,code,name', 'creator:id,name'), true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTemplate($request);
        $template = Template::create($data + ['created_by' => $request->user()->id]);
        $this->audit->log('template.created', Template::class, $template->id, null, ['name' => $template->name]);

        return $this->sendCreated($this->row($template->fresh(['project', 'creator'])));
    }

    public function update(Request $request, Template $template): JsonResponse
    {
        $template->update($this->validateTemplate($request));
        $this->audit->log('template.updated', Template::class, $template->id);

        return $this->sendOk($this->row($template->fresh(['project', 'creator']), true));
    }

    public function destroy(Template $template): JsonResponse
    {
        $template->delete();
        $this->audit->log('template.deleted', Template::class, $template->id);

        return $this->sendNoContent();
    }

    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name'         => 'required|string|max:160',
            'description'  => 'nullable|string|max:1000',
            'project_id'   => 'nullable|integer|exists:projects,id',
            'header'       => 'nullable|string',
            'body'         => 'nullable|string',
            'footer'       => 'nullable|string',
            'group_header' => 'nullable|string',
            'group_footer' => 'nullable|string',
        ]);
    }

    private function row(Template $t, bool $withSections = false): array
    {
        $base = [
            'id'          => $t->id,
            'name'        => $t->name,
            'description' => $t->description,
            'scope'       => $t->project_id ? 'project' : 'global',
            'project'     => $t->project ? ['id' => $t->project->id, 'code' => $t->project->code, 'name' => $t->project->name] : null,
            'creator'     => $t->creator ? ['id' => $t->creator->id, 'name' => $t->creator->name] : null,
            'updated_at'  => $t->updated_at,
        ];

        if ($withSections) {
            $base += [
                'header'       => $t->header,
                'body'         => $t->body,
                'footer'       => $t->footer,
                'group_header' => $t->group_header,
                'group_footer' => $t->group_footer,
            ];
        }

        return array_filter($base, fn ($v) => $v !== null);
    }
}
