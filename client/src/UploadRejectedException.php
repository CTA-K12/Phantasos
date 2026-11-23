<?php

declare(strict_types=1);

namespace App\Phantasos;

/**
 * Phantasos refused the file, most often because it can't process the type
 * (`Filetype "text/plain" is not supported.`). The message is safe to show the user.
 */
class UploadRejectedException extends PhantasosException
{
}
