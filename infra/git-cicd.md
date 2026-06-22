# AIRR — Git & CI/CD Architecture

Sovereign-first, open-core. **GitLab CE** is the primary (self-hosted, in-country);
the Community Edition is push-mirrored to **GitHub** + **Codeberg** for the open-source community.

```
        GitLab CE (self-host, DC Malaysia)  ── PRIMARY (sovereign)
        git · CI/CD · container registry · environments
               │  push-mirror (auto, read-only)
        ┌──────┴───────┐
        ▼              ▼
     GitHub        Codeberg            ── Community Edition (AGPL-3.0), public
```

| AIRR DevOps menu | GitLab CE feature |
|---|---|
| 🚀 Releases | Pipelines (`.gitlab-ci.yml`), triggered via API/webhook |
| 🌐 Environments | GitLab Environments (Dev/Staging/Prod) + deploy jobs |
| 📦 CRA / images | Container & Package Registry |
| 💾 Backups | `gitlab-backup` + `pg_dump` (cron) |

---

## 1. Install GitLab CE (Omnibus) — on a Linux server (NOT the dev Mac)

> GitLab CE needs ~4–8 GB RAM. Run it on the DC/staging Linux box, not locally.

```bash
# Ubuntu/Debian
curl -s https://packages.gitlab.com/install/repositories/gitlab/gitlab-ce/script.deb.sh | sudo bash
sudo EXTERNAL_URL="https://git.airr.local" apt-get install gitlab-ce
sudo gitlab-ctl reconfigure
```

Air-gap: download the `.deb`/`.rpm` offline package, copy into the DC, install without internet.

## 2. Create the project & push the existing repo

```bash
# in ~/Documents/AIRR
git remote add origin https://git.airr.local/airr/airr.git
git push -u origin main
```

## 3. Push-mirror to GitHub + Codeberg (Community Edition)

GitLab → Project → **Settings → Repository → Mirroring repositories**:
- Add `https://github.com/<org>/airr.git`  (Push, read-only mirror)
- Add `https://codeberg.org/<org>/airr.git`
- Auth: use a **Personal Access Token** from each host (scope: repo/write).

Now every push to GitLab auto-mirrors to both public hosts. Community CI runs via
`.github/workflows/ci.yml` (already in repo); sovereign CI runs via `.gitlab-ci.yml`.

## 4. Register a CI runner (for GitLab pipelines)

```bash
sudo gitlab-runner register \
  --url https://git.airr.local \
  --registration-token <PROJECT_TOKEN> \
  --executor docker \
  --docker-image alpine:3
```

## 5. CI/CD variables to set in GitLab (Settings → CI/CD → Variables)

| Variable | Purpose |
|---|---|
| `CI_REGISTRY_*` | auto-provided by GitLab registry |
| `DEPLOY_HOST` / SSH key | target host for staging/production deploy |
| `AIRR_EDITION` | edition baked into the build (community/standard/enterprise) |

## 6. Branch & release strategy

- `main` — always deployable; protected; merge via MR only.
- `develop` — integration branch.
- `feature/*` — short-lived; MR into `develop`.
- Release = annotated **tag** `vX.Y.Z` on `main` → triggers `build:images` + manual `deploy:production`.
- Staging deploys automatically (manual gate) from `main`; production only from a tag, manual.

## 7. Backups (DevOps "Backups & Restore")

**Physical (ops, scheduled):**
```bash
# nightly cron
gitlab-backup create                       # GitLab itself
pg_dump -Fc airr > /backups/airr_$(date +%F).dump   # AIRR database
```
**Logical (in-app):** AIRR "Export / Import" produces a portable per-project JSON bundle
(report definitions, KB, templates, settings) — version-friendly, restorable into any environment.

---

## What still needs your action
1. Provision a Linux server for GitLab CE (DC Malaysia).
2. Create the public repos: `github.com/<org>/airr` and `codeberg.org/<org>/airr`.
3. Generate mirror PATs and add them in GitLab mirroring settings.

Everything else (pipelines, workflows, Dockerfiles, compose) is already in this repo and ready.
