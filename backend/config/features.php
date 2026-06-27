<?php

// Module readiness board (Dashboard drill-down). Each feature carries the
// build-time facts we know (developed, ai_tested = covered by automated tests);
// human_tested + ready_for_prod are toggled by reviewers and stored in the
// feature_statuses table. Keep in sync with docs/AIRR-Dev-Tracker.md + Test-Tracker.
//
// Shape: 'Mx' => ['name' => ..., 'features' => [ ['key','fr','label','developed','ai_tested'], ... ]]

return [
    'M1' => ['name' => 'Core Platform & Security', 'features' => [
        ['key' => 'm1.1', 'fr' => 'FR-M1.1', 'label' => 'Auth: login/logout/token, password reset, self-register, Google OAuth', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm1.2', 'fr' => 'FR-M1.2', 'label' => 'RBAC: roles + granular permissions', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm1.5', 'fr' => 'FR-M1.5', 'label' => 'Immutable audit trail + export', 'developed' => true, 'ai_tested' => false],
    ]],
    'M2' => ['name' => 'Data Source Connector', 'features' => [
        ['key' => 'm2.1', 'fr' => 'FR-M2.1', 'label' => 'Connect PostgreSQL / MySQL / Oracle (SQL Server scaffolded)', 'developed' => true, 'ai_tested' => false],
        ['key' => 'm2.2', 'fr' => 'FR-M2.2', 'label' => 'Connect REST API (bearer/header; GraphQL scaffolded)', 'developed' => true, 'ai_tested' => false],
        ['key' => 'm2.3', 'fr' => 'FR-M2.3', 'label' => 'Ingest CSV / Excel / JSON (parse, preview)', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm2.4', 'fr' => 'FR-M2.4', 'label' => 'Schema introspection + cache', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm2.5', 'fr' => 'FR-M2.5', 'label' => 'Datasets: SQL/API + runtime params + SELECT-guarded preview', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm2.8', 'fr' => 'FR-M2.8', 'label' => 'Credentials encrypted-at-rest', 'developed' => true, 'ai_tested' => true],
    ]],
    'M3' => ['name' => 'Knowledge Base / RAG', 'features' => [
        ['key' => 'm3.1', 'fr' => 'FR-M3.1', 'label' => 'Train-the-DB UI: KB CRUD + upload reference docs', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm3.2', 'fr' => 'FR-M3.2', 'label' => 'Ingest PDF / DOCX / XLSX / Markdown', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm3.2a', 'fr' => 'FR-M3.2a', 'label' => 'Document categories (URS/SRS/SDS/UAT/Manual/Other)', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm3.2b', 'fr' => 'FR-M3.2b', 'label' => 'System-knowledge ingest (DB schema + RBAC)', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm3.3', 'fr' => 'FR-M3.3', 'label' => 'Structure-aware chunking', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm3.6', 'fr' => 'FR-M3.6', 'label' => 'Ingestion status (queued/processing/trained/error)', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm3.9', 'fr' => 'FR-M3.9', 'label' => 'Per-document version history & change log', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm3.5', 'fr' => 'FR-M3.5', 'label' => 'Embeddings via pgai → pgvector (needs Ollama)', 'developed' => false, 'ai_tested' => false],
        ['key' => 'm3.8', 'fr' => 'FR-M3.8', 'label' => 'Semantic retrieval (needs Ollama)', 'developed' => false, 'ai_tested' => false],
        ['key' => 'm3.10', 'fr' => 'FR-M3.10', 'label' => 'Zero-trust access filtering', 'developed' => false, 'ai_tested' => false],
    ]],
    'M6' => ['name' => 'Report Engine & Definition', 'features' => [
        ['key' => 'm6.1', 'fr' => 'FR-M6.1', 'label' => 'Report = portable JSON definition + CRUD', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm6.2', 'fr' => 'FR-M6.2', 'label' => 'Render: table/list · grouped · KPI (HTML)', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm6.3', 'fr' => 'FR-M6.3', 'label' => 'Grouping + subtotal / grand total', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm6.4', 'fr' => 'FR-M6.4', 'label' => 'Conditional formatting (colour by rule)', 'developed' => true, 'ai_tested' => false],
        ['key' => 'm6.2b', 'fr' => 'FR-M6.2', 'label' => 'Render: matrix / chart / drill-down / doc-style', 'developed' => false, 'ai_tested' => false],
        ['key' => 'm6.5', 'fr' => 'FR-M6.5', 'label' => 'Runtime params (date picker / dropdown)', 'developed' => false, 'ai_tested' => false],
    ]],
    'M14' => ['name' => 'Admin & Governance', 'features' => [
        ['key' => 'm14.4a', 'fr' => 'FR-M14.4a', 'label' => 'Users / Roles / Groups CRUD', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm14.3', 'fr' => 'FR-M14.3', 'label' => 'Owner+group visibility scoping; avatar upload', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm14.x', 'fr' => 'FR-M14.x', 'label' => 'Projects CRUD + assignment + scoping', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm14.4b', 'fr' => 'FR-M14.4b', 'label' => 'Settings: Regional / Subscription / About', 'developed' => true, 'ai_tested' => false],
        ['key' => 'm14.5', 'fr' => 'FR-M14.5', 'label' => 'Per-project AI model config', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm14.6', 'fr' => 'FR-M14.6', 'label' => 'Settings → AI/Models tab', 'developed' => true, 'ai_tested' => true],
    ]],
    'M15' => ['name' => 'Deployment & Infra', 'features' => [
        ['key' => 'm15.1', 'fr' => 'FR-M15.1', 'label' => 'Design-Time & Runtime separately deployable (scaffold)', 'developed' => true, 'ai_tested' => false],
        ['key' => 'm15.2', 'fr' => 'FR-M15.2', 'label' => '100% web-based authoring', 'developed' => true, 'ai_tested' => false],
        ['key' => 'm15.4', 'fr' => 'FR-M15.4', 'label' => 'Containerised CI pipeline (basic)', 'developed' => true, 'ai_tested' => false],
        ['key' => 'm15.6', 'fr' => 'FR-M15.6', 'label' => 'AiProvider abstraction + system-default model config', 'developed' => true, 'ai_tested' => true],
        ['key' => 'm15.8', 'fr' => 'FR-M15.8', 'label' => 'resolveModel(project, task) resolver', 'developed' => true, 'ai_tested' => true],
    ]],
];
