<?php

// AIRR edition feature-gating (open-core). Single source of truth for what each
// edition unlocks. Gate features via the `feature:` middleware or Edition::allows().

return [
    // community | standard | enterprise
    'edition' => env('AIRR_EDITION', 'standard'),

    // Service endpoints (sovereign / on-premise only — no external AI APIs).
    'rag_api_url' => env('RAG_API_URL', 'http://localhost:8001'),
    'ollama_url'  => env('OLLAMA_URL', 'http://localhost:11434'),

    // Feature matrix. A feature is enabled when the active edition is listed.
    // Keys are referenced by the `feature:<key>` middleware.
    'features' => [
        // Community and up
        'core_reporting'      => ['community', 'standard', 'enterprise'],
        'nl_single_prompt'    => ['community', 'standard', 'enterprise'],
        'rbac_basic'          => ['community', 'standard', 'enterprise'],
        'run_from_json'       => ['community', 'standard', 'enterprise'],

        // Standard and up
        'multi_agent'         => ['standard', 'enterprise'],
        'double_check'        => ['standard', 'enterprise'],
        'hybrid_fusion'       => ['standard', 'enterprise'],
        'interactive_chat'    => ['standard', 'enterprise'],
        'cra_compile'         => ['standard', 'enterprise'],
        'all_connectors'      => ['standard', 'enterprise'],
        'rbac_full'           => ['standard', 'enterprise'],
        'mpsa_checks'         => ['standard', 'enterprise'],
        'project_ai_config'   => ['standard', 'enterprise'], // per-project model override (FR-M14.5); Community = system default only
        'project_constants'   => ['standard', 'enterprise'], // per-project constants (templates); same tier as project_ai_config

        // Enterprise only
        'zero_trust_rag'      => ['enterprise'],
        'multi_tenant'        => ['enterprise'],
        'dedicated_llm'       => ['enterprise'],
        'air_gap_certified'   => ['enterprise'],
        'source_escrow'       => ['enterprise'],
    ],

    // Edition limits (null = unlimited).
    'limits' => [
        'community'  => ['data_sources' => 1,    'rag_kbs' => 1,    'api_endpoints' => 1],
        'standard'   => ['data_sources' => null, 'rag_kbs' => null, 'api_endpoints' => 25],
        'enterprise' => ['data_sources' => null, 'rag_kbs' => null, 'api_endpoints' => null],
    ],
];
