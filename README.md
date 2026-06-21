# AIRR — AI & RAG Reporting

> *Thin & light, but powerful.* A sovereign, AI-native reporting platform that fuses
> structured data (SQL) with unstructured knowledge (RAG), reasons with a multi-agent
> pipeline, and runs fully on-premise. **MVP 1.0 (Standard Edition).**

See [`CLAUDE.md`](CLAUDE.md) and [`docs/`](docs/) for the full spec & roadmap.

## Monorepo layout
```
/frontend   Vue 3 + TS + Tailwind v4 (Design-Time Studio + Viewer)   → dev :9100
/backend    Laravel 13 + Sanctum (API, auth, RBAC, audit, edition)   → dev :9000
/engines    design-time (JSON→CRA compiler) · runtime (CRA executor) — Phase 4
/services   rag_api (FastAPI; chunking, pgai embeddings, retrieval)  → dev :8001
/db         migrations & pgvector/pgai setup notes
/infra      docker / compose / CI-CD (placeholders)
/docs       specs + diagrams
```

## Status — Phase 0 (Foundation) in progress
| Piece | State |
|---|---|
| PostgreSQL 17 + pgvector 0.8.3 + pgai 0.12.1 (`airr` DB) | ✅ provisioned |
| Backend M1: auth (Sanctum), RBAC, audit trail | ✅ working & smoke-tested |
| Edition feature-gating (`community`/`standard`/`enterprise`) | ✅ config + middleware |
| Frontend shell: login + dashboard (health/edition/modules) | ✅ builds & runs |
| rag_api service | ✅ reused from `~/rag-stack` (linked) |
| Ollama, Docker compose, CI, engines | ⏳ pending |

## Local development

Prereqs (already installed on this machine): PostgreSQL 17 (brew service), PHP 8.x + Composer, Node 22, Python 3.12.

```bash
# 1. Database (one-time; already done locally)
#    role airr / db airr with vector + pgai extensions

# 2. Backend  (http://localhost:9000)
cd backend
composer install
php artisan migrate --seed     # seeds roles, permissions, admin user
php artisan serve --port=9000

# 3. Frontend (http://localhost:9100)
cd frontend
npm install
npm run dev

# 4. rag_api  (http://localhost:8001) — optional until Phase 2
cd ../rag-stack/rag_api && source .venv/bin/activate && python main.py
```

### Default login
`admin@airr.technology` / `airr12345` (override via `AIRR_ADMIN_EMAIL` / `AIRR_ADMIN_PASSWORD`).

## Key endpoints (M1)
| Method | Path | Notes |
|---|---|---|
| GET  | `/api/health` | public; reports edition, features, pgvector/pgai status |
| POST | `/api/auth/login` | returns Sanctum token + user payload |
| GET  | `/api/auth/me` | current user, roles, permissions, edition features |
| POST | `/api/auth/logout` | revoke current token |
| GET  | `/api/audit` | audit trail (requires `audit.read`) |

## Conventions
- API envelope: `{ data, meta }` on success, `{ error: { code, message, details } }` on failure.
- Gate RBAC with `->middleware('permission:reports.view')`; gate editions with `->middleware('feature:multi_agent')`.
- All AI inference is local (Ollama) — **never** call external AI APIs (sovereign by design).
