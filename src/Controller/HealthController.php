<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the deployment asks to know the app is up (codehive and the healthcheck of docker
 * compose). Public and without touching the disk: it only says the PHP side answers.
 */
final class HealthController
{
    #[Route('/salud', name: 'health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response('ok', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }
}
