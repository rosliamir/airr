# CLAUDE.md — AIRR (AI & RAG Reporting)

> Project context for Claude Code / in-repo AI. Read this first. Full detail lives in `docs/`.

## What we are building
**AIRR — AI & RAG Reporting** — *Thin & light, but powerful.* A future-proof, AI-native reporting platform that replaces static reporting (Jasper/Oracle) with **living, context-aware reports**. It fuses **structured data (SQL)** with **unstructured knowledge (RAG)**, reasons with a **multi-agent** pipeline, and is **sovereign by design** (self-hosted, on-premise LLM).

Building **MVP 1.0 (Standard Edition)**.

## Source-of-truth documents (read these for any task)
- `docs/AIRR-MVP1.0-Technical-Specification.md` — **the spec**: functional requirements per module (FR-Mx.n), AI/RAG spec, security, compliance, API, data model, NFRs.
- `docs/AIRR-MVP1.0-Technical-Product-Roadmap.md` — features (L1/L2), functions/sub-functions, the 15 modules, PlantUML schematics, and the 6-phase build plan.
- `docs/AIRR-Pricing.md` — editions & **feature gating** (Community/Standard/Enterprise).
- `docs/diagrams/` — architecture, pipeline, RAG flow, multi-agent, evolution.
- `docs/AIRR-brand.md` — brand, logo, colours.

## Tech stack (use these exact choices)
| Layer | Tech |
|---|---|
| Frontend / Studio | **Vue + Tailwind CSS** |
| Backend / API | **Laravel (PHP)** |
| Database | **PostgreSQL 16 / 17** |
| Vector | **pgvector v0.7.x / v0.8.x** (HNSW) |
| In-DB AI | **pgai v0.12.x+** |
| RAG service | **rag_api** — FastAPI / **Python 3.10–3.12** |
| Local LLM | **Ollama** (on-premise; NO external AI APIs) |
| Infra | Docker; Linux/Windows; Dev/Staging/Prod |

## Non-negotiable constraints
1. **On-premise / sovereign only** — never call external/third-party AI APIs. All inference via local Ollama. No data egress.
2. **Design-Time vs Runtime are separate engines.** Authoring is 100% web-based (zero-install).
3. **Report source = human-readable JSON.** On publish it compiles to a **CRA** (Compiled Report Artifact): opaque, signed, encrypted, non-human-readable; only the Runtime Engine executes it.
4. **Deterministic rendering** — Tailwind→HTML→PDF must be reproducible/print-stable.
5. **Security at the data layer** — RBAC + row/column + **zero-trust RAG** filtering inside PostgreSQL; full audit trail.
6. **Accuracy** — multi-agent with a **double-check (Auditor) guardrail**; **semantic schema cache** to prevent Text-to-SQL errors.

## Modules (15, in 5 groups)
- **G1 Foundation & Platform:** M1 Core Platform & Security · M2 Data Source Connector · M15 Deployment & Infra
- **G2 Intelligence:** M3 Knowledge Base/RAG · M4 AI Orchestration (Multi-Agent)
- **G3 Authoring & Engine:** M5 Design-Time Studio · M6 Report Engine & Definition · M7 Compiler & Artifact · M8 Runtime Engine
- **G4 Experience & Delivery:** M9 Interactive Chat · M10 Output & Export · M11 API Delivery · M12 Scheduler
- **G5 Data & Governance:** M13 Mini Data Warehouse · M14 Admin & Governance

## Edition feature-gating (open-core)
Build features behind an **edition flag** (`community` | `standard` | `enterprise`).
- **Community (AGPL-3.0):** core reporting, 1 data source, 1 RAG KB, single-prompt NL builder, basic RBAC, runs from JSON (no CRA), 1 API endpoint.
- **Standard:** + multi-agent + double-check, hybrid fusion, interactive chat, CRA compile, all connectors, full RBAC, MPSA checks, 25 API endpoints.
- **Enterprise:** + zero-trust RAG, multi-tenant, dedicated/tuned LLM, air-gap certified, unlimited API, source escrow.
Keep the gating in one place (config/policy layer) so the same codebase serves all editions.

## Repo structure (monorepo)
```
/frontend        # Vue + Tailwind (Design-Time Studio + Report Viewer)
/backend         # Laravel (API gateway, auth, RBAC, audit, orchestration controller)
/engines
  /design-time   # compiler: JSON -> CRA (sign/encrypt)
  /runtime       # CRA loader/verifier + executor + renderers
/services
  /rag_api       # FastAPI (chunking, embeddings via pgai, retrieval, agents)
/db              # migrations, pgvector/pgai setup
/infra           # docker, compose, CI/CD, env config
/docs            # the markdown specs + diagrams
```

## Build order (roadmap phases)
- **Phase 0 — Foundation:** repo scaffold (Vue+Laravel+Postgres), pgvector+pgai, rag_api skeleton, Ollama provisioned, Docker + CI/CD, **M1 Auth/RBAC/Audit**, edition-flag config.
- **Phase 1 — Core Reporting:** M2 connectors, M6 engine, M5 studio basics, M10 PDF/Excel export.
- **Phase 2 — KB & RAG:** M3 ingestion, pgai embeddings → pgvector, semantic schema cache.
- **Phase 3 — AI Orchestration:** M4 multi-agent (Retriever→Analyst→Auditor→Writer) + guardrails, hybrid fusion, narration, chart/drill-down/document types.
- **Phase 4 — Compile/Runtime/API/Chat:** M7 CRA compiler, M8 runtime, M9 chat, M11 Report-as-API.
- **Phase 5 — Warehouse/Governance/Hardening:** M13, M12, M14, data masking, security review.

## Conventions / Definition of Done
- Each feature: tests pass · RBAC + audit applied · respects edition gating · concise docs · demoable on Staging.
- Validate AI-generated SQL against schema + permissions **before** execution (guardrail).
- Every report answer must be **grounded/citable** to its source.
- Prefer config over hard-coding (editions, environments, data-residency region).

## Brand quick-ref
- Name: **AIRR — AI & RAG Reporting**; motto: *"Thin & light, but powerful."*
- Colours: crimson gradient `#FB7185 → #E11D48 → #9F1239`; logo = spark (generation) + 2 nodes (retrieval = data + documents).
- Web: airr.technology.

## Local dev environment (this machine — already provisioned)
- PostgreSQL 17 (Homebrew) running as a service; pgvector 0.8.3 + pgai 0.12.1 installed. See user memory `rag-stack-local`.
- rag_api already cloned & runnable at `~/rag-stack/rag_api` (port 8001), linked into `services/rag_api`.
- Ollama: NOT yet installed locally — provision before Phase 3.
