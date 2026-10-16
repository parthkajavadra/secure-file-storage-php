<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Browser page for testing the API by hand: http://127.0.0.1:8000/tester
 * Only available when APP_ENV=dev: the route is defined in config/routes/dev/tester.yaml,
 * which Symfony loads in the dev environment only (404 in prod and test).
 */
#[AsController]
final class DevTesterController
{
    public function __invoke(#[Autowire('%kernel.project_dir%')] string $projectDir): BinaryFileResponse
    {
        $response = new BinaryFileResponse($projectDir.'/tools/tester.html');
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
