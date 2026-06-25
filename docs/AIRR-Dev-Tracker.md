# AIRR — Dev Tracker (Master Backlog)

> Senarai induk **semua** yang akan dibangunkan untuk MVP 1.0, ikut requirement `FR-Mx.n` dalam spec (`AIRR-MVP1.0-Technical-Specification.md` §7).
> Tanda `[x]` bila siap, isi **Tarikh** & **Masa**. Susunan ikut roadmap phase + kebergantungan.
>
> Status: ✅ siap · 🟡 sedang buat · ⬜ belum · 🔒 blocked (tunggu prasyarat)

---

## PHASE 0 — Foundation (M1, M15) + Governance asas (M14)

### M1 — Core Platform & Security
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [x] | FR-M1.1 | Auth: login, logout, session/token, **password reset** (+ self-register & Google OAuth) | ✅ | 2026-06-22 | 10:30 |
| [x] | FR-M1.2 | RBAC: roles + granular permissions (view/create/run/export/admin) — *backend* | ✅ | 2026-06-22 | — |
| [ ] | FR-M1.3 | Row-level & column-level security per role | ⬜ | | |
| [ ] | FR-M1.4 | API secret keys (issue, rotate, revoke) per-role scope | ⬜ | | |
| [x] | FR-M1.5 | Immutable audit trail (actor/action/object/ts) + export | ✅ | 2026-06-22 | — |
| [ ] | FR-M1.6 | Data masking untuk kolum sensitif | ⬜ | | |
| [ ] | FR-M1.7 | Verify CRA signature/integrity sebelum execute | 🔒 | | |

### M15 — Deployment & Infrastructure
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [x] | FR-M15.1 | Design-Time & Runtime engine boleh deploy berasingan (scaffold) | ✅ | 2026-06-22 | — |
| [x] | FR-M15.2 | Pembangunan 100% web-based (no local install) | ✅ | 2026-06-22 | — |
| [ ] | FR-M15.3 | Self-hosted Dev/Staging/Prod (Linux + Windows) | ⬜ | | |
| [x] | FR-M15.4 | Containerised pipeline (build/migrate/promote) — *CI asas* | ✅ | 2026-06-22 | — |
| [ ] | FR-M15.5 | Deploy penuh dalam Malaysian DC + air-gap capable | ⬜ | | |
| [x] | FR-M15.6 | **AI Provider abstraction** (`AiProvider` interface; `OllamaProvider` impl) + **system-default** model config per-task (config/ai.php) — config, bukan hard-code | ✅ | 2026-06-24 | |
| [x] | FR-M15.7 | **Model management** — `listModels()/pull()/health()` di provider (UI tab kemudian) | ✅🟡 | 2026-06-24 | |
| [x] | FR-M15.8 | **`ModelResolver::resolve(task, project)`** — project override (edition-gated) → fallback system default; satu titik untuk M3/M4 | ✅ | 2026-06-24 | |

### M14 — Governance (item Phase 0)
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [x] | FR-M14.4a | **Users & Roles** — tabbed UI (Users/Roles/Groups), full CRUD, user_type, approve/suspend, English-only | ✅ | 2026-06-22 | 12:10 |
| [x] | FR-M14.3 | Owner+group visibility scoping (admins exempt); avatar upload (local) | ✅ | 2026-06-22 | 17:28 |
| [x] | FR-M14.x | **Projects** — CRUD + assign users/groups + scoping (reports stored by project) | ✅ | 2026-06-22 | 17:28 |
| [x] | FR-M1.5+ | Audit log — searchable + paginated 10/page + expandable detail | ✅ | 2026-06-22 | 17:28 |
| [x] | FR-M14.4b | **Settings** — Regional (editable), Subscription (limits/features), About | ✅ | 2026-06-22 | 19:10 |
| [x] | FR-M14.5 | **Per-project AI config** — projek pilih model **per-task** (UI dalam Project form, gated `project_ai_config`); kosong = warisi default. `projects.ai_config` JSONB + validasi controller | ✅ | 2026-06-24 | |
| [x] | FR-M14.6 | **Settings → AI/Models tab** — admin set system-default (Setting `ai`) + health-check provider + senarai model | ✅ | 2026-06-24 | |
| [ ] | — | Commit kerja auth + seeder ke git | ⬜ | | |

---

## PHASE 1 — Core Reporting (M2, M6, M10)

### M2 — Data Source Connector
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [x] | FR-M2.1 | Connect PostgreSQL + MySQL + Oracle (all tested real); SQL Server scaffolded | ✅ | 2026-06-23 | |
| [x] | FR-M2.2 | Connect REST API (auth bearer/header); GraphQL scaffolded | ✅🟡 | 2026-06-23 | 10:39 |
| [x] | FR-M2.3 | Ingest CSV / Excel / JSON files (upload, parse, preview, param-filter) | ✅ | 2026-06-23 | |
| [x] | FR-M2.4 | Introspect schema (table/column/type) + cache (schema_cache) | ✅ | 2026-06-23 | 10:39 |
| [x] | FR-M2.5 | Datasets: SQL/API + runtime params + preview (bound, SELECT-guarded) | ✅ | 2026-06-23 | 10:39 |
| [ ] | FR-M2.6 | RAG collection sebagai data source | ⬜ | | |
| [ ] | FR-M2.7 | Cache result set (TTL + invalidation) | ⬜ | | |
| [x] | FR-M2.8 | Credentials encrypted-at-rest (encrypted:array cast) | ✅ | 2026-06-23 | 10:39 |

### M6 — Report Engine & Definition
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [ ] | FR-M6.1 | Report = JSON definition portable (fields/groups/filters/sorts/aggregates/AI hints) | ⬜ | | |
| [ ] | FR-M6.2 | Render: list, grouped (above/left), matrix/pivot, KPI, chart, drill-down, doc-style, AI output | ⬜ | | |
| [ ] | FR-M6.3 | Multi-level grouping + subtotal/grand/running/percent | ⬜ | | |
| [ ] | FR-M6.4 | Conditional formatting (color/icon/data-bar by rule) | ⬜ | | |
| [ ] | FR-M6.5 | Prompt runtime params (date picker, dropdown) | ⬜ | | |

### M10 — Output & Export
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [ ] | FR-M10.1 | Deterministic Tailwind→HTML→PDF (print-stable) | ⬜ | | |
| [ ] | FR-M10.2 | Export Excel / CSV / JSON / HTML | ⬜ | | |
| [ ] | FR-M10.3 | Page size/orientation/header-footer/page-no/branding | ⬜ | | |
| [ ] | FR-M10.4 | Security-to-open (password/access control) | ⬜ | | |
| [ ] | FR-M10.5 | Shareable URL berkawalan kebenaran | ⬜ | | |

---

## PHASE 2 — Knowledge Base & RAG (M3)

### M3 — Knowledge Base / RAG
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [x] | FR-M3.1 | "Train the Database" UI — KB CRUD + upload reference docs (KnowledgeBaseView) | ✅ | 2026-06-25 | |
| [x] | FR-M3.2 | Ingest PDF / DOCX / XLSX / Markdown auto (DocumentExtractor) | ✅ | 2026-06-25 | |
| [x] | FR-M3.2a | **Document category taxonomy** — URS/SRS/SDS/Test Script/UAT/User Manual/Helpdesk/Other (config/kb.php), dipilih masa upload + papar dalam KB | ✅ | 2026-06-25 | |
| [x] | FR-M3.2b | **System-knowledge ingest** — pipeline siap (SystemKnowledgeService + ingestText); **DB Schema + RBAC** done. Baki (ERD/data-dictionary/glossary/business-logic/UI/menu/API) tambah berperingkat | ✅🟡 | 2026-06-25 | |
| [ ] | FR-M3.2c | **Help Desk integration** — connector Generic REST (base URL+auth) tarik tiket → KB (source_kind=integration) + UI | 🔒 KIV→Fasa 3 | | |
| [x] | FR-M3.3 | Structure-aware chunking (ChunkerService — heading/paragraph, bukan fixed count) | ✅ | 2026-06-25 | |
| [ ] | FR-M3.4 | Tukar flowchart/diagram/table → descriptive text sebelum embed | ⬜ | | |
| [ ] | FR-M3.5 | Embeddings via pgai → simpan pgvector (HNSW) | ⬜ | | |
| [x] | FR-M3.6 | Status ingestion (queued/processing/trained/error) + log error (IngestionService) | ✅ | 2026-06-25 | |
| [ ] | FR-M3.7 | **Semantic schema cache** (term bisnes → table/column) | ⬜ | | |
| [ ] | FR-M3.8 | Semantic retrieval (similarity search) untuk konteks prompt | ⬜ | | |
| [ ] | FR-M3.9 | KB versioning, tagging, re-index, delete | ⬜ | | |
| [ ] | FR-M3.10 | **Zero-trust access filtering** ikut role/clearance | ⬜ | | |

---

## ⚠️ INFRA GATE — prasyarat Phase 3 (wajib siap dulu)
| ✓ | Tugas | Status | Tarikh | Masa |
|---|---|---|---|---|
| [ ] | Pasang Ollama (LLM tempatan, on-prem) + pull model (embedding + generation) | 🔒 | | |
| [x] | Implement `AiProvider` abstraction + `resolveModel()` (FR-M15.6/M15.8) | ✅ | 2026-06-24 | |
| [ ] | Load pgai extension dalam DB `airr` | 🔒 | | |
| [ ] | Sambung rag_api → `services/rag_api` + run skeleton | 🔒 | | |

---

## PHASE 3 — Agentic AI (M4, M5)

### M4 — AI Orchestration (Multi-Agent)
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [ ] | FR-M4.1 | Terima prompt single & hybrid (structured + unstructured) | 🔒 | | |
| [ ] | FR-M4.2 | Resolve intent + map term → schema (semantic cache) | 🔒 | | |
| [ ] | FR-M4.3 | **Retriever agent** — fetch passages via pgvector | 🔒 | | |
| [ ] | FR-M4.4 | **Data-Analyst agent** — generate + execute SQL | 🔒 | | |
| [ ] | FR-M4.5 | **Auditor agent (Double-Check)** — verify fakta vs PG & RAG | 🔒 | | |
| [ ] | FR-M4.6 | **Writer/Designer agent** — compose HTML/Tailwind | 🔒 | | |
| [ ] | FR-M4.7 | Hybrid Context Fusion (gabung text + numeric) | 🔒 | | |
| [ ] | FR-M4.8 | Auto executive-summary narration | 🔒 | | |
| [ ] | FR-M4.9 | SQL guardrail — validate vs schema + permission sebelum execute | 🔒 | | |
| [ ] | FR-M4.10 | **Compliance agent** — semak draft vs MPSA sebelum publish | 🔒 | | |
| [ ] | FR-M4.11 | Semua inference lokal via Ollama (no external API) | 🔒 | | |

### M5 — Design-Time Studio (Web IDE)
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [ ] | FR-M5.1 | WYSIWYG drag-drop canvas (bands/elements/data binding) | ⬜ | | |
| [ ] | FR-M5.2 | Property Inspector (binding/style/format/expression/visibility) | ⬜ | | |
| [ ] | FR-M5.3 | Live Preview (sample atau live data) | ⬜ | | |
| [ ] | FR-M5.4 | Unlimited multi-step Undo/Redo (command stack) | ⬜ | | |
| [ ] | FR-M5.5 | Expose & edit canonical JSON source | ⬜ | | |
| [ ] | FR-M5.6 | Lint binding/expression + report error | ⬜ | | |
| [ ] | FR-M5.7 | Prompt-driven authoring (NL → definition) | 🔒 | | |

---

## PHASE 4 — Compile / Runtime / API / Chat (M7, M8, M9, M11)

### M7 — Compiler & Artifact Service
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [ ] | FR-M7.1 | Transform JSON source → CRA (intermediate rep) | ⬜ | | |
| [ ] | FR-M7.2 | CRA encrypted + signed + integrity-hash (non-human-readable) | ⬜ | | |
| [ ] | FR-M7.3 | Artifact registry + versioning | ⬜ | | |
| [ ] | FR-M7.4 | Publish/promote CRA → Dev/Staging/Prod | ⬜ | | |
| [ ] | FR-M7.5 | Rollback ke versi CRA sebelumnya | ⬜ | | |

### M8 — Runtime Engine
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [ ] | FR-M8.1 | Load + verify CRA (signature/integrity) sebelum execute | ⬜ | | |
| [ ] | FR-M8.2 | Deserialize + execute CRA online | ⬜ | | |
| [ ] | FR-M8.3 | Bind live data + apply runtime params + security policy | ⬜ | | |
| [ ] | FR-M8.4 | Render output ikut channel (view/PDF/Excel/API) | ⬜ | | |
| [ ] | FR-M8.5 | Stateless & horizontally scalable | ⬜ | | |

### M9 — Interactive Report Chat
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [ ] | FR-M9.1 | Select/highlight mana-mana paragraph/chart dalam report | 🔒 | | |
| [ ] | FR-M9.2 | Jawab soalan susulan tentang elemen dipilih | 🔒 | | |
| [ ] | FR-M9.3 | Mutate report dinamik (tukar chart/filter/scope) | 🔒 | | |
| [ ] | FR-M9.4 | Kekal konteks perbualan untuk sesi report | 🔒 | | |

### M11 — API Delivery
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [ ] | FR-M11.1 | Expose report sebagai REST endpoint | ⬜ | | |
| [ ] | FR-M11.2 | Setiap endpoint perlu secret key + RBAC scope | ⬜ | | |
| [ ] | FR-M11.3 | Auto-generate API docs (endpoint/param/sample) | ⬜ | | |
| [ ] | FR-M11.4 | Rate limiting + key revocation | ⬜ | | |
| [ ] | FR-M11.5 | Log setiap API call untuk audit | ⬜ | | |

---

## PHASE 5 — Warehouse / Scheduler / Governance / Hardening (M12, M13, M14)

### M12 — Scheduler & Delivery
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [ ] | FR-M12.1 | Schedule report (cron) + pilih recipient | ⬜ | | |
| [ ] | FR-M12.2 | Deliver via email + webhook | ⬜ | | |
| [ ] | FR-M12.3 | Refresh materialized dataset ikut jadual | ⬜ | | |

### M13 — Mini Data Warehouse
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [ ] | FR-M13.1 | Define data mart (subset/star schema) | ⬜ | | |
| [ ] | FR-M13.2 | Materialize + refresh cached dataset (data besar) | ⬜ | | |
| [ ] | FR-M13.3 | Embeddable analytics block untuk app lain | ⬜ | | |

### M14 — Administration & Governance (baki)
| ✓ | FR | Requirement | Status | Tarikh | Masa |
|---|---|---|---|---|---|
| [ ] | FR-M14.1 | Usage analytics on reports | ⬜ | | |
| [ ] | FR-M14.2 | Template & version governance (approval/publish workflow) | ⬜ | | |
| [ ] | FR-M14.3 | Baseline multi-tenant isolation | ⬜ | | |

### Hardening akhir
| ✓ | Tugas | Status | Tarikh | Masa |
|---|---|---|---|---|
| [ ] | Data masking + zero-trust RAG hardening | ⬜ | | |
| [ ] | Compliance MPSA / WCAG-OKU automated checks | ⬜ | | |
| [ ] | Security review penuh | ⬜ | | |

---

### Ringkasan progress
- **Phase 0:** 8 / 15 FR siap (auth, RBAC asas, audit, scaffold). Baki: M1.3/M1.4/M1.6/M1.7, Users&Roles, Settings.
- **Phase 1–5:** belum mula.
- **Seterusnya:** Users & Roles (M14.4a) untuk tutup gate approval.

_Tracker dikemaskini sepanjang dev. Last update: 2026-06-22 10:48._
