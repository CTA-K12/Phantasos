<?php

declare(strict_types=1);

namespace App\Phantasos;

/** mediaInfo `status`. The values are the strings the API returns. */
enum ProcessingStatus: string
{
    /** Ticket issued but nothing uploaded yet, or uploaded and waiting for the worker. */
    case Queued     = 'In queue';
    case Processing = 'Processing';
    case Ready      = 'Ready';
    /** The original is kept (and downloadable) but renditions could not be made. */
    case Failed     = 'Failed to process';
}
