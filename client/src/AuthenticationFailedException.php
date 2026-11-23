<?php

declare(strict_types=1);

namespace App\Phantasos;

/** Phantasos rejected the API key: wrong, rotated or deactivated. A configuration error, not a user error. */
class AuthenticationFailedException extends PhantasosException
{
}
