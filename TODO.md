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
- [ ] **Review** manifest + measure output before deleting anything.
- [ ] **Execute purge** (after backup decision): `delete_files.sh delete` on server, then
      `purge_app.py --apply` for Mongo docs.
- [ ] **Verify**: `inspect_db.py` shows ORSkills media/mediaFile = 0; spot-check leaf dirs gone.

## Phase 2 — Dependency / runtime modernization

- [ ] **MongoDB driver**: replace legacy `ext-mongo` path. Target **Doctrine MongoDB ODM 2.x +
      `ext-mongodb` + `mongodb/mongodb`** (required for PHP 8 + modern Mongo). (AI.md §6.)
- [ ] **Mongo mappings**: convert `@MongoDB\String/Int/Boolean` annotations → ODM 2.x
      attributes/`#[Field(...)]`. Re-map `Media`, `MediaFile`, `ApiUserDocument`.
- [ ] **PHP 8.x**: get `composer install` working locally (MacBook has PHP 8.5.6) on the new stack.
- [ ] **Replace abandoned libs**: `phpoffice/phpexcel` → `phpoffice/phpspreadsheet`; check
      `imagine`, `dompdf`, `php-ffmpeg`, `guzzle` for current majors.
- [ ] **Queue payload**: move RabbitMQ message from PHP `serialize()` to JSON.

## Phase 3 — Symfony upgrade (2.6 → current LTS)

- [ ] Scaffold a current **Symfony LTS** (6.4/7.x) skeleton (flex), migrate `src/` bundles into it.
- [ ] Replace **`security.context`** usage with `Security`/`token_storage` in `ApiController`.
- [ ] Port the **TyHand API-key auth** to the new Security authenticator system.
- [ ] Port **routing/controllers** to PHP attributes; `Controller` → `AbstractController`.
- [ ] Port **RabbitMQ integration** (oldsound bundle is unmaintained for new Symfony — evaluate
      `php-amqplib` directly or Symfony Messenger + AMQP transport).
- [ ] Re-wire `services.yml`, config, monolog (mail handler), swiftmailer → **symfony/mailer**.
- [ ] **Centralize/clean CORS** (kill the duplicated hardcoded headers; use a real CORS policy).
- [ ] Revisit/replace the `pcntl_fork` processor runner (Messenger workers / k8s replicas).

## Phase 4 — Infrastructure migration

- [ ] **RabbitMQ → Kubernetes** (replace the 3 appdev-video VMs). Decide cluster operator
      (e.g. RabbitMQ Cluster Operator) + persistence.
- [ ] **Processor workers → k8s** (containerize ffmpeg + consumer; scale via replicas).
- [ ] **API/web app → k8s** (containerize; ingress; config via env/secrets — rotate prod secrets).
- [ ] **MongoDB**: provision new home per Phase-0 decision; migrate data; cut over.
- [x] **Storage architecture decided** (2026-06-01): **local storage on Rocky box + NFS to k8s
      pods** (not Longhorn RWX / not cloud object storage). See AI.md §11. ePHI/HIPAA in play.
- [ ] **New storage server**: provision Rocky 10; migrate `/media/phantasos` (post-purge ~110 G,
      growing to ~1 TB) off the failing MD3200i; **NFS mount into BOTH API and worker pods**.
- [ ] **HIPAA — at rest**: LUKS on `/media`; LUKS on Mongo data dir + mongodump backups.
- [ ] **HIPAA — in transit (CHOSEN: WireGuard)**: WG tunnel worker-nodes↔phantasos, NFS bound to
      wg0 only (NFSv4.2). WG e.g. 10.66.0.0/24 (verify no Cilium CIDR clash); peers = workers
      .3/.4/.5; firewalld 51820/udp from 198.236.76.0/28 + NFS only on wg0; k8s PV server=10.66.0.1;
      wg-quick before kubelet. (Alt: NFSv4.2 RPC-over-TLS.) Plus TLS on mongod + API.
- [~] **HIPAA — audit logging**: owned by **ORMed**, not Phantasos. Confirm ORMed proxies media
      server-side (not browser-direct), lock Phantasos API access to ORMed pods (CiliumNetworkPolicy
      egress + phantasos firewall to k8s subnet), ORMed media = hideToOthers:true.
- [ ] **ORMed onboarding** (not yet in Phantasos; being rebuilt): provision a NEW api key under its
      final renamed name when added — no data migration. (`ORMed Training` = different, non-ePHI.)
- [ ] **HIPAA — tenancy decision**: dedicated Phantasos instance+volume+Mongo for the ePHI app vs.
      multi-tenant (affects compliance scope).
- [ ] **HIPAA — availability**: storage HA/replication + tested restore (contingency plan).
- [ ] **HIPAA — sign-off**: security/compliance officer owns the risk assessment.
- [ ] **Secrets**: rotate Mongo/RabbitMQ/mailer/app-secret; move to k8s secrets. (AI.md §8.)

## Phase 5 — Cutover & validation

- [ ] End-to-end test in sandbox: upload (image/pdf/doc/audio/video) → process → mediaInfo →
      requestMedia, for each consuming app's key.
- [ ] Verify callbacks still reach consuming apps.
- [ ] Plan cutover window; DNS/ingress switch; keep old stack as rollback until verified.
- [ ] Decommission old phantasos host + MD3200i + appdev-video VMs after stable.

---

## Backlog / nice-to-have
- [ ] Re-enable `.docx` processing (currently disabled due to an unresolved bug).
- [ ] Fix dead `MediaFile::$filePath` getter/setter (unmapped property).
- [ ] Add automated tests (none currently in repo).
- [ ] Add CI + container build pipeline.
