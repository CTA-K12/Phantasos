<?php

namespace App\Message;

/** Queued after an upload is accepted; the worker generates the renditions. */
final readonly class ProcessMedia
{
    public function __construct(
        public string $mediaId,
    ) {
    }
}
