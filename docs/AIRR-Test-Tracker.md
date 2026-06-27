# AIRR — Test Tracker

> Setiap feature yang siap WAJIB ada test. Tracker ini jejak liputan test per-FR.
> Status: ✅ lulus · 🟡 sebahagian · ⬜ belum · ❌ gagal
>
> Jalankan: `cd backend && php artisan test`

---

## Backend (PHPUnit — sqlite :memory:)

| Test file | Liputan (FR) | Bilangan | Status | Tarikh |
|---|---|---|---|---|
| `tests/Unit/DocumentExtractorTest.php` | FR-M3.2 — detect type, markdown, docx, guard | 4 | ✅ | 2026-06-24 |
| `tests/Unit/FileParserTest.php` | FR-M2.3 — csv (header/no-header/delimiter), json (list/envelope), limit, guard | 6 | ✅ | 2026-06-24 |
| `tests/Feature/ModelResolverTest.php` | FR-M15.8/M14.5 — default, override, fallback, edition gate, Setting layering, task guard | 6 | ✅ | 2026-06-24 |
| `tests/Feature/AiConfigApiTest.php` | FR-M14.6/M14.5 — index, update, health, permission gate, project ai_config | 5 | ✅ | 2026-06-24 |
| `tests/Feature/DataSourceTest.php` | FR-M2.8/M2.3/M2.4 — encryption-at-rest, masking, validation, file upload+schema, permission gate | 6 | ✅ | 2026-06-24 |
| `tests/Feature/ConnectorServiceTest.php` | FR-M2.5 — SELECT guardrail, multi-statement guard, file dataset param filter | 3 | ✅ | 2026-06-24 |
| `tests/Unit/ChunkerServiceTest.php` | FR-M3.3 — heading split, single chunk, oversized split, paragraph packing, empty | 5 | ✅ | 2026-06-25 |
| `tests/Feature/KnowledgeBaseTest.php` | FR-M3.1/M3.2/M3.2a/M3.2b/M3.6/M3.9 — CRUD, gate, ingest→chunks, category, db_schema & rbac, **version history+log**, unsupported, reindex, cascade | 12 | ✅ | 2026-06-27 |
| `tests/Feature/AuthTest.php` | FR-M1.1 — login, bad creds, pending gate, self-register, me auth/payload | 6 | ✅ | 2026-06-25 |
| `tests/Feature/AdminCrudTest.php` | FR-M14.3/M14.x — project CRUD, owner scoping, user create, RBAC gating | 5 | ✅ | 2026-06-25 |
| `tests/Feature/ReportTest.php` | FR-M6.1/M6.2/M6.3 + **ACL** — report CRUD, render table/grouped/KPI, **per-report role ACL (private-by-default, view/run grant, creator access, store grants)** | 11 | ✅ | 2026-06-27 |
| `tests/Feature/MenuTest.php` | M14 DB-driven nav — permission-filtered nav, empty-group hidden, CRUD gate, create | 4 | ✅ | 2026-06-27 |
| `tests/Feature/FeatureBoardTest.php` | Dashboard readiness — merge facts+status, permission gate, toggle, summary | 4 | ✅ | 2026-06-27 |

## Belum ada test (hutang test — tambah bila disentuh)

| Modul | FR | Catatan |
|---|---|---|
| M2 DB/API connectors (live) | FR-M2.1,2.2 | `test()`/`introspect()` atas DB sebenar — perlu instance; guard + file path sudah ditest |
| M1 OAuth + password reset | FR-M1.1 | Google OAuth + reset flow — login/register/me sudah ditest |
| M14 Roles/Groups CRUD | FR-M14.4a | Projects + users ditest; roles/groups CRUD belum |
| M3 embedding + retrieval | FR-M3.5,3.8,3.10 | 🔒 tunggu Ollama + pgvector (chunking + ingest sudah ditest) |

## Frontend
| Area | Status | Catatan |
|---|---|---|
| Type-check / build (`npm run build`) | ✅ | Lulus (vue-tsc, exit 0) |
| Unit (Vitest) | ⬜ | Belum disetup |

---

### Polisi
1. **Definition of Done** termasuk test lulus (lihat CLAUDE.md).
2. Setiap commit feature baru → tambah/kemaskini test + tracker ini.
3. Hutang test di atas dibayar bila modul berkenaan disentuh semula.

_Last update: 2026-06-24 (M2 test debt paid)._
