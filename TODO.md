# Phantasos Modernization — TODO

Shared task list for the migration. See `AI.md` for architecture/context.
Status keys: `[ ]` todo · `[~]` in progress · `[x]` done · `[?]` needs decision/input.

---

## Phase 0 — Discovery & safety net (do first)

- [~] **Confirm prod versions**: MongoDB = **3.0.2** ✅. PHP version on phantasos host — still TODO
      (`php -v` on the server).
- [x] **Measure the footprint**: disk = **748 G / 5 T (15%)**; Mongo = ~60 MB; media=111,129,
      mediaFile=93,876, apiUsers=15. (See `migration/inspect_db.py` + AI.md §5 inventory.)
- [x] **List all apps**: 15 apiUsers, 13 with media; ORSkills/SubDocs/NurseDocsDev ≈ 99%.
      (See AI.md §5 inventory.)
- [ ] **Full backup before any change**: `mongodump` of `phantasos` db + a verified copy/snapshot
      of `/media/phantasos`. Store off the dying SAN. **Required before any delete.**
- [ ] **Stand up a non-prod sandbox** to test the upgrade against (copy of db + a media sample).
- [x] **DB hosting — SUPERSEDED**: re-platforming Mongo → Percona PostgreSQL (existing PG infra),
      so the "dedicated Mongo VM vs k8s" question is moot. See AI.md §12.
- [ ] **(New) Orphan-media cleanup** (separate from app purge): 31,109 media with `mediaType: None`
      = abandoned upload tickets. Quantify on-disk footprint and purge after backup.
- [?] **Decide new storage backend** for `/media/phantasos` (NFS/Ceph vs object storage; keep
      hashed-dir layout vs re-architect via Gaufrette/S3). (AI.md §10.)

## Phase 1 — Decommissioned-app data purge (early win, shrinks migration)

- [x] **Confirm the exact `applicationName`**: **`ORSkills`** (privacy wipe). Leave SubDocs +
      NurseDocsDev. 46,563 media / 49,502 mediaFile docs.
- [x] **Dry-run manifest generator** (`migration/purge_app.py`): manifests written to
      `manifest_ORSkills/` (media_ids 46,563 · mediafile_ids 49,502 · leaf_dirs 46,563).
- [x] **Server-side file deletion script** (`migration/delete_files.sh`, measure|delete, leaf-only,
      pattern-validated).
- [x] **Scope decided**: **ORSkills + all lookalikes** (`ORSkills`, `ORSkills-Dev`,
      `oforral-orskills`, `ORSkills-Sandbox`). Final manifest `manifest_ORSkills_all/`:
      **46,588 media · 49,679 mediaFile · 46,588 leaf dirs.** Verified leaf dirs are globally
      unique (0 collisions) and target-only; top-chunk dirs are shared (2,164 mixed with keep-apps)
      → leaf-only deletion is safe. On-disk model confirmed via SSH (read-only).
- [?] **Backup policy vs privacy**: a pre-purge full backup would contain ORSkills data we're
      legally wiping. Decide: purge first then take the authoritative clean backup, vs. keep a
      short-lived secured pre-purge backup and re-take after. (See discussion.)
- [x] **Measured on-disk footprint** of ORSkills (2026-05-31): **638 G** across **40,189** existing
      leaf dirs (6,399 never uploaded). That's ~85% of the 748 G store → purge drops `/media` to
      ~110 G. Staged on server at `/root/orskills_purge/` (leaf_dirs.txt md5-verified, delete_files.sh).
- [x] **Review** manifest + measure output before deleting anything.
- [x] **Scope expanded (2026-10-25)** to 10 retired keys: ORSkills, ORSkills-Dev, oforral-orskills,
      ORSkills-Sandbox, Subdocs-tyhand, NWPromiseTeacherApplication, Paradocs, VaccineTracking, Migrant,
      ORMed Training → `manifest_retire/` (47,291 media · 50,621 mediaFile).
- [x] **Pilot (2026-10-25)**: 7 leaf dirs deleted, each sharing a parent dir with a SubDocs/NurseDocsDev
      canary — all canaries intact, parents intact.
- [x] **Files deleted (2026-10-25)**: 40,578 leaf dirs removed in ~2 min; `/media` 752 G → **113 G**.
      Verified all 39,486 kept-app leaf dirs still present (0 missing) + canaries OK.
- [x] **Mongo cleanup (2026-10-25)**: deleted 47,291 media + 50,621 mediaFile docs; 10 retired keys
      deactivated. Verified: only SubDocs, NurseDocsDev (DocGuard), Events, Training,
      HealthedTrainingsNWRESD remain — **64,013 media / 43,350 mediaFile**, all 5 keys active.
- [ ] Optional tidy: remove `/root/orskills_purge` + `/root/retire_purge` id lists on the old server.

## ⚠️ Priority: migrate ASAP
Old server (PHP 5.6.8) is exposed to an unpatchable PHP RCE class of vuln. **No code fixes on the old
system** — all hardening (ownership check, header-based keys, CORS) goes into the new system.

## Rebuild (Symfony 7.4 / PostgreSQL / Messenger) — see AI.md §13–14

Done 2026-10-25 (working tree on `php8_upgrade`):
- [x] Starter scaffold; dependencies updated (composer audit clean).
- [x] Schema: `api_client` (SHA-256 keys), `media`, `media_file` (`position`), `messenger_messages`.
- [x] API: the four endpoints, API-key authenticator (header/query/form), ownership on every read.
- [x] Contract fixtures captured from the legacy server; byte-identical replay tests.
- [x] Processors: image, pdf, audio (mp3), video (frame + mp4); Messenger worker; callbacks
      (incl. legacy scheme-less URLs).
- [x] Mongo→PG import (`migration/import_to_pg.py`); 39,486 media / 43,350 files; local + **prod
      rehearsal** done. Live parity check 150/150 identical.
- [x] prod DB `phantasos` on appdev-pg-prod: schema migrated; all tables pg_tde-encrypted.
- [x] Containers (`app`, `web`) + nginx (X-Accel, upload streaming, no query-string logging);
      local end-to-end smoke test passed. Quadlet units in `deploy/quadlet/`.

Open:
- [x] Word/Excel: not supported (decided 2026-10-25). Revisit with ORMed (docx/xlsx attachments).
- [x] Management UI (2026-10-25): ORMed v2 layout + help panel; Clients & Keys (create/rotate/
      deactivate, key shown once); Processing (failed/stuck + retry); dashboard; nginx allowlist.
- [ ] Media browser (search by client/media ID, view files; audit each view).
- [ ] Phantasos Google OAuth client for the management site; add the real admin users.
- [?] Management-site allowlist: confirm which source ranges staff arrive from at .21 (private
      ranges are the default; if traffic is NATed to MESD/NWRESD public IPs, list those).
- [ ] Build/push script for Harbor (`appdev-phi`), image tags; Trivy scan clean.
- [ ] Retention for `audit_log` (`media.served` ≈ 7k rows/day) and `messenger_messages` failed queue.
- [ ] Monitoring: worker liveness, failed-message count, disk usage on /media.

## Host & cutover (phantasos.apps.cascadetech.org, .21)

Handoff to the cluster/infra side (details in AI.md §14 "Host prerequisites"):
- [ ] LUKS on `/media` (while empty); SELinux fcontext for `/media` (no `:Z`).
- [ ] Podman + Quadlet; Harbor robot login.
- [ ] Let's Encrypt (Route53 DNS-01) for both names → `/etc/phantasos/tls/`; renewal reload hook.
      Confirm Route53 hosts `mesd.k12.or.us`.
- [ ] `/etc/phantasos/phantasos.env` (mode 600) from `deploy/phantasos.env.example`; fresh APP_SECRET.
- [ ] Firewall: 443/80 public; pg access from .21 to appdev-pg-prod.
- [ ] Remove MongoDB from the cluster repo's Phase 17.
- [ ] rsync old→new `/media/phantasos` (`--chown=65534:65534`), nightly `/media` backup.

Test on `phantasos.apps.cascadetech.org` (test writes are disposable), then cutover:
- [ ] Stop the old web server.
- [ ] Final `rsync --delete` + `import_to_pg.py --target prod --apply` (full re-import).
- [ ] DNS flip `phantasos.mesd.k12.or.us` → .21.
- [ ] Monitor 1 h: consumer traffic (Training, HealthEd MESD/NWRESD, SubDocs, DocGuard when on),
      worker queue, callbacks to SubDocs.
- [ ] Decommission old host, MD3200i, appdev-video1/2/3; remove RabbitMQ.

## HIPAA / ePHI (before ORMed onboarding)
- [x] Storage decision: local storage on the Rocky box (AI.md §11).
- [ ] LUKS on `/media` + encrypted backups; PG already pg_tde.
- [ ] Tenancy decision for ORMed (dedicated instance vs multi-tenant).
- [ ] ORMed key under its final name; ORMed proxies media server-side.
- [ ] Availability: tested restore; risk assessment sign-off.

## Backlog / nice-to-have
- [ ] Phase 2: signed short-lived URLs (nginx `secure_link`) so browsers don't carry API keys.
- [ ] Phase 2: HLS adaptive ladder for video.
- [ ] One key per consumer environment (prod/demo/qa/dev currently share keys).
- [ ] CI: PHPUnit + PHPStan (starter workflow exists; needs ffmpeg + the `_test` DB).
