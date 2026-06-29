<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// M4 (FR-M4.1) — validate the incoming orchestration request.
class OrchestrateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC enforced via middleware in routes
    }

    public function rules(): array
    {
        return [
            'prompt'         => 'required|string|min:10|max:4000',
            'project_id'     => 'nullable|integer|exists:projects,id',
            'data_source_id' => 'nullable|integer|exists:data_sources,id',
            'kb_ids'         => 'nullable|array',
            'kb_ids.*'       => 'integer|exists:knowledge_bases,id',
        ];
    }
}
