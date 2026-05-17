<?php

declare(strict_types=1);

namespace App\Controller;

use App\Phel\PhelApp;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PhelController
{
    public function __construct(private readonly PhelApp $app) {}

    #[Route('/{any}', requirements: ['any' => '.*'], priority: -100)]
    public function __invoke(Request $request): Response
    {
        return $this->app->handle($request);
    }
}
