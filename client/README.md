# Using Phantasos from a Symfony app

Phantasos stores an app's media (images, PDFs, audio, video), makes renditions (thumbnails,
web-sized images, MP3s, MP4s with poster frames), and serves the files back. It is safe for PHI:
storage is encrypted (LUKS), the database is encrypted (pg_tde), traffic is TLS, backups are
encrypted, every key can only read its own app's media, and every file served is audited.

This folder holds a **reference client** (`src/`) and this guide. It is tested in the Phantasos
repo (`tests/Client/`) against the real API. Copy it into your app; don't write your own.

## The rules

1. **Server-side only.** Your app receives uploads and streams files back to its users itself.
   The browser never gets a Phantasos URL or the API key. Your app decides who may see what.
2. **The key is a secret.** It goes in a k8s secret, read from `PHANTASOS_API_KEY`. The client sends
   it in the `X-Api-Key` header, never in a URL, and hides it from dumps. Never log it.
3. **One Phantasos client per app per environment** (prod, QA, demo each get their own key). A key
   can only see media uploaded with that key, so environments can't read each other's files.
4. **Store only the media ID** (24 hex characters) on your entity. File IDs come from `info()`.
5. **PHI apps audit every view and download in their own audit log** (who, which record, which file),
   before streaming. Phantasos also logs every file it serves, per app, but it doesn't know your users.

## Setup

```bash
composer require symfony/http-client symfony/mime
mkdir -p src/Phantasos && cp ~/projects/Phantasos/client/src/*.php src/Phantasos/
```

The namespace is `App\Phantasos`. Keep the files unchanged so they can be refreshed from here.

`.env` (defaults; the real key goes in the environment's secret):

```dotenv
PHANTASOS_URL=https://phantasos.apps.cascadetech.org
PHANTASOS_API_KEY=
```

`config/services.yaml`:

```yaml
services:
    # ...
    App\Phantasos\PhantasosClient:
        arguments:
            $baseUrl: '%env(PHANTASOS_URL)%'
            $apiKey: '%env(PHANTASOS_API_KEY)%'
    App\Phantasos\MediaStore: '@App\Phantasos\PhantasosClient'

when@dev: &local_media
    services:
        App\Phantasos\LocalMediaStore:
            arguments: ['%kernel.project_dir%/var/phantasos']
        App\Phantasos\MediaStore: '@App\Phantasos\LocalMediaStore'

when@test: *local_media
```

Inject `MediaStore` everywhere. Development and tests then use `LocalMediaStore`: no server, no key,
no network. It accepts and rejects the same file types Phantasos does, but makes no renditions
(every item is Ready at once with only its original). To try the real service from a dev machine,
point `MediaStore` at `PhantasosClient` in `.env.local` / `services_dev.yaml` with a **non-prod key**.

**Getting a key:** an admin creates the client in the Phantasos management site (Clients & Keys).
The key is shown once; put it straight into the environment's secret. Name clients by app and
environment, e.g. `ORMed`, `ORMed QA`, `ORMed Demo`.

**Network:** pods need egress to `phantasos.apps.cascadetech.org` (198.236.76.21) on 443. Check
your namespace's NetworkPolicy.

## Uploading

```php
use App\Phantasos\MediaStore;
use App\Phantasos\UploadRejectedException;

public function upload(Request $request, MediaStore $media): Response
{
    // ... a Symfony form with a FileType field, validated with a File constraint (below)
    /** @var UploadedFile $file */
    $file = $form->get('file')->getData();

    try {
        $mediaId = $media->upload($file->getPathname(), $file->getClientOriginalName());
    } catch (UploadRejectedException $e) {
        $form->get('file')->addError(new FormError($e->getMessage())); // safe to show
        // re-render the form
    }

    $document->setMediaId($mediaId);   // the only thing to store
    $document->setFileName($file->getClientOriginalName()); // Phantasos doesn't keep it
    // flush, audit, redirect
}
```

Validate before uploading, so users get a clear message without a round trip:

```php
new Assert\File(maxSize: MediaStore::MAX_SIZE, mimeTypes: MediaStore::ACCEPTED_TYPES,
    mimeTypesMessage: 'Upload a PDF, a JPEG or PNG image, or an audio or video file.')
```

`ACCEPTED_TYPES` (and `IMAGE_TYPES`, `PDF_TYPES`, `AUDIO_TYPES`, `VIDEO_TYPES`) are exactly what
Phantasos accepts; its tests fail if they drift. Phantasos sniffs the content, not the file name.
Images include phone photos: JPEG, PNG, GIF, WebP, AVIF, **HEIC/HEIF** (iPhone) and TIFF (scanners;
first page only). Renditions come out upright and carry no metadata; the **original is stored unchanged,
including any location the phone recorded**. Phantasos does **not** accept SVG,
Word, Excel, text, HTML or ZIP files: convert to PDF first, or keep those elsewhere. The limit is
1 GB per file. Phantasos doesn't store the original file name, so keep it on your own entity.

**Originals of HEIC, TIFF and AVIF** can't be shown by every browser (Chrome can't show HEIC). Show
the PNG renditions (`large.png` is 1024×768) and offer the original as a download.

The upload returns once the file is stored. Renditions follow in the background (seconds for
images, minutes for long videos). Pass a `$callbackUrl` only if you must react the moment
processing ends; the callback is unauthenticated, so treat it as a hint and call `info()`.

## Showing and downloading

```php
public function file(Document $document, string $fileName, Request $request, MediaStore $media): Response
{
    $this->denyAccessUnlessGranted('VIEW', $document);   // your rules

    $info = $media->info($document->getMediaId());
    $file = $fileName === 'original' ? $info->original() : $info->file($fileName);
    if ($file === null) {
        throw $this->createNotFoundException();          // not processed (yet), or no such rendition
    }

    $this->audit->log('document.file_viewed', $document, ['file' => $file->fileName]); // PHI apps

    return $media->fetch($file->id, $request->headers->get('Range'))
        ->toResponse($document->getFileName(), inline: !$request->query->getBoolean('download'));
}
```

`toResponse()` streams in chunks (large videos never sit in memory), passes Range requests through
so video seeking works, sets `Cache-Control: no-store`, forces a download for anything a browser
could execute (HTML, SVG and other non-media types are never shown inline), and adds a sandbox CSP.

In templates, link to your own route, never to Phantasos:

```twig
<img src="{{ path('document_file', {id: doc.id, fileName: 'thumbnail.png'}) }}" alt="">
<video controls preload="metadata" poster="{{ path('document_file', {id: doc.id, fileName: 'small-frame.png'}) }}">
    <source src="{{ path('document_file', {id: doc.id, fileName: 'small.mp4'}) }}" type="video/mp4">
</video>
<a href="{{ path('document_file', {id: doc.id, fileName: 'original', download: 1}) }}">Download</a>
```

For server-side use (attach to an email, merge into a PDF): `->saveTo($path)`, or `->contents()`
for small files.

## What you get back

`info()` returns a `MediaInfo`: `ready`, `status` (`Queued`, `Processing`, `Ready`, `Failed`),
`processingPercentage`, `type` (`image`, `pdf`, `audio`, `video`) and `files`, the original first:

| Type | Files |
|---|---|
| image (JPEG, PNG, GIF, WebP, AVIF, HEIC, TIFF, BMP) | `original.*`, `thumbnail.png` (75×75), `small.png` (320×240), `medium.png` (640×480), `large.png` (1024×768) |
| pdf | `original.pdf` |
| audio | `original.*`, `low.mp3` (64 kbps), `high.mp3` (192 kbps) |
| video | `original.*`, `small-frame.png`, `small.mp4` (640×360), `large-frame.png`, `large.mp4` (1280×720) |

`files` is empty until processing finishes. `Failed` means renditions could not be made; the
original is still kept and downloadable from the Phantasos management site, but not listed.

## Errors

| Exception | When | What to do |
|---|---|---|
| `UploadRejectedException` | unsupported type | show the message to the user |
| `MediaNotFoundException` | unknown ID, or another app's media | 404 |
| `AuthenticationFailedException` | wrong, rotated or deactivated key | configuration error: alert, 503 |
| `PhantasosException` (base class) | network errors, 5xx | log it (it never contains the key), 503 |

## Deleting

There is no delete API yet. Retention is managed in Phantasos (PHI is kept 96 months); a retention
screen is planned. If your app deletes a record, keep or archive its media ID.

## Testing

Use `LocalMediaStore` (the `when@test` config above). For unit tests, construct it with a temp
directory. Don't call the real Phantasos from CI.

## Updating the client

The copy in Phantasos (`client/src/`) is the reference and is tested on every Phantasos build.
To update an app, copy the files again; don't edit them in the app.
