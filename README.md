# Phantasos

Media upload, processing, storage and delivery service for Cascade Technology Alliance applications.

A consuming app asks for an **upload ticket**, the end user's browser uploads the file directly to
Phantasos, a worker generates renditions (image sizes, MP3s, MP4s with poster frames), and the app
reads **mediaInfo** and streams files with **requestMedia**. Each app has its own API key and can only
see its own media.

## API

All endpoints live under `https://phantasos.apps.cascadetech.org/api/` (since the 2026-11-21 cutover;
`phantasos.mesd.k12.or.us` serves the same API once its DNS points at the new host). Send the key as an `X-Api-Key`
header (preferred) or, for existing integrations, as the `apikey` query parameter.

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `requestUploadTicket` | POST | key | Form fields `tags`, `security` (comma lists), `hide_to_others`, `callback_route`. Returns `{upload_route, media_id, expiration}`; the ticket is valid for 2 hours. |
| `uploadMedia/{ticketId}` | POST, OPTIONS | ticket | Browser upload, multipart field `file_data`. CORS-enabled. |
| `mediaInfo?media_id=` | GET | key | Type, status, `processing_percentage` and, once ready, the file list (original first). |
| `requestMedia?media_file_id=` | GET | key | The file itself (supports Range). |

When processing finishes, Phantasos POSTs `media_id` to `callback_route` if one was given.

Supported uploads: images (JPEG, PNG, GIF, WebP, AVIF, HEIC/HEIF, TIFF, BMP, XBM/XPM), PDF, audio (MP3, M4A, Ogg, FLAC, WAV, WebM) and
video (MP4, MOV, WebM, AVI, MKV, WMV, FLV, 3GP, MPEG, Ogg).

## Development

Requires PHP 8.4+, Composer, Docker (for PostgreSQL) and ffmpeg.

```bash
cp .env.example .env                            # then set APP_SECRET (see the file)
docker compose -f docker-compose-dev-db.yml up -d
composer install
php bin/console doctrine:migrations:migrate -n
APP_ENV=test php bin/console doctrine:database:create
APP_ENV=test php bin/console doctrine:migrations:migrate -n
APP_ENV=test php bin/console doctrine:fixtures:load -n
composer test && composer test:stan
symfony serve                                   # API + admin UI
php bin/console messenger:consume async -vv     # worker
```

To sign in to the admin site locally, create yourself a user and either set up Google OAuth
credentials in `.env.local` or use the dev login (`APP_DEV_AUTH_ENABLED=true` in `.env.local`, then
open `/dev-login`):

```bash
php bin/console app:user:create you@mesd.k12.or.us --role=ROLE_ADMIN
```

Apps integrating with Phantasos: use the reference client and guide in `client/` (`client/README.md`).

`CLAUDE.md` has the conventions, and `AI.md` has the architecture, history and decisions.
Deployment (Podman Quadlet on `phantasos.apps.cascadetech.org`) is in `deploy/`.
