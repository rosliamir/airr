<?php

// AIRR AI provider & model resolution (FR-M15.6 / FR-M15.8).
// On-premise only (Ollama) — NO external AI APIs, no data egress.
// Per-task system defaults; projects may override via projects.ai_config
// (FR-M14.5), resolved through App\Services\Ai\ModelResolver.

return [
    // Active provider key (must exist in `providers` below). One day swappable
    // per edition (Enterprise = dedicated/tuned LLM) without code change.
    'provider' => env('AI_PROVIDER', 'ollama'),

    // System-default model per task. Empty project config inherits these.
    // Tasks: embedding | generation | reasoning | audit | retrieval | compliance.
    'defaults' => [
        'embedding'  => env('AI_MODEL_EMBEDDING',  'nomic-embed-text'),
        'generation' => env('AI_MODEL_GENERATION', 'llama3.1:8b'),
        'reasoning'  => env('AI_MODEL_REASONING',  'llama3.1:8b'),
        'audit'      => env('AI_MODEL_AUDIT',       'llama3.1:8b'),
        // M4 — can map to same model or a different one per project override.
        'retrieval'  => env('AI_MODEL_RETRIEVAL',  'nomic-embed-text'),
        'compliance' => env('AI_MODEL_COMPLIANCE', 'llama3.1:8b'),
    ],

    // Provider connection details.
    'providers' => [
        'ollama' => [
            'driver'  => \App\Services\Ai\OllamaProvider::class,
            'url'     => env('OLLAMA_URL', 'http://localhost:11434'),
            'timeout' => (int) env('AI_TIMEOUT', 120),
        ],
    ],

    // Valid task keys (guards typos in resolveModel()).
    'tasks' => ['embedding', 'generation', 'reasoning', 'audit', 'retrieval', 'compliance'],
];
