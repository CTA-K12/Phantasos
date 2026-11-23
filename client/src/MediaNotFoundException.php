<?php

declare(strict_types=1);

namespace App\Phantasos;

/** The media or file doesn't exist, or belongs to another app (Phantasos doesn't say which). */
class MediaNotFoundException extends PhantasosException
{
}
