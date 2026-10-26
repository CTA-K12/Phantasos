# Phantasos

Media upload / processing / storage / delivery API for Cascade Technology Alliance (CTA) apps.
Consumers (Online Training, HealthEd Trainings MESD/NWRESD, DocGuard, SubDocs) get an upload
ticket, the end user's browser uploads the file, a worker generates renditions, and consumers read
`mediaInfo` and stream files with `requestMedia`.

This is a rebuild of a 2015 Symfony 2.6 / MongoDB / RabbitMQ system. **Read `AI.md` first**: it holds
the history, inventory, decisions (§13) and as-built notes (§14). `TODO.md` is the shared task list.
The legacy code is at the git tag `legacy-final` (`git show legacy-final:src/...`).

## Rules that matter most

- **The public API is a frozen contract.** Paths, parameters, status codes, bodies (byte for byte),
  header names and file names must match legacy. `tests/Contract/` replays recorded legacy responses —
  if you change API output, those tests must still pass, or the difference must be a deliberate,
  documented security fix (AI.md §14 lists them).
- **Ownership on every read.** A key may only see and download its own client's media.
- **Media / media-file IDs are 24-hex legacy ObjectIds**, not UUIDs (`App\Util\LegacyId`). They are
  public IDs stored by consumers and they determine the disk path. Other entities use UUID v7.
- **API keys are stored as SHA-256 only.** Never log, print or store a plaintext key. Legacy consumers
  send keys in the query string, so never log query strings (nginx logs the path only).
- **Never commit secrets.** Runtime config comes from the environment; `.env*` files other than
  `.env.example`/`.env.test` are gitignored, as is `migration/secrets/`.
- **Fixtures never touch appdev-pg-prod**: `doctrine:fixtures:load` purges every table, so
  `FixturesGuard` refuses any non-local database host.
- **The test suite must never point at appdev-pg-prod.** It uses the local container's
  `phantasos_dev_test` database.
- Keep personal names and "who decided" phrasing out of tracked files; state decisions with dates.

## Stack

- Symfony 7.4 LTS, PHP 8.4 in production (Alpine `php84`), PHP 8.5 locally; Doctrine ORM 3.
- PostgreSQL 17: Percona `appdev-pg-prod.apps.cascadetech.org`, database `phantasos` (pg_tde,
  TLS `verify-full`). Local dev: `docker-compose-dev-db.yml` on port 5448.
- Symfony Messenger with the **Doctrine transport** (queue = `messenger_messages`); no RabbitMQ.
- ffmpeg/ffprobe (audio/video), GD (images), via `Symfony\Component\Process` — no PHP media bindings.
- Containers: `.docker/Dockerfile` targets `app` (PHP-FPM, and the worker via `messenger:consume`)
  and `web` (nginx). Runs under Podman Quadlet on `phantasos.apps.cascadetech.org` (`deploy/`).
- Management site (internal networks only, enforced by nginx): Google OAuth sign-in, `app_user`
  roles, AuditLogger — from the CTA starter — with the ORMed v2 / HealthEd layout (Pico 2,
  flexboxgrid, htmx + hyperscript, static files in `public/`; no AssetMapper, Bootstrap or Turbo).

## Layout

```
src/Controller/Api/ApiController.php   requestUploadTicket, uploadMedia/{ticketId}, mediaInfo, requestMedia
src/Security/ApiKeyAuthenticator.php   X-Api-Key header | ?apikey= | apikey form field → ApiClient
src/Entity/                            ApiClient, Media, MediaFile (+ starter User, AppSetting, AuditLog)
src/Enum/                              MediaStatus, MediaType — values are legacy API strings
src/Service/MediaStorage.php           disk layout under MEDIA_ROOT
src/Service/MediaInfoPresenter.php     legacy mediaInfo JSON shape
src/Service/MediaFileResponder.php     X-Accel-Redirect (prod) / BinaryFileResponse (dev, tests)
src/Processor/                         Image, Pdf, Audio, Video (tag app.processor)
src/MessageHandler/ProcessMediaHandler.php   the worker
src/Controller/Admin/                  Clients & Keys, Processing (retry), Users, Audit Log (ROLE_ADMIN)
templates/base.html.twig               signed-in shell (sidebar, breadcrumbs, help panel, confirm modal)
templates/public.html.twig             pre-sign-in shell; macros.html.twig = icons, iconBtn, confirmAction
migration/                             Python tooling (own venv at .venv): legacy inspection/purge,
                                       capture_contract.py, import_to_pg.py, parity_check.py
tests/Contract/                        legacy contract replay (fixtures/legacy/*.json)
deploy/                                Quadlet units, compose file, runtime env template
```

## Adding a processor

Implement `App\Processor\ProcessorInterface` (media type, sniffed MIME types, `process()`); it is
auto-tagged. Write renditions into `MediaStorage::mediaDirectory($media)`, register each with
`MediaStorage::addRendition()` **in the order mediaInfo should list them** (the original is always
position 0), and drive `ProgressReporter` phases. The handler sets Ready/100 and sends the callback.

## Management UI conventions (ORMed v2 layout)

- Pages extend `base.html.twig` and fill `breadcrumbs`, optional `breadcrumb_actions` (buttons with
  class `breadcrumb-action-btn`), and `body`. Content goes in `<article>`; tables are
  `<figure><table role="grid">`.
- Icons: `macros.icon('name')` (inline Font Awesome Free SVGs; copy more from ORMed2's macros).
  Row actions: `macros.iconBtn(...)`. Every POST action goes through `macros.confirmAction` /
  `confirmActionButton` (shared modal, CSRF token, optional reason) — never `window.confirm`.
- Flash types: `success`, `warning`, `error`, `info`.
- Help: `docs/help/<route_name>.md`, shown in the slide-out panel (`?` in the top bar).
- **API keys are displayed once**, in the response to the create/rotate POST (`Cache-Control:
  no-store`), never via flash/session, and never logged — audit metadata holds names only.

## Conventions (from the CTA starter)

- `InputBag`: always typed getters (`getString`, `getInt`, `getBool`), never `get()` — PHPStan level 8.
- Repositories expose `save()`/`flush()`; don't call `getEntityManager()` from outside them.
- `AuditLogger::log('action', 'Entity', $id, $meta)`; add new actions to `ACTION_LABELS`.
  `media.served` is logged for every requestMedia.
- PHP 8.5: `fgetcsv()` needs the explicit `$escape` argument; `ReflectionProperty::setAccessible()`
  is deprecated; `StreamedResponse::getContent()` is false in tests — use
  `$client->getInternalResponse()->getContent()`.
- Functional tests that use the Messenger in-memory transport must `disableReboot()` and must not
  make another request before reading the queue (a request resets it).

## Commands

```bash
docker compose -f docker-compose-dev-db.yml up -d     # local PostgreSQL (dev + _test databases)
php bin/console doctrine:migrations:migrate           # dev schema
APP_ENV=test php bin/console doctrine:migrations:migrate -n && APP_ENV=test php bin/console doctrine:fixtures:load -n
composer test                                         # PHPUnit
composer test:stan                                    # PHPStan level 8
php bin/console messenger:consume async -vv           # run the worker locally
php bin/console app:user:create someone@mesd.k12.or.us --role=ROLE_ADMIN   # bootstrap a sign-in user

# Legacy → PG import (dry run unless --apply). Target prod reads migration/secrets/new-pg.env.
cd migration && ../.venv/bin/python import_to_pg.py --target local [--apply] [--apps Training ...]

# Images (linux/amd64 for the server)
docker build --platform linux/amd64 -f .docker/Dockerfile --target app -t phantasos-app:local .
docker build --platform linux/amd64 -f .docker/Dockerfile --target web -t phantasos-web:local .
```

## Environment

`.env` (gitignored locally) holds dev defaults; `.env.test` the test overrides. Phantasos-specific:
`MEDIA_ROOT`, `MEDIA_ACCEL_PREFIX` (set → X-Accel-Redirect), `FFMPEG_BINARY`, `FFPROBE_BINARY`,
`FFMPEG_THREADS`, `TRUSTED_PROXIES` (empty: nothing in front of nginx), `TRUSTED_HOSTS` (prod).
Production values: `.docker/php/env.defaults` (baked, non-secret) + `/etc/phantasos/phantasos.env`
on the host (secrets; template `deploy/phantasos.env.example`).
