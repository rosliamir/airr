# services/ — AI/RAG microservices

## rag_api (FastAPI / Python 3.10–3.12)
Chunking, pgai embeddings, pgvector retrieval, multi-agent orchestration.

For local dev this reuses the already-provisioned instance at `~/rag-stack/rag_api`
(see user setup). When we containerise (Phase 0/5) it will be vendored here or pulled
as a submodule, configured against the `airr` database instead of `rag_db`.

Run locally:
```bash
cd ~/rag-stack/rag_api && source .venv/bin/activate && python main.py   # :8001
```
