<?php

namespace App\Enum;

/** Values are the legacy strings returned in mediaInfo's `status` field — do not change. */
enum MediaStatus: string
{
    case Queued     = 'In queue';
    case Processing = 'Processing';
    case Ready      = 'Ready';
    case Failed     = 'Failed to process';
}
