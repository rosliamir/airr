<?php

// M3 Knowledge Base taxonomy. KB content falls into two families:
//   - documents: human artifacts uploaded as files (FR-M3.2)
//   - system:    knowledge introspected from within AIRR / data sources,
//                converted to descriptive text before embedding (FR-M3.4)
// Config-driven so editions/projects can extend the list without code change.

return [
    // Uploaded document artifacts (the upload UI offers these). 'other' lets the
    // user type a custom label (kb_documents.category_label).
    'document_categories' => [
        'urs'             => 'User Requirement Spec (URS)',
        'srs'             => 'Software Requirement Spec (SRS)',
        'sds'             => 'Software Design Spec (SDS)',
        'test_script'     => 'Test Script',
        'uat'             => 'UAT Document',
        'user_manual'     => 'User Manual',
        'helpdesk_ticket' => 'Help Desk Ticket',
        'other'           => 'Other',
    ],

    // System-knowledge sources ingested from AIRR itself (not file upload).
    // Built incrementally — listed here as the target taxonomy (Part B).
    'system_sources' => [
        'db_schema'       => 'Database Schema',
        'erd'             => 'ERD / Relationships',
        'data_dictionary' => 'Data Dictionary / Reference Codes',
        'glossary'        => 'Business Glossary',
        'business_logic'  => 'Business Logic / Workflow',
        'ui_screen'       => 'Screen / UI',
        'menu'            => 'Menu / Navigation',
        'rbac'            => 'RBAC (Roles & Permissions)',
        'api_catalog'     => 'API Endpoint Catalog',
    ],
];
