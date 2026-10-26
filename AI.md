# Phantasos — Project Memory (AI.md)

Shared knowledge base for the Phantasos modernization effort. Keep this current as we
learn things — it is the "what is true about this system" doc. Actionable work lives in `TODO.md`.

> **KEY DECISIONS (current target architecture, 2026-06-01):**
> - **DB: re-platform Mongo → Percona PostgreSQL** (decided). The org runs ~50 PG DBs already;
>   Percona TDE (at rest) + TLS (in transit) + Barman backups already in place.
>   This makes the old `ext-mongo`/ODM blocker (§6) **moot** — we leave Mongo, not upgrade it.
> - **Storage: local `/media` on the Rocky 10 `phantasos` box + LUKS at rest.** In-transit to k8s via
>   **WireGuard**-tunneled NFS *(only if API/workers run in k8s — see OPEN QUESTION below)*.
> - **phantasos VM sheds the DB role** → becomes media-store + app/API host. DB lives on Percona PG.
> - ePHI app **ORMed** (being rebuilt) onboards later as a brand-new key; `ORMed Training` ≠ ePHI.
> - **OPEN:** do the Phantasos **API + queue workers run on the phantasos VM (local /media)** or **in
>   k8s (needs NFS+WireGuard)**? Determines whether the storage-encryption design is even needed.
> - **OPEN:** ePHI in a **dedicated PG instance/db** vs. shared `pg-prod` (HIPAA segmentation).

---

## 1. What Phantasos is

A media upload, processing, storage, and retrieval **API server**. Internal MESD apps
use it to store and serve **images, PDFs, Office documents, audio, and video**. Each
consuming app authenticates with its own API key; uploads are processed asynchronously
(video is transcoded into multiple formats/bitrates) and served back over HTTP.

- Originally authored ~2015 (git author: `thand`). **Last real commit ~9 years ago.**
- It has "just worked" and been largely unmaintained since.
- Repo: `git@github.com:CTA-K12/Phantasos.git` (origin). MESD fork: github.com/MESD/Phantasos.
- Current working branch: `php8_upgrade` (off `master`).
- Version file says `0.2.6`.

### Recent commits of note (pre-existing, before this effort)
- Word/Excel document processing was added, then `.docx` support was reverted
  ("Removed .docx support until issue with them can be fixed, .doc still works").

---

## 2. Tech stack (current / as-built)

| Layer | Technology | Version (as-built) | Notes |
|---|---|---|---|
| Language | PHP | `>=5.3.3`, currently needs **PHP 7.x** | **Cannot run on PHP 8** as-is (see §6) |
| Framework | Symfony | **2.6** (Standard Edition) | EOL many years; huge jump to LTS |
| DB / ODM | Doctrine MongoDB ODM | `dev-master` (1.x era) | Requires legacy `ext-mongo` |
| Mongo driver | `ext-mongo` (PECL) | legacy | **Removed in PHP 7.0** — the core blocker |
| Database | MongoDB | **3.0.2** (`mongodb-org-server-3.0.2-1.el7`, 2015) | DB name `phantasos`; too old for mongosh — query with pymongo 3.x |
| Queue | RabbitMQ via `oldsound/rabbitmq-bundle` ~1.6 | 3-node cluster | On the appdev-video hosts |
| Video | `php-ffmpeg` 0.6 + system ffmpeg/ffprobe | `/usr/bin/ffmpeg` | Transcodes to mp4/webm/ogv |
| Images | `imagine/imagine` ~0.5 (GD) | | Thumbnails/resizes |
| Documents | `dompdf` 0.8, `phpoffice/phpword` 0.13, `phpoffice/phpexcel` 1.8 | | phpexcel is abandoned |
| Storage abstraction | `knplabs/gaufrette` ~0.1 | | (present in deps) |
| HTTP client | `guzzlehttp/guzzle` ~5.0 | | Used by Alerter for callbacks |
| API auth | custom `TyHand\SimpleApiKey*` bundles | in-repo | API-key preauth, Mongo-backed |

---

## 3. Infrastructure / hosts

- **`phantasos.mesd.k12.or.us`** — the web/API server (Apache/httpd, deployed under
  `/var/www/phantasos`) **and** currently also hosts the **MongoDB** instance (port 27017).
- **`appdev-video1/2/3.mesd.k12.or.us`** — 3 VM guests forming the **RabbitMQ cluster**
  and running the **processor consumers + ffmpeg**. `rabbitmq_host` points at
  `appdev-video1`. The processor runs as `phantasos-processor.service` (systemd) on these.
- **Storage**: media files live on a filesystem mounted at **`/media/phantasos`**
  (`/dev/mapper/vg1-lv--media`, 5.0T total, **748G used / 15%** — smaller than feared), currently
  backed by an **11-year-old Dell MD3200i SAN that is failing (one controller down)** —
  primary driver for this migration. The purge of the decommissioned app will trim this further.
- **New target server**: a fresh **Rocky Linux 10** VM will host the **new MongoDB** and the
  **Phantasos file store** (`/media/phantasos`). (To be provisioned.)

### Deployment (legacy, see `publish.sh` / `publish-processor.sh`)
Old flow: copy `parameters.yml` + `version.txt` from the live tree, `composer install`,
`assets:install`, `assetic:dump`, clear cache, `rsync` to `/var/www/phantasos`, chown to
apache, reload httpd (web) / restart `phantasos-processor.service` (processors).
**Target deployment: Kubernetes** (see §7).

---

## 4. Architecture & data flow

### Upload → process → ready
1. `POST /api/requestUploadTicket` (auth'd) → creates a `Media` doc (status "In queue",
   2-hour upload expiration) → returns a ticket id + upload URL.
2. `POST /api/uploadMedia/{ticketId}` (file in `file_data`) → validates ticket
   (exists / unused / not expired) → `Preparer::handleUpload`:
   - rejects unsupported MIME types,
   - stores the original to disk,
   - publishes a RabbitMQ message `{mediaId}` (PHP-`serialize`d) to the `process_upload`
     exchange/queue,
   - marks the media `uploaded` and stamps its media type.
3. **ProcessorConsumer** (on video hosts) pulls the message, loads the original, and
   **ProcessorContainer** routes by MIME type to a processor.
4. Processor derives outputs, registers each as a `MediaFile`, then `markAsReady()`.
   If the media has a `callback` route set, **Alerter** does a Guzzle callback to the app.

### Retrieval
- `GET /api/mediaInfo?media_id=...` → JSON metadata (respects `hideToOthers` visibility).
- `GET /api/requestMedia?media_file_id=...` → raw file (`BinaryFileResponse`), visibility-checked.

### Processors (`src/Component/Processor/Processors/`)
- **ImageProcessor** (Imagine/GD): thumbnail (75×75) + sized variants.
- **VideoProcessor** (ffmpeg): for each of `small` (640×360) and `large` (1280×720),
  emits `.mp4` (x264/mp3), `.webm`, `.ogv`, plus a `*-frame.png` poster. Falls back to
  AudioProcessor if the "video" has no video stream.
- **AudioProcessor**, **PdfProcessor**, **WordProcessor**, **ExcelProcessor**.
- Processors are registered via the `phantasos.tag.processor` DI tag + a compiler pass.

### Processor runner
`php app/console phantasos:run_processors` (`RunProcessorsCommand`) `pcntl_fork`s
`phantasos_processor_count` (=4) child RabbitMQ consumers in an endless loop, with email
alerting on failure (throttled by `email-timeout`). `phantasos:reprocess` requeues failed
(or a single) media.

---

## 5. Data model (MongoDB)

Database: **`phantasos`**. Collections:

- **`media`** (`Component\Storage\Document\Media`)
  - `applicationName` (string) — **owning app; this is the key for the decommission cleanup**
  - `hideToOthers` (bool), `tags[]`, `securityTags[]`
  - `uploaded` (bool), `uploadExpiration` (date)
  - `mediaType` (video/audio/image/pdf/word/excel/document/...)
  - `callback` (string), `callbackSent` (bool)
  - `ready` (bool), `processingPercentage` (float), `status` (string; see StatusEnum)
  - `files` → **ReferenceMany** to `MediaFile` (cascade=all, addToSet)

- **`mediaFile`** (`Component\Storage\Document\MediaFile`)
  - `fileName`, `original` (bool), `contentType`, `bitRate`, `height`, `width`
  - NOTE: `getFilePath/setFilePath` reference a `$filePath` property that is **not mapped** —
    latent bug / dead code. Paths are actually derived from the file key (below).

- **`apiUsers`** (`TyHand\...\Document\ApiUserDocument`)
  - `applicationName` (unique), `applicationUri`, `applicationDescription`,
    `contactEmail`, `contactName`, `roles[]`, `apiKey` (unique), `active` (bool),
    `lastUse` (date), `salt`. **One doc per consuming app.**

### On-disk layout & file key
`LocalFileKey::getBaseFileKey($mediaId)` derives the directory from the Mongo ObjectId:
```
{id[0:5]}/{id[5:10]}/{id[10:15]}/{full-id}/
```
under `/media/phantasos`. So a media's files all live in one leaf dir
`/media/phantasos/<a>/<b>/<c>/<fullId>/` (e.g. `original.<ext>`, `small.mp4`, `large.webm`,
`thumbnail.png`, `*-frame.png`). **This makes per-media deletion a single directory remove.**

### StatusEnum
`In queue` → `Processing` → `Ready` | `Failed to process`.

### Live inventory (read-only survey, snapshot 2026-05-31 — see `migration/inspect_db.py`)
- Mongo `phantasos` is **tiny: ~60 MB data / 77 MB storage**. The 748 G is all on-disk files.
- Counts: **`media` = 111,129**, **`mediaFile` = 93,876**, **`apiUsers` = 15** (+ legacy `test`).
- `mediaFile` < `media` → **lots of media docs have no files** (incomplete uploads).
- **`mediaType: None` = 31,109 (28%)** — upload tickets never completed/processed. Separate
  easy-cleanup win (orphaned/abandoned media), independent of the app purge.
- media by type: pdf 77,534 · None 31,109 · image 1,210 · video 995 · audio 258 · word 18 · excel 5.
- **~99% of media belongs to 3 apps**: `ORSkills` (46,563), `SubDocs` (34,472),
  `NurseDocsDev` (28,618). The other 10 apps are <500 media each.
- Per-app media (ready): ORSkills 46,563(40,164) · SubDocs 34,472(32,887) ·
  NurseDocsDev 28,618(6,035) · Events 462 · Training 264 · Subdocs-tyhand 222 ·
  VaccineTracking 177 · Paradocs 152 · NWPromiseTeacherApplication 116 · ORMed Training 36 ·
  HealthedTrainingsNWRESD 22 · ORSkills-Dev 20 · oforral-orskills 5.
- **Stale apps** (apiUsers `lastUse`): Migrant 2018 (0 media), ORSkills-Sandbox 2015 (0 media),
  ORSkills-Dev 2015, oforral-orskills 2015, Subdocs-tyhand 2016, NWPromiseTeacherApplication 2018,
  Paradocs 2018, VaccineTracking 2021. Active/recent: SubDocs, NurseDocsDev, Events, Training,
  ORMed Training, HealthedTrainingsNWRESD, ORSkills.
- **Decommissioned app to purge: `ORSkills`** (confirmed 2026-05-31). This is a
  **privacy wipe** — their data must be removed. **46,563 media / 49,502 mediaFile docs.**
  Leave `SubDocs` and `NurseDocsDev` alone. **Scope = ORSkills + all lookalikes** (`ORSkills`,
  `ORSkills-Dev`, `oforral-orskills`, `ORSkills-Sandbox`) → `manifest_ORSkills_all/`:
  **46,588 media / 49,679 mediaFile / 46,588 leaf dirs.**
  Verified: leaf (full-id) dirs are globally unique (0 collisions across all 111,129 media), so
  leaf-only deletion can't touch another app; top-chunk dirs ARE shared (2,164 prefixes mix
  ORSkills with keep-apps) so never delete a chunk dir. **No dedup / no content-hashing** — path
  is keyed by per-record ObjectId, not a content hash, so no shared blobs.
  **Backups**: MongoDB is mongodump'd nightly on the host (`/var/lib/mongo/backups/{today,yesterday}`),
  so deleting Mongo docs is recoverable. **There is NO backup of the `/media` file store** — file
  deletion is IRREVERSIBLE. (sys-eng to set up a `/media` backup in the new env.)
  SSH: `root@phantasos.mesd.k12.or.us` available (key-based) for read-only checks.
- **Purge tooling** (in `migration/`): `purge_app.py` builds a frozen manifest
  (`manifest_ORSkills/{media_ids,mediafile_ids,leaf_dirs}.txt`) in dry-run, and with `--apply`
  deletes the Mongo docs (mediaFile → media → deactivate apiUser). `delete_files.sh` runs on the
  **storage server** to measure/delete the on-disk leaf dirs (pattern-validated, leaf-only).

### App / key inventory (verified 2026-10-25)
Evidence: ~5 weeks of old-server Apache logs (key hashed server-side, never exported), Mongo upload
timestamps, and matching `MEDIASERVER_KEY` values in consumer repos by SHA-256 (values never printed).

**Kept keys (ORMed Training moved to purge → 5 keep):**

| Key (old name) | Actually used by | Evidence | Rename at migration |
|---|---|---|---|
| `Events` | **HealthEd Trainings — MESD** (`healthed-api`, `healthed-training`; prod/demo/dev/qa) | k8s nodes read; browser uploads | → **`HealthEdTrainingsMESD`** |
| `HealthedTrainingsNWRESD` | **HealthEd Trainings — NWRESD** (same repos, nwresd envs) | k8s reads; uploads from ttsd.k12.or.us | keep / align naming |
| `Training` | **Online Training** (`training-api`) — embedded by HealthEd (MESD/NWRESD) and MAC frontends | ~257k `requestMedia` in 5 wks (heavy) | keep |
| `NurseDocsDev` | **DocGuard** — being decommissioned (unpatchable vuln); now **internal-network only, switched on on demand for urgent research**. **Must stay fully functional until its purge (several months out) — do NOT deactivate its key. Decision: MIGRATES to the new Phantasos as `DocGuard`.** | DocGuard backup configmap key matches | → **`DocGuard`** |
| `ORMed Training` | legacy ORMed site (`ormed.mesd.k12.or.us`); unused — reads likely bots crawling the old site | ~500 reads in 1 day | **→ PURGE** (added to retire list) |
| `SubDocs` | older system being disabled; **one NWRESD client still reads (read-only)** | no requests in 5-wk window; no uploads since 2024-01 | **keep for now** |

**Findings to carry into the rebuild:**
- **SECURITY: file downloads have NO ownership check.** `FileInfo::canBeViewedBy()` is commented out and
  returns `true`, so *any* valid key can `requestMedia` *any* app's file by `media_file_id` (ObjectIds are
  semi-guessable). Only `mediaInfo` enforces `hideToOthers`. Log check (5 wks): **zero cross-app reads** —
  re-enabling the check breaks no current consumer. **Decision: not patched on the old system** (avoid
  touching exposed legacy PHP; migrate ASAP instead). New system MUST enforce ownership on every read.
- **DocGuard data may already be ePHI** (nurse document exchange) — if so, ePHI is in Phantasos *today*
  on the old box, not just with future ORMed. Prioritise it in HIPAA scoping.
- **One key is shared across prod/demo/dev/qa** for HealthEd (MESD and NWRESD) and Training → non-prod
  environments read/write production media. New system: **one key per environment**.
- **API keys travel in the GET query string** → they land in Apache access logs in cleartext. New system:
  accept the key in a header (keep query-param fallback only during cutover).
- **Old server is internet-reachable** (browser uploads need it; CISA CyHy scans it). New API sits behind
  Envoy Gateway on the cluster.
- Application name is server-side only (derived from the key), so renames are transparent to consumers —
  do them in the Mongo→PG import, not on the live old system.

---

## 6. The PHP-upgrade blocker (why it needs PHP 7.x today) — NOW MOOT (leaving Mongo)

> **SUPERSEDED by the Postgres decision (see banner up top).** We are **not** upgrading the Mongo
> stack — we're replacing it with Percona PostgreSQL + Doctrine **ORM**. So the `ext-mongo`/ODM 1.x→2.x
> blocker below no longer gates the PHP 8 upgrade. The persistence layer (`MongoDBStorage`) gets
> rewritten against PG either way; the data model is cleanly relational (see §11.5). Kept below for
> history / in case the decision is ever revisited.

The MongoDB stack here is built on the **legacy `mongo` PECL extension (`ext-mongo`)**, pulled
in by `doctrine/mongodb` (dev-master) → `doctrine/mongodb-odm` → `doctrine/mongodb-odm-bundle`.
`ext-mongo` was **removed in PHP 7.0**. Modern PHP uses the **`ext-mongodb`** extension plus the
`mongodb/mongodb` userland library, and **Doctrine MongoDB ODM 2.x**.

Two migration paths:
- **Bridge (PHP 7 only, stopgap):** `alcaeus/mongo-php-adapter` reimplements the old `ext-mongo`
  API on top of `ext-mongodb`. Lets the existing ODM 1.x run on PHP 7. **Does not work on PHP 8.**
- **Proper (target):** move to **Doctrine ODM 2.x + ext-mongodb**, which is required for PHP 8 and
  for a current MongoDB server. This changes mapping annotations and some APIs.

The local dev machine currently has **PHP 8.5.6** (Homebrew, NTS, Xdebug).
`composer install` will not work here until the stack is modernized.

---

## 7. Migration goals (the "why we're here")

1. **Replace the dying storage server** (Dell MD3200i) with a new storage backend for
   `/media/phantasos`.
2. **Upgrade MongoDB** to a current stable release.
3. **Upgrade PHP** to a current stable (8.x).
4. **Upgrade Symfony** from 2.6 to a current LTS (e.g. 6.4/7.x) — effectively a substantial
   rewrite of bootstrap/config/security.
5. **Move RabbitMQ (3 VMs) into Kubernetes.**
6. **Decommission-app data purge** — delete all media (Mongo docs + on-disk files) for one
   app that was removed from the platform. Reduces data footprint and de-risks migration.
   *(App name to be confirmed — see TODO.)*

### Deployment & infra preferences
- Apps run on **Kubernetes** now.
- **Does NOT want DB servers in k8s** right now; has dedicated Postgres prod/dev servers and
  leans toward keeping a **dedicated MongoDB VM**. Open to MongoDB-in-k8s since this DB is very
  low-traffic, but not committed. → **decision pending** in TODO.
- **RabbitMQ → k8s: yes.**

---

## 8. Known issues / debt to address during the rewrite

- **`security.context`** is used in `ApiController` (`->get('security.context')->getToken()`).
  Removed in Symfony 3.0 → use `security.token_storage` / injected `Security`.
- **Doctrine annotations** like `@MongoDB\String`, `@MongoDB\Int`, `@MongoDB\Boolean` are gone in
  ODM 2.x → `#[Field(type: 'string')]` etc. (`String`/`Int` are reserved words in PHP 7+).
- **Annotations → PHP 8 attributes** across controllers/routes/mappings.
- **`Controller` base class** deprecated → `AbstractController`.
- **Queue payload uses PHP `serialize()`/`unserialize()`** of an array — brittle across versions
  and an unserialize-trust smell → switch to JSON.
- **CORS** headers are hardcoded and duplicated all over `uploadMediaAction`, with
  `Access-Control-Allow-Origin: *` — needs cleanup/centralization.
- **`phpoffice/phpexcel` is abandoned** → migrate to `phpoffice/phpspreadsheet`.
- **`.docx` processing disabled** (see commit history) — unresolved bug.
- **`MediaFile::$filePath`** getter/setter with no mapped property — dead/buggy.
- **Secrets**: `app/config/parameters.yml` (gitignored) holds the **live prod creds** (Mongo,
  RabbitMQ, mailer, app secret), manually copied from the server — **not leaked**, so no
  rotation is required for a security reason. The real action item is to stop storing them in a
  plaintext yml and **move them to k8s secrets / env** on the new platform. Rotation is optional
  hygiene (they're ~9 years old), not mandatory. Existing passwords keep working on the new server.

---

## 9. Endpoints quick reference

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/requestUploadTicket` | API key | Reserve an upload slot, get ticket id |
| POST/OPTIONS | `/api/uploadMedia/{ticketId}` | ticket (not in firewall) | Upload `file_data` |
| GET | `/api/mediaInfo?media_id=` | API key | Metadata JSON |
| GET | `/api/requestMedia?media_file_id=` | API key | Raw file bytes |

Firewall: `^/api/(?!uploadMedia|test)` is stateless API-key preauth (TyHand authenticator).

---

## 10. Open questions to resolve
- Exact **application name** of the decommissioned app to purge (must match `media.applicationName`
  and `apiUsers.applicationName`).
- Current **PHP version** actually running in prod on phantasos host. (Mongo confirmed: **3.0.2**.)
- **MongoDB hosting decision**: dedicated VM vs k8s. (Leaning: new MongoDB goes on the Rocky 10 VM
  alongside the file store, per current plan.)
- New **storage backend** choice for `/media/phantasos` (NFS/CephFS/object storage + how it maps
  to the existing hashed-dir layout, or whether we re-architect to S3-style via Gaufrette).
- Total **data size** on disk and in Mongo (informs migration window + the purge's payoff).
  → Largely answered: 748 G disk (638 G is ORSkills, to be purged → ~110 G remaining); Mongo ~60 MB.

---

## 11. Storage architecture & HIPAA / ePHI

A new **K-12 Student Healthcare app** will store **ePHI** in Phantasos → **HIPAA** applies. Storage
is expected to grow from ~110 G (post-purge) to **~1 TB soon**.

### Decision (2026-06-01): local storage on the Rocky box + NFS to k8s pods
Not Longhorn RWX, not cloud object storage. Rationale: shared multi-pod writes mean NFS semantics
either way (Longhorn RWX is itself NFS-backed via a share-manager pod); a dedicated NFS server gives
direct control over encryption/export/backup; **on-prem avoids cloud BAAs**; single auditable backup
point for sys-eng. (Correction to earlier note: Longhorn *does* support multi-pod writes via RWX.)

Consequence: the **API pods AND worker pods both mount `/media`** (API writes originals + serves;
workers read originals + write derivatives — not just the processors). Files stay on the Rocky box;
Mongo also on the Rocky box (or its own VM) per the dedicated-DB preference.

### HIPAA controls to implement (beyond at-rest /media encryption)
- **At rest**: LUKS on `/media` ✔ (planned). **Also** LUKS on the **Mongo data dir + nightly
  mongodump backups** — filenames/tags can be ePHI (WiredTiger native encryption is Enterprise-only).
- **In transit**: real encryption required (not the addressable-risk dodge). **Chosen:
  WireGuard tunnel** between worker nodes and phantasos, with **NFS bound to the WG interface only**
  (never exposed on the LAN IP). Rationale: the NFS mount is done by the worker *node* kernel
  (kubelet/CSI), and the traffic is routed across the L3 boundary between Subnet 1 (k8s) and Subnet 2
  (servers) — so Cilium's pod-level encryption doesn't cover it; node-level encryption is needed.
  WG overlay e.g. `10.66.0.0/24` (verify no clash with Cilium pod/service CIDR): phantasos
  `10.66.0.1`, workers w1/w2/w3 `10.66.0.3/.4/.5`. `/etc/exports`: `/media 10.66.0.0/24(rw,sync,
  no_subtree_check,root_squash,sec=sys)`, NFSv4.2 only. firewalld: 51820/udp from `198.236.76.0/28`,
  NFS (2049) only on `wg0`. k8s PV `server: 10.66.0.1`. Bring `wg-quick@wg0` up before kubelet on
  each node. **Alternative**: NFSv4.2 RPC-over-TLS (`xprtsec=tls`+`tlshd`, Rocky 10 kernel) — native
  but newer + needs PKI + CSI must plumb the option. Also **TLS on mongod** + TLS on the API.
- **Audit logging**: handled by **ORMed**, not Phantasos. ORMed (the K-12 health app) is the *only*
  path to store/view/download its media and owns the access audit log. Valid **only if** ORMed
  **proxies media server-side** (never hands the browser a direct Phantasos URL+key), Phantasos
  network access is **locked to ORMed's pods** (NetworkPolicy/firewall), and ORMed media is
  `hideToOthers:true` (default). Phantasos then needs only ordinary web/access logs.
- **Tenancy / blast radius**: consider a **dedicated Phantasos instance + volume + Mongo** for the
  ePHI app vs. multi-tenant; commingling expands HIPAA scope to the whole system.
- **Availability/contingency**: single Rocky NFS box is a SPOF → HA/replication + a **tested restore**.
- **App hardening before ePHI**: CORS `*`, API-key model, `unserialize()` queue payload (see §8).
- **Sign-off**: security/compliance officer must own the HIPAA risk assessment (esp. in-transit
  method + tenancy decision).

### The ePHI app: ORMed (K-12 Student Health System)
- **ORMed is NOT in Phantasos yet** — it's being rebuilt now; Phantasos support is added "soon."
  When added it gets a **brand-new API key under its final (renamed) name** → **no data migration**,
  no rename of existing docs.
- **`ORMed Training` (36 media) is a different, NON-ePHI system** — do not conflate or scope it in.
- `applicationName` is Phantasos's identity key (on `media` + `apiUsers`) and drives visibility.
- Access model: ORMed is the sole gateway + owns audit (see audit bullet above).

---

## 12. Database re-platform: MongoDB → Percona PostgreSQL

**Decision (2026-06-01):** replace Mongo with **Percona PostgreSQL**.
Drivers: team runs ~50 PG DBs (deep PG expertise, weak on Mongo); Percona **TDE** (at rest) + **TLS**
(in transit) + **Barman** backups (`appdev-pg-barman` .18) already operational; the DB server is
already built; phantasos VM then sheds its DB role. Data is tiny (~60 MB) and the model is cleanly
relational — no document-DB feature is used (no GridFS; files are on disk; single-node; stable schema).

### 12.1 Target schema (sketch — finalize during build)
```
api_user(id PK, application_name UNIQUE, api_key UNIQUE, salt, application_uri,
         application_description, contact_email, contact_name, roles jsonb/text[],
         active bool, last_use timestamptz)
media(id PK, storage_key varchar UNIQUE NOT NULL,   -- see continuity note
      application_name (→ api_user), hide_to_others bool, media_type, status,
      ready bool, uploaded bool, upload_expiration timestamptz, callback, callback_sent bool,
      processing_percentage real, tags jsonb/text[], security_tags jsonb/text[],
      created_at timestamptz)
media_file(id PK, media_id (→ media, ON DELETE CASCADE), file_name, original bool,
           content_type, bit_rate, width int, height int)
```
- `media_file.media_id` FK replaces the old BSON **DBRef** array; `ON DELETE CASCADE` makes future
  app purges a single `DELETE FROM media WHERE application_name=…` (files still removed on disk).
- Array fields → `jsonb` (simplest) or join tables if we want to query/index tags.

### 12.2 File-path continuity (THE gotcha)
On-disk paths derive from the Mongo **ObjectId hex** (`LocalFileKey`: `<id0:5>/<id5:10>/<id10:15>/
<fullid>/`). Preserve it: store the 24-char hex in **`media.storage_key`** and keep the path function
operating on `storage_key`, so the **~110 GB of existing files never move**. New rows generate a fresh
`storage_key` (keep 24-hex style for a uniform path scheme, or switch + branch the path fn).

### 12.3 Conversion approach
One-time BSON→PG load, reusing the `migration/` pymongo tooling (add `psycopg2` to the venv):
read `apiUsers`/`media`/`mediaFile` from the old 3.0.2 Mongo → insert into PG; map ObjectId→storage_key,
DBRef→FK. Run AFTER the ORSkills purge so we don't migrate wiped data. Persistence layer
(`MongoDBStorage` → a new `PostgresStorage` on Doctrine ORM/DBAL) is rewritten as part of the Symfony
upgrade — Doctrine ORM is Symfony's first-class path and already in `composer.json`.

### 12.4 Mongo encryption (only relevant if we ever stayed on Mongo)
In transit: mongod native TLS (Community supports). At rest: Community has none → LUKS, or use
**Percona Server for MongoDB** (free at-rest encryption). N/A under the Postgres decision.

---

## 13. New Phantasos — build design (decided 2026-10-25)

**Repo/branches:** `master` tagged `legacy-final` (old code: `git show legacy-final:<path>`). New code is built
on `php8_upgrade`, which merges into **`main`** (new default branch; `master` retires).

**Stack:** Symfony 7.4 LTS on PHP 8.4, based on `cta-symfony-starter`, with the newer layout from
ORMed2/healthed-training (Pico CSS, `_navbar`/`_sidebar`/`_tab_bar`/`_flashes`/macros, slide-out help panel).
Doctrine ORM on Percona PG (`appdev-pg-prod`, db `phantasos`). **Symfony Messenger with the Doctrine
transport** (queue = PG table) — RabbitMQ is retired.

**Database (created 2026-10-25):** `phantasos` on `appdev-pg-prod.apps.cascadetech.org`, single role
`phantasos` (owner; used by the app, migrations and the import — one role keeps dev workflow simple).
Verified: TLS 1.3 with `sslmode=verify-full&sslrootcert=system`, `default_table_access_method = tde_heap`
(new tables encrypted). Connection strings live only in gitignored files: `.env.prod.local` (Symfony,
`APP_ENV=prod`) and `migration/secrets/new-pg.env` (import tooling). PHPUnit and fixtures stay on the
local container (port 5448) — never point the test suite at prod. Prod DB is for migrations, import
rehearsals (disposable until cutover) and live traffic.

**Hosting:** dedicated VM `phantasos.apps.cascadetech.org` (.21), **not k8s** (large volume + RWX).
Alpine containers following the team pattern (`alpine:3` + `php84` packages, non-root, FPM_MAX_CHILDREN):
`nginx`, `php-fpm`, `worker` (same image, runs `messenger:consume`), with ffmpeg in the image. `/media`
(LUKS) is bind-mounted; SELinux context is set once on the host — **never `:Z`**. Images go to Harbor
(Trivy). Differences from the team pattern for an ePHI service: Composer is fetched with TLS verification,
and secrets are injected at runtime (not baked into the image via `.env`). As built: see §14.

**Network/TLS:** public 443 directly on .21; Let's Encrypt via Route53 DNS-01 with one cert for **both**
`phantasos.apps.cascadetech.org` (testing) and `phantasos.mesd.k12.or.us` (after the DNS flip). TBC that
Route53 hosts the `mesd.k12.or.us` zone. Envoy/WAF is a later option (exempt the large upload/download
bodies). Admin UI is reachable from internal networks only; nginx exposes `/api` publicly, with limits.

**API contract = exact parity** (consumers change nothing; keys are imported unchanged):
- Endpoints: `requestUploadTicket`, `uploadMedia/{ticketId}` (browser, CORS), `mediaInfo`, `requestMedia`.
- Key accepted in the query string (legacy) **or** a header. **Ownership enforced on every read.**
- `mediaInfo` file order: **original first**, then derived files in creation order (frontends use
  `mediaFiles[0]` for images/audio) → `media_file.position` column.
- `requestMedia`: PHP authorizes, nginx streams via **X-Accel-Redirect** (sendfile + Range).
- Upload URL is built from the request host → works on both hostnames.
- Audit log of every file access/issuance (starter's AuditLogger).

**Processor parity:** Image → thumbnail/small/medium/large PNG; PDF → none; Word → output.html;
Excel → output.html + output.csv; Audio → mp3 (drop .oga); Video → small/large .mp4 + -frame.png
(**drop .webm/.ogv**: consumers list small.mp4/webm/ogv as `<source>`s and browsers pick mp4).

**Phase 2 (after cutover, opt-in):** signed short-lived URLs (nginx `secure_link`) so browsers fetch
directly without the API key; HLS adaptive ladder for video.

**Migration & cutover:** the per-key, re-runnable Mongo→PG import (renames Events→HealthEdTrainingsMESD,
NurseDocsDev→DocGuard; `storage_key` = old ObjectId hex so file paths never change; skips abandoned
upload tickets with no files) + rsync of /media. Test on the new hostname (test writes are disposable).
Cutover: stop the old web server → final rsync (`--delete`) + full re-import → DNS flip → monitor 1h.

## 14. As built (2026-10-25)

State of the `php8_upgrade` working tree. Everything below is implemented and tested unless marked open.

**Code map**
- `src/Controller/Api/ApiController.php`: the four legacy endpoints.
- `src/Security/ApiKeyAuthenticator.php`: key from the `X-Api-Key` header, then the `apikey` query parameter, then the form field.
  Failures return `403 "Authentication Failed. "`, as legacy did.
- `src/Entity/{ApiClient,Media,MediaFile}.php`, plus enums in `src/Enum/` holding the legacy status and type strings.
- `src/Service/MediaInfoPresenter.php`: builds the byte-identical mediaInfo body.
- `src/Service/MediaFileResponder.php`: X-Accel-Redirect in prod, BinaryFileResponse in dev and tests.
- `src/Service/MediaStorage.php`: the legacy disk layout.
- `src/Processor/*`: one processor per type, tagged `app.processor`.
- `src/MessageHandler/ProcessMediaHandler.php`: the worker. Processing failure sets the status to "Failed to process", then Messenger retries and finally parks the message in `failed`.

**IDs and keys**
- Media and media-file IDs stay 24-hex ObjectIds (`App\Util\LegacyId`). Imported rows keep their IDs, and new ones use the same format.
- They are the public IDs, and they decide the disk path `<id0:5>/<id5:10>/<id10:15>/<id>/`.
- API keys are stored only as SHA-256 (`api_client.key_hash`), so imported keys keep working unchanged.

**Contract evidence**
- `migration/capture_contract.py` recorded legacy responses into `tests/Contract/fixtures/legacy/`. In those fixtures the `raw` field is authoritative; the parsed `json` has sorted keys.
- `tests/Contract/LegacyApiContractTest.php` replays them byte for byte.
- `migration/parity_check.py`: old and new mediaInfo matched 150/150 (30 random media per app, real keys, renamed clients included).

**Deliberate differences from legacy**
- Ownership is enforced. requestMedia and mediaInfo for another app's media return 404. Legacy served any file to any key.
- Unknown or malformed IDs return 404, not 500. Both mediaInfo not-found cases return "Media was not found".
- Files are served with `Cache-Control: private`, not `public`.
- `owner` in mediaInfo reports `api_client.legacy_name` when set, so renamed clients still show `Events` and `NurseDocsDev` to consumers.
- New audio produces only `low.mp3` and `high.mp3`; new video produces only `small`/`large` `-frame.png` and `.mp4`. Imported media keep their existing webm, ogv and oga files.
- Video is letterboxed to exactly 640×360 and 1280×720.
- **Open: Word and Excel processors are not built.** The current data has zero word or excel media, and Office parsers have a long record of XML vulnerabilities. Such uploads now get `400 Filetype "…" is not supported.`. Decide before cutover whether to add them (PhpSpreadsheet / PHPWord).

**Legacy behaviour kept on purpose**
- Image renditions are stretched to the exact box size, with "72ppi" in `bitrate`.
- Ticket lifetime is 2 h, and `expiration` is reported in Pacific time.
- Whole floats are written as `100`, not `100.0`.
- The CORS headers on uploadMedia are unchanged.
- Callbacks are a form POST of `media_id`, and only HTTP 200 counts as sent.
- Callback URLs without a scheme get `http://`. SubDocs registers `ortrac.mesd.k12.or.us/subdocs/external/...`; 29,308 of 34,472 were delivered that way.

**Time:** timestamps are stored naive in `APP_TIMEZONE` (America/Los_Angeles), set in `Kernel::boot()` so the web, the worker and the tests agree. The import converts Mongo UTC to Pacific.

**Tests**
- PHPUnit uses `phantasos_dev_test` (`dbname_suffix`), so imported data in `phantasos_dev` never collides with test rows.
- 100 tests, PHPStan level 8 clean.
- The ffmpeg tests skip when ffmpeg is missing.

**Import (`migration/import_to_pg.py`)**
- Per app, in one transaction: upsert the client, delete that client's media, then insert from Mongo, with a count check that rolls back on mismatch. Runs are idempotent.
- Dry-run is the default; `--apply` writes.
- Result: 39,486 media and 43,350 files. All Ready media were imported, every file reference resolved, and the 24,527 never-uploaded tickets were skipped.
- It ran on the local dev DB and **on appdev-pg-prod** (schema migrated, rehearsal import done 2026-10-25). Every prod table and index is pg_tde-encrypted.

**Containers** (`.docker/Dockerfile`, targets `app` and `web`; verified locally end to end)
- The `app` target is PHP-FPM; the same image runs the worker with `messenger:consume`.
- No secrets are baked in: the image's `.env` is `.docker/php/env.defaults`, and secrets come from the host env file. Composer comes from the official `composer:2` image.
- The image runs as non-root (nobody / nginx).
- `upload_tmp_dir` is on `/media`, so in-flight uploads land on the encrypted volume.
- nginx streams uploads to FPM (no body buffering), drops unknown Host headers, logs paths without the query string, and serves `/_media/` as internal only.
- Trusted proxies: none. Trusted hosts: the two names.

**Deploy (Podman Quadlet, `deploy/quadlet/`)**
- Units: `phantasos-{app,worker,web}.container` plus `phantasos.network`.
- Images: `appdev-registry.apps.cascadetech.org/appdev-phi/phantasos-{app,web}:<tag>`.
- `deploy/compose.yaml` runs the same stack (used for the local smoke test).

Host prerequisites (handoff):
- `/etc/phantasos/phantasos.env`, root-owned, mode 600 (template: `deploy/phantasos.env.example`).
- `/etc/phantasos/tls/{fullchain,privkey}.pem`.
- `/media/phantasos` and `/media/.upload-tmp` writable by uid 65534 (`nobody` in Alpine). The rsync from the old server must set ownership to match, e.g. `--chown=65534:65534`.
- SELinux fcontext on `/media` (no `:Z`).
- A Harbor robot login for podman.
- Certificate renewal hook: `podman exec phantasos-web nginx -s reload`.
