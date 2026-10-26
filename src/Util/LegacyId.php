<?php

namespace App\Util;

/**
 * Media and media-file IDs in the 24-hex-character MongoDB ObjectId format.
 *
 * Consumers store `media_id` / `media_file_id` values from the legacy system, some in
 * columns sized for 24 characters, and the on-disk layout is derived from the media ID.
 * Imported rows keep their original ObjectIds; new rows get freshly generated ones in
 * the same format (4-byte big-endian Unix time + 8 random bytes) so the two never collide
 * in practice and still sort roughly by creation time.
 */
final class LegacyId
{
    public const PATTERN = '[0-9a-f]{24}';

    public static function generate(): string
    {
        return bin2hex(pack('N', time()) . random_bytes(8));
    }

    public static function isValid(string $id): bool
    {
        return 1 === preg_match('/^' . self::PATTERN . '$/D', $id);
    }

    /**
     * Directory for a media item relative to the media root: `abcde/fghij/klmno/<id>/`.
     * Identical to the legacy LocalFileKey so migrated files keep their paths.
     */
    public static function storagePath(string $mediaId): string
    {
        return substr($mediaId, 0, 5) . '/'
            . substr($mediaId, 5, 5) . '/'
            . substr($mediaId, 10, 5) . '/'
            . $mediaId . '/';
    }
}
