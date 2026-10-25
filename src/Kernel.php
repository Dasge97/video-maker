<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Dates of job.json, the logs and the screens in the time of the person.
        date_default_timezone_set('Europe/Madrid');
        parent::boot();
    }
}
