<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;

final class AuthTest extends ApiTestCase
{
    /** storage/tests.py: test_token_authentication */
    public function testTokenAuthentication(): void
    {
        $response = $this->api('POST', '/api/token/', ['username' => 'testuser', 'password' => 'testpass'], token: '');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertArrayHasKey('access', $body);
        self::assertArrayHasKey('refresh', $body);
    }

    public function testTokenWithWrongPasswordIsRejected(): void
    {
        $response = $this->api('POST', '/api/token/', ['username' => 'testuser', 'password' => 'nope'], token: '');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['detail' => 'No active account found with the given credentials'], $this->json($response));
    }

    public function testRefreshTokenReturnsNewAccessToken(): void
    {
        $login = $this->json($this->api('POST', '/api/token/', ['username' => 'testuser', 'password' => 'testpass'], token: ''));

        $response = $this->api('POST', '/api/token/refresh/', ['refresh' => $login['refresh']], token: '');

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('access', $this->json($response));

        // The new access token works on a protected endpoint.
        self::assertSame(200, $this->api('GET', '/api/storage/files/', token: $this->json($response)['access'])->getStatusCode());
    }

    public function testInvalidRefreshTokenIsRejected(): void
    {
        $response = $this->api('POST', '/api/token/refresh/', ['refresh' => 'not-a-real-token'], token: '');

        self::assertSame(401, $response->getStatusCode());
    }

    public function testProtectedEndpointRequiresToken(): void
    {
        $response = $this->api('GET', '/api/storage/files/', token: '');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['detail' => 'Authentication credentials were not provided.'], $this->json($response));
    }

    public function testInvalidTokenIsRejected(): void
    {
        $response = $this->api('GET', '/api/storage/files/', token: 'garbage.token.value');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('token_not_valid', $this->json($response)['code']);
    }

    public function testRegister(): void
    {
        $response = $this->api('POST', '/api/storage/register/', ['username' => 'newuser', 'password' => 's3cret!'], token: '');

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('newuser', $body['username']);
        self::assertArrayNotHasKey('password', $body);
        self::assertNotNull($this->em()->getRepository(User::class)->findOneBy(['username' => 'newuser']));

        // The new account can log in.
        $login = $this->api('POST', '/api/token/', ['username' => 'newuser', 'password' => 's3cret!'], token: '');
        self::assertSame(200, $login->getStatusCode());
    }

    public function testRegisterRejectsDuplicateUsername(): void
    {
        $response = $this->api('POST', '/api/storage/register/', ['username' => 'testuser', 'password' => 'x'], token: '');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['username' => ['A user with that username already exists.']], $this->json($response));
    }

    public function testRegisterRequiresFields(): void
    {
        $response = $this->api('POST', '/api/storage/register/', [], token: '');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'username' => ['This field is required.'],
            'password' => ['This field is required.'],
        ], $this->json($response));
    }

    public function testLoginIsRateLimitedPerIp(): void
    {
        // DRF AnonRateThrottle: 5/minute
        for ($i = 0; $i < 5; ++$i) {
            $this->api('POST', '/api/token/', ['username' => 'testuser', 'password' => 'wrong'], token: '');
        }
        $response = $this->api('POST', '/api/token/', ['username' => 'testuser', 'password' => 'testpass'], token: '');

        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($response->headers->has('Retry-After'));
        self::assertStringStartsWith('Request was throttled.', $this->json($response)['detail']);
    }
}
