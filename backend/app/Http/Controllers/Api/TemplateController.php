<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Template;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ModelResolver;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TemplateController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $query = Template::with('project:id,code,name', 'creator:id,name');

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        // Global (project_id IS NULL) + project-scoped visible to current user
        if ($pid = $request->input('project_id')) {
            $query->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $pid));
        }

        // Owner+project visibility (mirrors DataSourceController/KnowledgeBaseController):
        // a global template is only visible to its creator; a project-scoped one is
        // visible if the project itself is visible to the viewer.
        if (! $viewer->seesEverything()) {
            $query->where(fn ($q) => $q->where('created_by', $viewer->id)
                ->orWhereHas('project', fn ($p) => $p->visibleTo($viewer)));
        }
        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        return $this->sendOk($query->orderBy('name')->get()->map(fn ($t) => $this->row($t)));
    }

    public function archive(Request $request, Template $template): JsonResponse
    {
        $template->update(['archived_at' => now()]);
        $this->audit->log('template.archived', Template::class, $template->id);

        return $this->sendOk($this->row($template->fresh(['project', 'creator'])));
    }

    public function unarchive(Request $request, Template $template): JsonResponse
    {
        $template->update(['archived_at' => null]);
        $this->audit->log('template.unarchived', Template::class, $template->id);

        return $this->sendOk($this->row($template->fresh(['project', 'creator'])));
    }

    public function duplicate(Request $request, Template $template): JsonResponse
    {
        $copy = Template::create([
            'name' => $template->name . ' (Copy)', 'description' => $template->description,
            'project_id' => $template->project_id, 'data_source_id' => $template->data_source_id,
            'dataset_id' => $template->dataset_id, 'header' => $template->header, 'body' => $template->body,
            'footer' => $template->footer, 'page_header' => $template->page_header, 'page_footer' => $template->page_footer,
            'groups' => $template->groups, 'parameter_screen' => $template->parameter_screen,
            'meta' => $template->meta, 'tags' => $template->tags, 'created_by' => $request->user()->id,
        ]);
        $this->audit->log('template.duplicated', Template::class, $copy->id, null, ['from' => $template->id]);

        return $this->sendCreated($this->row($copy->fresh(['project', 'creator'])));
    }

    public function export(Template $template): \Symfony\Component\HttpFoundation\Response
    {
        $payload = [
            'name' => $template->name, 'description' => $template->description,
            'header' => $template->header, 'body' => $template->body, 'footer' => $template->footer,
            'page_header' => $template->page_header, 'page_footer' => $template->page_footer,
            'groups' => $template->groups ?? [], 'parameter_screen' => $template->parameter_screen,
            'tags' => $template->tags ?? [], 'exported_at' => now()->toIso8601String(),
        ];
        $filename = \Illuminate\Support\Str::slug($template->name) . '.json';

        return response()->json($payload)->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => 'nullable|file|mimes:json,txt|max:2048',
            'payload' => 'nullable|array',
            'project_id' => 'nullable|integer|exists:projects,id',
        ]);
        if ($request->hasFile('file')) {
            $decoded = json_decode($request->file('file')->get(), true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                return $this->sendError(422, 'INVALID_JSON', 'The uploaded file is not valid JSON.');
            }
        } else {
            $decoded = $data['payload'] ?? null;
        }
        if (! $decoded || empty($decoded['name'])) {
            return $this->sendError(422, 'INVALID_PAYLOAD', 'Provide a file or payload with at least a "name".');
        }

        $template = Template::create([
            'name' => $decoded['name'], 'description' => $decoded['description'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'header' => $decoded['header'] ?? null, 'body' => $decoded['body'] ?? null, 'footer' => $decoded['footer'] ?? null,
            'page_header' => $decoded['page_header'] ?? null, 'page_footer' => $decoded['page_footer'] ?? null,
            'groups' => $decoded['groups'] ?? [], 'parameter_screen' => $decoded['parameter_screen'] ?? null,
            'tags' => $decoded['tags'] ?? [], 'created_by' => $request->user()->id,
        ]);
        $this->audit->log('template.imported', Template::class, $template->id);

        return $this->sendCreated($this->row($template->fresh(['project', 'creator'])));
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
        $this->saveHistory($template, $request->user()->id, 'created');

        return $this->sendCreated($this->row($template->fresh(['project', 'creator'])));
    }

    public function update(Request $request, Template $template): JsonResponse
    {
        $template->update($this->validateTemplate($request));
        $this->audit->log('template.updated', Template::class, $template->id);
        $this->saveHistory($template, $request->user()->id, 'updated');

        return $this->sendOk($this->row($template->fresh(['project', 'creator']), true));
    }

    public function destroy(Template $template): JsonResponse
    {
        $template->delete(); // soft delete
        $this->audit->log('template.deleted', Template::class, $template->id);

        return $this->sendNoContent();
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $template = Template::withTrashed()->findOrFail($id);
        $template->restore();
        $this->audit->log('template.restored', Template::class, $template->id);

        return $this->sendOk($this->row($template->fresh(['project', 'creator'])));
    }

    // Change history snapshots.
    public function history(Template $template): JsonResponse
    {
        $rows = DB::table('template_histories as h')
            ->join('users as u', 'u.id', '=', 'h.changed_by')
            ->where('h.template_id', $template->id)
            ->orderByDesc('h.created_at')
            ->limit(50)
            ->get(['h.id', 'h.action', 'h.snapshot', 'h.created_at', 'u.name as changed_by_name']);

        return $this->sendOk($rows->map(fn ($r) => [
            'id'              => $r->id,
            'action'          => $r->action,
            'snapshot'        => json_decode($r->snapshot, true),
            'changed_by_name' => $r->changed_by_name,
            'created_at'      => \Carbon\Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
        ]));
    }

    // Access logs from the central audit log.
    public function logs(Template $template): JsonResponse
    {
        $rows = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.object_type', Template::class)
            ->where('a.object_id', (string) $template->id)
            ->orderByDesc('a.created_at')
            ->limit(100)
            ->get(['a.id', 'a.action', 'a.new_values', 'a.created_at', 'u.name as user_name']);

        return $this->sendOk($rows->map(fn ($r) => [
            'id'         => $r->id,
            'action'     => $r->action,
            'properties' => json_decode($r->new_values ?? '{}', true),
            'user_name'  => $r->user_name ?? 'System',
            'created_at' => \Carbon\Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
        ]));
    }

    // Generates HTML for a single section from a natural-language prompt.
    public function fillFromPrompt(Request $request, ModelResolver $resolver, AiProvider $ai): JsonResponse
    {
        $data = $request->validate([
            'prompt'  => 'required|string|max:2000',
            'section' => 'required|string|in:header,body,footer,page_header,page_footer,parameter_screen,group',
        ]);

        $model = $resolver->model('generation');
        $system = 'You write short HTML snippets for a report template section (' . $data['section'] . '). '
            . 'Return only the HTML fragment, no markdown fences, no explanation. '
            . 'You may use placeholders like {{SYSTEM:DATE}}, {{GLOBAL:KEY}}, {{PROJECT:KEY}} where relevant.';
        try {
            $html = $ai->generate($model, $data['prompt'], ['system' => $system, 'temperature' => 0.2]);
        } catch (\Throwable $e) {
            return $this->sendError(503, 'AI_UNAVAILABLE', 'Could not reach the AI provider: ' . $e->getMessage());
        }

        $html = trim($html);
        if (preg_match('/^```(?:html)?\s*(.*?)\s*```$/s', $html, $m)) {
            $html = trim($m[1]);
        }

        return $this->sendOk(['html' => $html]);
    }

    private function validateTemplate(Request $request): array
    {
        $data = $request->validate([
            'name'                  => 'required|string|max:160',
            'description'           => 'nullable|string|max:1000',
            'project_id'            => 'nullable|integer|exists:projects,id',
            'data_source_id'        => 'nullable|integer|exists:data_sources,id',
            'dataset_id'            => 'nullable|integer|exists:datasets,id',
            'header'                => 'nullable|string',
            'body'                  => 'nullable|string',
            'footer'                => 'nullable|string',
            'page_header'           => 'nullable|string',
            'page_footer'           => 'nullable|string',
            'parameter_screen'      => 'nullable|string',
            'groups'                => 'nullable|array',
            'groups.*.level'        => 'required_with:groups|integer|min:1',
            'groups.*.header'       => 'nullable|string',
            'groups.*.footer'       => 'nullable|string',
            'prompt'                => 'nullable|string|max:2000',
            'tags'                  => 'nullable|array',
            'tags.*'                => 'string|max:40',
        ]);

        if (array_key_exists('prompt', $data)) {
            $data['meta'] = ['prompt' => $data['prompt']];
            unset($data['prompt']);
        }

        return $data;
    }

    private function row(Template $t, bool $withSections = false): array
    {
        $base = [
            'id'          => $t->id,
            'name'        => $t->name,
            'description' => $t->description,
            'scope'          => $t->project_id ? 'project' : 'global',
            'project'        => $t->project ? ['id' => $t->project->id, 'code' => $t->project->code, 'name' => $t->project->name] : null,
            'data_source_id' => $t->data_source_id,
            'dataset_id'     => $t->dataset_id,
            'creator'     => $t->creator ? ['id' => $t->creator->id, 'name' => $t->creator->name] : null,
            'updated_at'  => $t->updated_at,
            'deleted_at'  => $t->deleted_at?->toISOString(),
            'archived_at' => $t->archived_at?->toISOString(),
            'tags'        => $t->tags ?? [],
        ];

        if ($withSections) {
            $base += [
                'header'           => $t->header,
                'body'             => $t->body,
                'footer'           => $t->footer,
                'page_header'      => $t->page_header,
                'page_footer'      => $t->page_footer,
                'groups'           => $t->groups ?? [],
                'parameter_screen' => $t->parameter_screen,
                'prompt'           => $t->meta['prompt'] ?? null,
            ];
        }

        return array_filter($base, fn ($v) => $v !== null);
    }

    private function saveHistory(Template $template, int $userId, string $action): void
    {
        DB::table('template_histories')->insert([
            'template_id' => $template->id,
            'changed_by'  => $userId,
            'action'      => $action,
            'snapshot'    => json_encode([
                'name'             => $template->name,
                'project_id'       => $template->project_id,
                'header'           => $template->header,
                'body'             => $template->body,
                'footer'           => $template->footer,
                'page_header'      => $template->page_header,
                'page_footer'      => $template->page_footer,
                'groups'           => $template->groups,
                'parameter_screen' => $template->parameter_screen,
            ]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
