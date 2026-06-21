# infra/ — Deployment & Infrastructure (M15)

Placeholders for Phase 0/5. To be filled in:

- `docker-compose.yml` — postgres (pgvector+pgai) · backend · frontend · rag_api · ollama
- `Dockerfile` per service
- CI/CD pipeline (build → migrate → deploy → promote CRA; rollback)
- Environment configs: Dev / Staging / Prod (sovereign, air-gap capable)

## Sovereign constraint
The entire stack (PostgreSQL + pgvector + pgai + rag_api + Ollama + engines) must be able to
run isolated inside a Malaysian data center with **zero external AI egress**.

## Current local (native, no Docker yet)
| Service | Port | How |
|---|---|---|
| PostgreSQL 17 | 5432 | `brew services start postgresql@17` (db `airr`) |
| Backend (Laravel) | 9000 | `php artisan serve --port=9000` |
| Frontend (Vite) | 9100 | `npm run dev` |
| rag_api (FastAPI) | 8001 | `~/rag-stack/rag_api` venv + `python main.py` |
| Ollama | 11434 | not yet installed |
