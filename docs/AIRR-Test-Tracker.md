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
| `tests/Feature/ModelResolverTest.php` | FR-M15.8/M14.5 — default, override, fallback, edition gate, Setting layering, task guard | 6 | ✅ | 2026-06-24 |
| `tests/Feature/AiConfigApiTest.php` | FR-M14.6/M14.5 — index, update, health, permission gate, project ai_config | 5 | ✅ | 2026-06-24 |

## Belum ada test (hutang test — tambah bila disentuh)

| Modul | FR | Catatan |
|---|---|---|
| M2 Data Source | FR-M2.1–2.5,2.8 | Connectors, datasets, file ingestion, schema cache — **belum ada test** |
| M1 Auth/RBAC | FR-M1.1,1.2,1.5 | Login, OAuth, RBAC, audit — **belum ada test** |
| M14 Users/Roles/Projects | FR-M14.3,14.4,14.x | CRUD + scoping — **belum ada test** |
| M3 ingestion penuh | FR-M3.3,3.5,3.6,3.8,3.10 | 🔒 tunggu Ollama + pgvector |

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

_Last update: 2026-06-24._
