<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Applied here rather than in public/index.php so the web, the Messenger worker and
     * the test suite all agree. Timestamps are stored without a zone and the legacy API
     * reports ticket expiry in Pacific time.
     */
    public function boot(): void
    {
        $tz = $_SERVER['APP_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? 'America/Los_Angeles';
        date_default_timezone_set(is_string($tz) && $tz !== '' ? $tz : 'America/Los_Angeles');

        parent::boot();
    }
}
