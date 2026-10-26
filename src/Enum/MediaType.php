<?php

namespace App\Enum;

/** Values are the legacy strings returned in mediaInfo's `type` field — do not change. */
enum MediaType: string
{
    case Image = 'image';
    case Pdf   = 'pdf';
    case Word  = 'word';
    case Excel = 'excel';
    case Audio = 'audio';
    case Video = 'video';
}
