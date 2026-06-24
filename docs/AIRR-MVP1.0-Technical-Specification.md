# AIRR — AI & RAG Reporting
## Technical Specification (Software Requirements & Design) — MVP 1.0 (Standard Edition)

**Product:** AIRR — AI & RAG Reporting — *Thin & light, but powerful engine.*
**Edition:** MVP 1.0 (Standard Edition)
**Status:** Draft for review
**Companion documents:** `AIRR-MVP1.0-Technical-Product-Roadmap.md` (delivery plan), `AIRR-Sales-Deck.pptx` (sales), `AIRR-architecture.png` (schematic), `AIRR-brand.md` (brand).

---

## Table of Contents
1. Introduction (Purpose, Scope, Audience, Definitions)
2. Product Overview (Vision, Philosophy, Problem, Objectives)
3. Stakeholders & User Roles
4. System Architecture
5. Technology Stack & Versions
6. Feature Catalogue (Level 1 & Level 2)
7. Functional Requirements (by Module Group, M1–M15)
8. AI & RAG Specification
9. Security Specification
10. Compliance Specification (MPSA / WCAG-OKU)
11. Report & Output Specification
12. API Specification (Report-as-API)
13. Data Model (Key Entities)
14. Non-Functional Requirements
15. Deployment & Environments
16. Assumptions, Constraints & Risks
17. Glossary

---

# 1. Introduction

## 1.1 Purpose
Specifies the requirements and high-level design for **AIRR MVP 1.0**, an AI-native, RAG-powered reporting platform embeddable into any system-development project. Single source of truth for what MVP 1.0 must do and how it is structured.

## 1.2 Scope
MVP 1.0 (Standard Edition) covers: data connectivity, a knowledge base (RAG), multi-agent AI report generation, a no-code web authoring studio, a deterministic rendering and compilation pipeline, secure delivery (including Report-as-API), governance, and self-hosted deployment with full data sovereignty. Deferred items are listed under **MVP 2.0** in the roadmap.

## 1.3 Intended Audience
Product owners, business analysts (BA), solution architects, developers, QA, security/compliance officers, and prospective customers.

## 1.4 Definitions (quick reference — full glossary in §17)
- **CRA** — Compiled Report Artifact: an opaque, signed, non-human-readable executable produced from the JSON source.
- **RAG** — Retrieval-Augmented Generation.
- **Design-Time Engine** — authoring + compilation engine.
- **Runtime Engine** — execution + serving engine (separate from Design-Time).
- **MPSA** — Malaysian Government Portals and Websites Assessment.

---

# 2. Product Overview

## 2.1 Vision
A lightweight, future-proof reporting engine that replaces static reporting (Oracle Reports, JasperReports, iReport) with **dynamic, context-aware, living reports**, governed for the public sector and enterprise, and **sovereign by design**.

## 2.2 Philosophy
1. **Thin & Light, but Powerful** — embeddable yet capable of fusing text, numbers and context.
2. **Context over Query** — ingests project artifacts (URS/SRS/SDS/manuals) to understand intent.
3. **From Static to Living** — interactive, conversational, dynamically reshapeable.
4. **Democratised Reporting** — BAs and users author by prompt; no report developers.

## 2.3 Problem Statement
Legacy reporting tools are developer-dependent, produce static/inert output, are disconnected from document context, are inaccessible to non-IT users (prone to Text-to-SQL errors), require manual compliance work, and are hard to integrate.

## 2.4 Objectives
1. Eliminate developer dependency (prompt + templates).
2. Hybrid Context Fusion (SQL + RAG).
3. Living reports (chat, narration, dynamic output).
4. Accuracy & trust (multi-agent + double-check guardrail + semantic schema cache).
5. Secure, integrable delivery (Report-as-API, RBAC, audit).
6. Flexible, sovereign deployment (Design-Time/Runtime split; Malaysian DC; on-premise Ollama).

---

# 3. Stakeholders & User Roles

| Role | Description | Key permissions |
|---|---|---|
| **System Administrator** | Installs, configures, manages environments, security, tenants. | Full admin, RBAC config, audit export, deployment. |
| **Business Analyst (BA)** | Defines report requirements; authors via studio/prompt. | Create/edit definitions, templates, run, preview. |
| **Report Author / Designer** | Builds and styles reports in the Design-Time Studio. | Design, compile, publish (per RBAC). |
| **End User / Viewer** | Runs reports, interacts via chat, exports. | Run, view, export (per RBAC & clearance). |
| **Compliance / Security Officer** | Reviews compliance & audit posture. | Audit logs, compliance reports, masking policy. |
| **API Consumer (system)** | External app calling Report-as-API. | Scoped secret-key access. |
| **Knowledge Manager** | Uploads & curates KB documents. | Upload, tag, version, re-index KB. |

---

# 4. System Architecture

## 4.1 Architectural Principles
- **Separation of authoring and execution** (Design-Time vs Runtime engines).
- **Source/artifact split** — human-readable **JSON source** vs protected **CRA**.
- **Sovereign by default** — all data, documents, vectors and inference stay in-country.
- **Deterministic rendering** — layout is reproducible and print-stable.
- **Security at the data layer** — RBAC and zero-trust RAG filtering inside PostgreSQL.

## 4.2 Logical Tiers
- **Client Tier (Browser, zero-install):** Design-Time Studio (Vue+Tailwind SPA) and Report Viewer (interactive chat).
- **Application Tier:** API Gateway / App Server (Laravel); Auth·RBAC·Audit; AI Orchestration controller; Scheduler/Workers.
- **Engine Tier:** Design-Time Engine + Compiler; Runtime Engine (executes CRA).
- **AI/RAG Tier (on-premise):** rag_api (FastAPI); Ollama local LLM.
- **Data & Artifact Tier:** PostgreSQL 16/17 (pgvector + pgai); Artifact/Object Store (CRA, documents).
- **External (outbound):** external data sources; API consumers.

## 4.3 Design-Time vs Runtime (critical)
- **100% web-based development** — no local install; authors work in the browser.
- **Two separate, independently deployable engines:** Design-Time used only during development; Runtime ships to Dev/Staging/Prod.
- **Report source-of-truth:** a human-readable, versioned **JSON Report Definition**.

## 4.4 Compilation Pipeline (JSON → CRA)
```
WYSIWYG Designer
  → JSON Source (readable, versioned)
  → Compile (Design-Time Engine)
  → CRA (opaque · signed · encrypted · non-human-readable)
  → Publish/promote to Artifact Store + registry
  → Runtime Engine loads, verifies (signature/integrity), executes online
  → Renders Report / PDF / Excel / REST API
```
**Rationale:** the CRA cannot be read or edited by humans; only the Runtime Engine can execute it — IP protection and tamper resistance.

## 4.5 Data Sovereignty
All application data, uploaded documents and vector embeddings reside exclusively in a **Malaysian data center**. The LLM is **self-hosted on-premise via Ollama**; prompts, documents and data never leave the country and are never sent to any external AI API. The full stack is **air-gap capable**.

---

# 5. Technology Stack & Versions

| Layer | Technology | Version / Notes |
|---|---|---|
| Frontend / Authoring SPA | Vue + Tailwind CSS | Latest stable |
| Application / API backend | Laravel (PHP) | Latest LTS-compatible |
| Database | PostgreSQL | **16 or 17** |
| Vector | pgvector | **v0.7.x / v0.8.x** (HNSW, vector quantization) |
| In-database AI | pgai | **v0.12.x or higher** |
| RAG service | rag_api (FastAPI / Python) | **Python 3.10 / 3.11 / 3.12** |
| Local LLM inference | Ollama | On-premise, self-hosted |
| Containerisation / OS | Docker; Linux / Windows | Self-hosted Dev/Staging/Prod |

---

# 6. Feature Catalogue (Level 1 & Level 2)
*(Authoritative feature list maintained in the roadmap §6. All are MVP 1.0 scope.)*

1. **Data Source & Connectivity** — DB, REST/GraphQL, files, parameterized queries, schema introspection, RAG-as-source, caching.
2. **Report Types & Layout** — list/tabular, grouped (above/left), matrix/pivot, summary/KPI, charts, drill-down, document-style, dynamic AI output.
3. **Report Designer (Studio)** — WYSIWYG, live preview, property inspector, unlimited undo/redo, JSON source view, prompt authoring, zero-install.
4. **AI & RAG Intelligence** — NL report builder, prompt-as-template, auto-layout, smart formatting, anomaly detection, in-DB AI, hybrid prompt box.
5. **Flagship AI-Native** — hybrid context fusion, multi-agent, interactive chat, insight narration, semantic schema caching, MPSA compliance readiness, double-check guardrail.
6. **Knowledge Base / RAG** — Train-the-DB UI, multi-format ingestion, structure-aware chunking, vector store, context-aware reporting, KB management, source grounding.
7. **Templates, Definition & Compilation** — JSON definition, template library, versioning, compile to CRA, publish/promote, run.
8. **Output & API Delivery** — deterministic Tailwind→PDF, multi-format export, Report-as-API (secret key + auto docs), scheduling, shareable links, embeddable views.
9. **Settings & Presentation** — page size, orientation, output format, security-to-open, URL-to-open, branding, page setup.
10. **Formatting & Interactivity** — conditional formatting, totals, localization, interactive view, runtime parameters, saved views.
11. **Mini Data Warehouse** — data marts, materialized datasets, embedded analytics, scheduled refresh.
12. **Security, RBAC & Audit** — RBAC, audit trail, authentication, data masking, CRA integrity, data sovereignty, on-premise LLM, zero-trust RAG security.
13. **Deployment, Runtime & Infrastructure** — separate engines, web-based dev, self-hosted, environments, OS support, CI/CD.
14. **Administration & Governance** — usage analytics, template/version governance, multi-tenancy, global settings.

---

# 7. Functional Requirements (by Module Group)

> Notation: **FR-Mx.n**. Priority: **M** = Must (MVP 1.0).

## GROUP 1 — Foundation & Platform

### M1 — Core Platform & Security
| ID | Requirement | Pri |
|---|---|---|
| FR-M1.1 | Provide authentication (login, logout, session, token refresh, password reset). | M |
| FR-M1.2 | Support RBAC with roles and granular permissions (view/create/run/export/admin). | M |
| FR-M1.3 | Enforce row-level and column-level security policies per role. | M |
| FR-M1.4 | Manage API secret keys (issue, rotate, revoke) with per-role scope. | M |
| FR-M1.5 | Record an immutable audit trail (actor, action, object, timestamp) and allow export. | M |
| FR-M1.6 | Support data masking for sensitive columns. | M |
| FR-M1.7 | Verify CRA signature/integrity before execution. | M |

### M2 — Data Source Connector
| ID | Requirement | Pri |
|---|---|---|
| FR-M2.1 | Connect to PostgreSQL, MySQL and SQL Server via query or table. | M |
| FR-M2.2 | Connect to REST/GraphQL APIs with authentication and pagination. | M |
| FR-M2.3 | Ingest CSV, Excel and JSON files as data sources. | M |
| FR-M2.4 | Introspect source schemas (tables, columns, types, relationships) and cache metadata. | M |
| FR-M2.5 | Support saved/parameterized queries with runtime parameters. | M |
| FR-M2.6 | Allow a RAG collection to be used as a data source. | M |
| FR-M2.7 | Cache result sets with configurable TTL and invalidation. | M |
| FR-M2.8 | Store connection credentials encrypted at rest. | M |

### M15 — Deployment & Infrastructure
| ID | Requirement | Pri |
|---|---|---|
| FR-M15.1 | Design-Time and Runtime engines shall be separately deployable services. | M |
| FR-M15.2 | All development shall be 100% web-based (no local install for authors). | M |
| FR-M15.3 | Run self-hosted across Dev/Staging/Prod on Linux and Windows. | M |
| FR-M15.4 | Provide a containerised deployment pipeline (build, migrate, promote). | M |
| FR-M15.5 | Deployable entirely within a Malaysian data center and air-gap capable. | M |
| FR-M15.6 | Provide an **`AiProvider` abstraction** (Ollama as first implementation; on-premise only, no external egress) with a **system-default model config** selectable **per task** (embedding / generation / reasoning / audit). Config-driven, not hard-coded. | M |
| FR-M15.7 | **Model management** — list / pull / health-check local models; swap models without redeploy. | M |
| FR-M15.8 | Provide a single **`resolveModel(project, task)`** resolver: per-project config overrides system default; consumed by M3 (embedding) and M4 (agents). | M |

## GROUP 2 — Intelligence (AI & Knowledge)

### M3 — Knowledge Base / RAG
| ID | Requirement | Pri |
|---|---|---|
| FR-M3.1 | Provide a "Train the Database" UI for admins to upload reference documents. | M |
| FR-M3.2 | Ingest PDF, DOCX, XLSX and Markdown automatically. | M |
| FR-M3.3 | Chunk documents by logical chapter/structure (not fixed word counts) using pgai. | M |
| FR-M3.4 | Convert flowcharts/diagrams and tables in SRS/SDS into descriptive text before embedding. | M |
| FR-M3.5 | Generate embeddings via pgai and store them in pgvector (HNSW). | M |
| FR-M3.6 | Show ingestion/training status (queued/processing/trained) and log errors. | M |
| FR-M3.7 | Maintain a semantic schema cache mapping business terms to tables/columns. | M |
| FR-M3.8 | Perform semantic retrieval (similarity search) for prompt context. | M |
| FR-M3.9 | Support KB versioning, tagging, re-indexing and deletion. | M |
| FR-M3.10 | Apply **zero-trust access filtering** so retrieval returns only chunks permitted for the user's role/clearance. | M |

### M4 — AI Orchestration (Multi-Agent)
| ID | Requirement | Pri |
|---|---|---|
| FR-M4.1 | Accept single and hybrid (structured + unstructured) prompts. | M |
| FR-M4.2 | Resolve intent and map business terms to schema using the semantic cache. | M |
| FR-M4.3 | **Retriever agent** fetches relevant document passages via pgvector. | M |
| FR-M4.4 | **Data-Analyst agent** generates and executes SQL to obtain raw data. | M |
| FR-M4.5 | **Auditor agent (Double-Check)** verifies every fact/figure/quote vs PostgreSQL & RAG, rejecting fabrications and forcing regeneration. | M |
| FR-M4.6 | **Writer/Designer agent** composes the report as HTML/Tailwind. | M |
| FR-M4.7 | Perform Hybrid Context Fusion (merge text + numeric results into one answer). | M |
| FR-M4.8 | Generate an automated executive-summary narration. | M |
| FR-M4.9 | Validate generated SQL against schema and permissions before execution (guardrail). | M |
| FR-M4.10 | **Compliance agent** checks the draft against MPSA/portal guidelines and flags non-compliance before publishing. | M |
| FR-M4.11 | All inference runs locally via Ollama with no external API calls. | M |

## GROUP 3 — Authoring & Reporting Engine

### M5 — Design-Time Studio (Web IDE)
| ID | Requirement | Pri |
|---|---|---|
| FR-M5.1 | WYSIWYG drag-and-drop canvas (bands, elements, data binding). | M |
| FR-M5.2 | **Property Inspector** for per-element properties (binding, style, format mask, expression, visibility rules). | M |
| FR-M5.3 | **Live Preview** with sample or live-bound data before publish/run. | M |
| FR-M5.4 | **Unlimited, multi-step Undo/Redo** via a command stack. | M |
| FR-M5.5 | Expose and allow editing of the canonical **JSON source**. | M |
| FR-M5.6 | Validate bindings/expressions (lint) and report errors. | M |
| FR-M5.7 | Support prompt-driven authoring (NL → definition). | M |

### M6 — Report Engine & Definition
| ID | Requirement | Pri |
|---|---|---|
| FR-M6.1 | Represent each report as a portable JSON definition (fields, groups, filters, sorts, aggregates, AI hints). | M |
| FR-M6.2 | Render: list/tabular, grouped (group-above & group-left), matrix/pivot, summary/KPI, chart, drill-down, document-style, dynamic AI output. | M |
| FR-M6.3 | Support multi-level grouping with subtotal/grand/running/percent-of-total. | M |
| FR-M6.4 | Support conditional formatting (color/icon/data-bar by rule). | M |
| FR-M6.5 | Prompt for runtime parameters (date pickers, dropdowns). | M |

### M7 — Compiler & Artifact Service
| ID | Requirement | Pri |
|---|---|---|
| FR-M7.1 | Transform a JSON source into a CRA (intermediate representation). | M |
| FR-M7.2 | CRA shall be encrypted, signed, and integrity-hashed; non-human-readable. | M |
| FR-M7.3 | Maintain an artifact registry with versioning. | M |
| FR-M7.4 | Publish/promote CRAs to Dev/Staging/Prod targets. | M |
| FR-M7.5 | Support rollback to a previous CRA version. | M |

### M8 — Runtime Engine
| ID | Requirement | Pri |
|---|---|---|
| FR-M8.1 | Load and verify a CRA (signature/integrity) before execution. | M |
| FR-M8.2 | Deserialize and execute the CRA online. | M |
| FR-M8.3 | Bind live data and apply runtime parameters and security policies. | M |
| FR-M8.4 | Render output for the requested channel (view/PDF/Excel/API). | M |
| FR-M8.5 | Be stateless and horizontally scalable. | M |

## GROUP 4 — Experience & Delivery

### M9 — Interactive Report Chat
| ID | Requirement | Pri |
|---|---|---|
| FR-M9.1 | Allow selecting/highlighting any paragraph or chart in a rendered report. | M |
| FR-M9.2 | Answer follow-up questions about a selected element. | M |
| FR-M9.3 | Dynamically mutate the report (change chart type, filter, scope). | M |
| FR-M9.4 | Retain conversational context for the report session. | M |

### M10 — Output & Export
| ID | Requirement | Pri |
|---|---|---|
| FR-M10.1 | Render PDF via a **deterministic Tailwind→HTML→PDF** engine with stable, print-safe layout. | M |
| FR-M10.2 | Export Excel, CSV, JSON and HTML. | M |
| FR-M10.3 | Honour page size (A4/Letter/Legal/custom), orientation, headers/footers, page numbers, repeating group headers and branding. | M |
| FR-M10.4 | Support security-to-open (password/access control) on output. | M |
| FR-M10.5 | Generate permission-controlled shareable URLs. | M |

### M11 — API Delivery
| ID | Requirement | Pri |
|---|---|---|
| FR-M11.1 | Expose any report as a REST API endpoint. | M |
| FR-M11.2 | Each endpoint requires a secret key and enforces RBAC scope. | M |
| FR-M11.3 | Auto-generate API documentation (endpoints, parameters, sample responses). | M |
| FR-M11.4 | Support rate limiting and key revocation. | M |
| FR-M11.5 | Log every API call for audit. | M |

### M12 — Scheduler & Delivery
| ID | Requirement | Pri |
|---|---|---|
| FR-M12.1 | Schedule reports (cron) with recipient selection. | M |
| FR-M12.2 | Deliver via email and webhook. | M |
| FR-M12.3 | Refresh materialized datasets on schedule. | M |

## GROUP 5 — Data & Governance

### M13 — Mini Data Warehouse
| ID | Requirement | Pri |
|---|---|---|
| FR-M13.1 | Define data marts (optimised subset/star schema). | M |
| FR-M13.2 | Materialize and refresh cached datasets for large data. | M |
| FR-M13.3 | Provide embeddable analytics blocks for other applications. | M |

### M14 — Administration & Governance
| ID | Requirement | Pri |
|---|---|---|
| FR-M14.1 | Provide usage analytics on reports. | M |
| FR-M14.2 | Provide template & version governance (approval/publish workflow). | M |
| FR-M14.3 | Provide baseline multi-tenant isolation. | M |
| FR-M14.4 | Provide global system and branding settings. | M |
| FR-M14.5 | **Per-project AI configuration** — each project selects provider + model **per task** (embedding/generation/reasoning/audit); empty = inherit system default (FR-M15.6). Stored as `projects.ai_config` (JSONB). Gated: Community=default only; Standard=choose installed models; Enterprise=dedicated/tuned. | M |
| FR-M14.6 | **Settings → AI/Models tab** — admin sets system default and health-checks models (UI may follow the resolver). | M |

---

# 8. AI & RAG Specification

## 8.1 Multi-Agent Pipeline
Sequential, auditable agents (LLM via Ollama):
1. **Retriever** — semantic search (pgvector/HNSW) over permitted KB chunks.
2. **Data Analyst** — schema-aware SQL generation + execution (guarded).
3. **Auditor (Double-Check)** — fact/figure/quote reconciliation vs PostgreSQL & RAG; mismatch → reject & regenerate.
4. **Writer/Designer** — composes HTML/Tailwind output.
- **Compliance agent** runs pre-publish (see §10).
- Design principle: the LLM generates the **report definition / structured output**, which the deterministic engine renders — never raw, unverifiable output.

## 8.2 Hybrid Context Fusion
Combines unstructured retrieval (RAG text) with structured query results (SQL) into a single response explaining both *what* and *why*.

## 8.3 Semantic Schema Caching (Text-to-SQL accuracy)
Per-table/column documentation stored in RAG. Before SQL generation, resolve business terms (e.g., "active" → `status = 1`) to prevent Text-to-SQL errors.

## 8.3a AI Provider & Model Resolution (M15.6–M15.8, M14.5)
On-premise only (Ollama); **no external AI egress**. A single `AiProvider` abstraction decouples callers from the engine, so editions can swap implementations (Standard = Ollama; Enterprise = dedicated/tuned LLM) without code change.

**Hierarchical config (per task):**
```
System default (admin)  →  Project override (projects.ai_config, JSONB)  →  resolveModel(project, task)
```
Tasks: `embedding` · `generation` · `reasoning` · `audit`. Empty project config inherits the system default. All AI callers (M3 ingestion/retrieval, M4 agents) MUST go through `resolveModel()` — never reference a model name directly.

## 8.4 Ingestion Pipeline (M3)
Multi-format intake (PDF/DOCX/XLSX/MD) → structure-aware chunking → diagram/table-to-text conversion → pgai embedding → pgvector storage → indexed for retrieval. Status tracked; re-indexable.

## 8.5 Guardrails
- **SQL guardrail:** validate generated SQL against schema + permissions before execution.
- **Double-Check guardrail:** verify report facts vs sources before publish.
- **Grounding:** every answer cites/links its source chunk(s).

---

# 9. Security Specification

| Area | Specification |
|---|---|
| **Authentication** | Login/session; token refresh; secret keys for APIs. |
| **RBAC** | Roles + granular permissions on reports, data sources, templates, KB, APIs. |
| **Row/Column security** | Enforced in PostgreSQL; per-role policies. |
| **Zero-Trust RAG** | Retrieval filters chunks by role/clearance. |
| **Audit trail** | Immutable user-action log + API-access log; exportable. |
| **Data masking** | Masking rules for sensitive columns. |
| **CRA protection** | Encrypted, signed, integrity-verified; executable only by Runtime Engine. |
| **Data sovereignty** | Malaysian DC residency; on-premise Ollama; zero external AI egress; air-gap capable. |
| **Credentials** | Encrypted at rest; least-privilege connections. |

---

# 10. Compliance Specification (MPSA / WCAG-OKU)

- **Standards targeted:** MPSA and public-sector portal guidelines; WCAG/OKU accessibility.
- **Automated checks (pre-publish):** mandatory disclaimers, copyright statements, accessibility formatting, and portal-guideline clauses.
- **Behaviour:** the Compliance agent reads the draft, compares it to the compliance ruleset, and **flags non-compliant elements before publication**.
- **Output:** a compliance check result attached to the report version (pass/flagged items), retained for audit.

---

# 11. Report & Output Specification

## 11.1 Report Types
List/tabular; grouped (group-above, group-left, multi-level subtotals/footers); matrix/pivot; summary/KPI; charts (bar/line/pie/area/combo); detail/drill-down; document-style (banded — invoice/statement/certificate); dynamic AI output.

## 11.2 Settings
Page size (A4/Letter/Legal/custom); orientation; output format default; security-to-open; URL-to-open; branding; page setup.

## 11.3 Deterministic PDF Layout
The AI emits **rigid Tailwind CSS layout** (not loose text). The renderer guarantees consistent pagination, tables, headers/footers and orientation — official-document-grade PDFs.

## 11.4 Formatting & Interactivity
Conditional formatting; subtotal/grand/running/percent-of-total; localization; interactive sort/filter/search/column toggle; runtime parameters; expand/collapse groups; per-user saved views.

---

# 12. API Specification (Report-as-API)

- **Style:** REST; JSON responses.
- **Endpoint:** each published report exposed as a callable endpoint with documented parameters.
- **Authentication:** per-endpoint **secret key**; RBAC scope enforced per key.
- **Documentation:** auto-generated per endpoint.
- **Controls:** rate limiting; key issue/rotate/revoke; full access logging.
- **Consumers:** external apps integrate report output as data.

---

# 13. Data Model (Key Entities — indicative)

| Entity | Purpose | Key fields (indicative) |
|---|---|---|
| `users`, `roles`, `permissions` | Identity & RBAC | id, role, scopes, clearance |
| `data_sources` | Registered connectors | id, type, config (encrypted), schema_cache |
| `report_definitions` | JSON source-of-truth | id, json, version, owner, status |
| `report_artifacts` (CRA) | Compiled artifacts | id, definition_id, version, signature, hash, env |
| `kb_documents` | Uploaded docs | id, type, version, tags, status |
| `kb_chunks` | Embeddings | id, doc_id, text, embedding (pgvector), clearance |
| `schema_semantics` | Term→schema cache | id, term, table, column, definition |
| `templates` | Reusable prompts/definitions | id, definition_id, prompt, output_meta |
| `api_keys` | Report-as-API auth | id, report_id, secret(hash), scope, rate_limit |
| `audit_log` | Audit trail | id, actor, action, object, ts, ip |
| `schedules` | Scheduled jobs | id, report_id, cron, recipients, channel |
| `data_marts` | Warehouse subsets | id, definition, refresh_policy |

---

# 14. Non-Functional Requirements (NFR)

| ID | Category | Requirement |
|---|---|---|
| NFR-1 | Performance | Interactive report renders within a few seconds on cached datasets; HNSW for low-latency retrieval. |
| NFR-2 | Scalability | Runtime Engine stateless & horizontally scalable; large datasets via materialized data marts. |
| NFR-3 | Availability | Standard HA (multiple Runtime nodes behind a load balancer). |
| NFR-4 | Security | Encryption at rest for credentials/CRA; signed artifacts; RBAC + zero-trust RAG; full audit. |
| NFR-5 | Sovereignty/Privacy | No data egress; in-country storage; on-premise inference; air-gap capable. |
| NFR-6 | Usability | No-code authoring; ≤5 minutes for a BA to change a report format. |
| NFR-7 | Portability | Runs on Linux/Windows; containerised; Dev/Staging/Prod parity. |
| NFR-8 | Maintainability | Modular (M1–M15); JSON source versioned; CRA registry with rollback. |
| NFR-9 | Accuracy | Multi-agent + double-check + semantic schema cache minimise hallucination & Text-to-SQL errors. |
| NFR-10 | Compliance | MPSA & WCAG/OKU automated pre-publish checks; audit-retained results. |
| NFR-11 | Observability | Usage analytics; processing/training status; error logging. |
| NFR-12 | Localization | Locale-aware number/date/currency; bilingual UI capable (BM/EN). |

---

# 15. Deployment & Environments

- **Engines:** Design-Time and Runtime deployed separately.
- **Environments:** Dev → Staging → Prod; CRAs promoted via the artifact registry.
- **OS / Infra:** Linux/Windows; containerised; self-hosted in Malaysian DC.
- **Pipeline:** build → migrate → deploy → promote CRA; rollback supported.
- **Sovereign/air-gap:** entire stack can run isolated.

---

# 16. Assumptions, Constraints & Risks

**Assumptions:** Malaysian DC with adequate GPU for Ollama; source systems reachable.
**Constraints:** all AI inference on-premise; report logic protected (CRA).
**Risks & Mitigations:** Hallucination/Text-to-SQL → Auditor + semantic cache + SQL guardrails; Vector perf at scale → HNSW + quantization + caching; Local LLM quality → capable open-weight models, swappable; IP exposure → encrypted/signed CRA; Scope creep → lock MVP 1.0.

---

# 17. Glossary

| Term | Meaning |
|---|---|
| **AIRR** | AI & RAG Reporting — the product. |
| **BA** | Business Analyst. |
| **CRA** | Compiled Report Artifact — opaque, signed, non-human-readable; runs only on Runtime Engine. |
| **Design-Time Engine** | Authoring + compilation engine (dev only). |
| **Runtime Engine** | Execution + serving engine (deployed). |
| **JSON Report Definition** | Human-readable, versioned source-of-truth for a report. |
| **RAG** | Retrieval-Augmented Generation. |
| **pgvector / pgai** | PostgreSQL extensions for vector search / in-database AI. |
| **HNSW** | Hierarchical Navigable Small World — fast vector index. |
| **Ollama** | On-premise local LLM serving. |
| **Hybrid Context Fusion** | Fusing unstructured (RAG) + structured (SQL) into one answer. |
| **Double-Check Guardrail** | Auditor agent verifying facts vs sources before publish. |
| **Semantic Schema Cache** | RAG-stored mapping of business terms to DB schema. |
| **MPSA** | Malaysian Government Portals and Websites Assessment. |
| **Zero-Trust RAG** | Role/clearance-filtered retrieval at the data layer. |
| **Data Mart** | Optimised data subset for fast reporting. |

---

*End of Technical Specification — AIRR MVP 1.0 (Standard Edition).*
*Contact · airr.technology*
