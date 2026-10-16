<?php

namespace App\Controller;

use App\Http\ApiResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The JWT endpoints themselves are handled by the security firewall (see config/packages/security.yaml):
 * the "login" firewall's json_login handles POST /api/token/ and the "api" firewall's refresh_jwt handles
 * POST /api/token/refresh/. These actions only run when the request could not be handled there.
 */
#[AsController]
final class AuthController
{
    #[Route('/api/token/', name: 'api_token', methods: ['POST'])]
    public function token(): ApiResponse
    {
        return new ApiResponse(
            ['detail' => 'Send the credentials as a JSON body: {"username": "...", "password": "..."}.'],
            Response::HTTP_BAD_REQUEST,
        );
    }

    #[Route('/api/token/refresh/', name: 'api_token_refresh', methods: ['POST'])]
    public function refresh(): ApiResponse
    {
        return new ApiResponse(['refresh' => ['This field is required.']], Response::HTTP_BAD_REQUEST);
    }
}
