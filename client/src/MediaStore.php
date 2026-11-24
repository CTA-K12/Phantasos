<?php

declare(strict_types=1);

namespace App\Phantasos;

/**
 * Where an app keeps its media. Production uses PhantasosClient; development and tests can use
 * LocalMediaStore, which needs no Phantasos server and no API key.
 *
 * Copied from the Phantasos repository (client/). Keep it in step with the copy there.
 */
interface MediaStore
{
    /*
     * The file types Phantasos accepts (it sniffs the content, not the name). Anything else is
     * rejected, including SVG, Word, Excel, text and HTML. Use ACCEPTED_TYPES in a File constraint
     * to reject early. Phantasos's tests keep these in step with its processors.
     */
    public const IMAGE_TYPES = [
        'image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
        'image/heic', 'image/heif', 'image/tiff',
        'image/bmp', 'image/x-windows-bmp', 'image/x-ms-bmp',
        'image/x-xbitmap', 'image/x-xbm', 'image/xbm', 'image/xpm', 'image/x-xpixmap',
    ];
    public const PDF_TYPES = ['application/pdf', 'application/x-pdf'];
    public const AUDIO_TYPES = [
        'audio/mpeg', 'audio/mpeg3', 'audio/x-mpeg-3', 'audio/x-mpeg', 'audio/mp4', 'audio/x-m4a',
        'audio/ogg', 'application/ogg', 'audio/vorbis', 'audio/flac', 'audio/x-flac', 'audio/wav',
        'audio/x-wav', 'audio/vnd.wave', 'audio/webm', 'audio/vnd.rn-realaudio',
    ];
    public const VIDEO_TYPES = [
        'video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v', 'video/mpeg', 'video/ogg',
        'video/3gpp', 'video/avi', 'video/msvideo', 'video/x-msvideo', 'video/x-ms-wmv', 'video/x-ms-asf',
        'video/x-matroska', 'video/x-flv', 'video/x-dv', 'application/x-winamp',
    ];
    public const ACCEPTED_TYPES = [...self::IMAGE_TYPES, ...self::PDF_TYPES, ...self::AUDIO_TYPES, ...self::VIDEO_TYPES];
    public const MAX_SIZE = '1024M';

    /**
     * Stores a file and returns its media ID (24 hex characters). Keep that ID: it is the only
     * thing the app needs to store. Processing (thumbnails, MP3/MP4 renditions) continues in the
     * background; check info()->ready before relying on renditions.
     *
     * @param string      $path        a readable local file, e.g. UploadedFile::getPathname()
     * @param string|null $fileName    the user's file name; defaults to the basename of $path
     * @param string|null $callbackUrl Phantasos POSTs `media_id` here when processing finishes
     *                                 (unauthenticated: treat it as a hint and call info())
     *
     * @throws UploadRejectedException when Phantasos refuses the file (unsupported type)
     * @throws PhantasosException      on any other failure
     */
    public function upload(string $path, ?string $fileName = null, ?string $callbackUrl = null): string;

    /**
     * @throws MediaNotFoundException when the media doesn't exist or belongs to another app
     * @throws PhantasosException     on any other failure
     */
    public function info(string $mediaId): MediaInfo;

    /**
     * Opens a file for streaming. Pass the browser's Range header through so video seeking works.
     *
     * @throws MediaNotFoundException when the file doesn't exist or belongs to another app
     * @throws PhantasosException     on any other failure
     */
    public function fetch(string $mediaFileId, ?string $range = null): FileStream;
}
