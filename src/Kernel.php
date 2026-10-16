<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Store and compare all timestamps in UTC (Django: USE_TZ = True, TIME_ZONE = "UTC").
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
