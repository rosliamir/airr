<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Services\AuditService;
use App\Services\ConnectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// M2 Layer 2 — datasets (saved query/call + fields + runtime params) under a
// data source. Read gated by datasources.view, writes by datasources.manage.
class DatasetController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit, protected ConnectorService $connector) {}

    public function index(DataSource $dataSource): JsonResponse
    {
        return $this->sendOk($dataSource->datasets()->orderBy('name')->get()->map(fn (Dataset $d) => $this->row($d)));
    }

    public function store(Request $request, DataSource $dataSource): JsonResponse
    {
        $data = $this->validateDataset($request);
        $dataset = $dataSource->datasets()->create($data + ['created_by' => $request->user()->id]);
        $this->audit->log('dataset.created', Dataset::class, $dataset->id, null, ['name' => $dataset->name]);

        return $this->sendCreated($this->row($dataset));
    }

    public function update(Request $request, Dataset $dataset): JsonResponse
    {
        $dataset->update($this->validateDataset($request));
        $this->audit->log('dataset.updated', Dataset::class, $dataset->id);

        return $this->sendOk($this->row($dataset));
    }

    public function destroy(Dataset $dataset): JsonResponse
    {
        $dataset->delete();
        $this->audit->log('dataset.deleted', Dataset::class, $dataset->id);

        return $this->sendNoContent();
    }

    // FR-M2.5 — run with runtime parameter values; returns sample rows.
    public function preview(Request $request, Dataset $dataset): JsonResponse
    {
        $values = (array) $request->input('params', []);
        try {
            $result = $this->connector->run($dataset->load('dataSource'), $values);
            $this->audit->log('dataset.previewed', Dataset::class, $dataset->id);

            return $this->sendOk($result);
        } catch (\Throwable $e) {
            return $this->sendError(422, 'PREVIEW_FAILED', $e->getMessage());
        }
    }

    private function validateDataset(Request $request): array
    {
        return $request->validate([
            'name'              => 'required|string|max:160',
            'description'       => 'nullable|string|max:255',
            'query'            => 'nullable|string',
            'method'           => 'nullable|in:GET,POST',
            'body'             => 'nullable|string',
            'fields'           => 'nullable|array',
            'fields.*.name'    => 'required_with:fields|string',
            'fields.*.label'   => 'nullable|string',
            'fields.*.type'    => 'nullable|string',
            'parameters'              => 'nullable|array',
            'parameters.*.name'       => 'required_with:parameters|string',
            'parameters.*.label'      => 'nullable|string',
            'parameters.*.type'       => 'nullable|in:text,number,date,boolean',
            'parameters.*.default'    => 'nullable',
            'parameters.*.required'   => 'nullable|boolean',
        ]);
    }

    private function row(Dataset $d): array
    {
        return [
            'id'             => $d->id,
            'data_source_id' => $d->data_source_id,
            'name'           => $d->name,
            'description'    => $d->description,
            'query'          => $d->query,
            'method'         => $d->method,
            'body'           => $d->body,
            'fields'         => $d->fields ?? [],
            'parameters'     => $d->parameters ?? [],
            'created_at'     => $d->created_at,
        ];
    }
}
