<?php

namespace App\Tests\Controller;

use App\Entity\File;
use App\Entity\PublicShareLink;
use App\Tests\Support\ApiTestCase;

final class ShareTest extends ApiTestCase
{
    public function testShareFileWithUser(): void
    {
        $fileId = $this->createStoredFile($this->userId, 'mine.txt', 'x');

        $response = $this->api('POST', '/api/storage/share/', ['file_id' => $fileId, 'share_with_username' => 'otheruser']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['message' => 'File shared with otheruser successfully!'], $this->json($response));
        self::assertTrue($this->em()->find(File::class, $fileId)->isSharedWith($this->user($this->otherUserId)));

        // otheruser can now download it.
        $otherToken = $this->tokenFor($this->user($this->otherUserId));
        self::assertSame(200, $this->api('GET', "/api/storage/download/$fileId/", token: $otherToken)->getStatusCode());
    }

    public function testCannotShareSomeoneElsesFile(): void
    {
        $fileId = $this->createStoredFile($this->otherUserId, 'theirs.txt', 'x');

        $response = $this->api('POST', '/api/storage/share/', ['file_id' => $fileId, 'share_with_username' => 'testuser']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['permission denied' => ['You do not have permission to share this file']], $this->json($response));
    }

    public function testShareWithUnknownUser(): void
    {
        $fileId = $this->createStoredFile($this->userId, 'mine.txt', 'x');

        $response = $this->api('POST', '/api/storage/share/', ['file_id' => $fileId, 'share_with_username' => 'nobody']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['share_with_errors' => ['User to share with not found']], $this->json($response));
    }

    public function testShareValidatesFields(): void
    {
        $response = $this->api('POST', '/api/storage/share/', ['file_id' => 'abc']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'file_id' => ['A valid integer is required.'],
            'share_with_username' => ['This field is required.'],
        ], $this->json($response));
    }

    /** storage/tests.py: test_file_share_link_creation */
    public function testFileShareLinkCreation(): void
    {
        $fileId = $this->createStoredFile($this->userId, 'testfile.txt', 'test content');

        $response = $this->api('POST', "/api/storage/share/$fileId/", ['expires_in_minutes' => 2]);

        self::assertSame(201, $response->getStatusCode());
        $link = $this->json($response)['share_link'];
        self::assertMatchesRegularExpression('#^http://localhost/api/storage/public/[0-9a-f-]{36}/$#', $link);

        // The link works without authentication.
        self::assertSame(200, $this->api('GET', parse_url($link, \PHP_URL_PATH), token: '')->getStatusCode());
        self::assertSame('test content', $this->downloadedContent());
    }

    public function testShareLinkForSomeoneElsesFileIsNotFound(): void
    {
        $fileId = $this->createStoredFile($this->otherUserId, 'theirs.txt', 'x');

        $response = $this->api('POST', "/api/storage/share/$fileId/", ['expires_in_minutes' => 2]);

        self::assertSame(404, $response->getStatusCode());
        self::assertEmpty($this->em()->getRepository(PublicShareLink::class)->findAll());
    }

    public function testShareLinkRequiresExpiry(): void
    {
        $fileId = $this->createStoredFile($this->userId, 'mine.txt', 'x');

        $response = $this->api('POST', "/api/storage/share/$fileId/", []);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['expires_in_minutes' => ['This field is required.']], $this->json($response));
    }

    /** storage/tests.py: test_expired_public_link */
    public function testExpiredPublicLink(): void
    {
        $fileId = $this->createStoredFile($this->userId, 'exp.txt', 'expire test');
        $token = $this->createLink($fileId, '-1 minute');

        $response = $this->api('GET', "/api/storage/public/$token/", token: '');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['detail' => 'Link expired'], $this->json($response));
    }

    public function testUnknownPublicLink(): void
    {
        $response = $this->api('GET', '/api/storage/public/3f2504e0-4f89-41d3-9a0c-0305e82c3301/', token: '');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['detail' => 'Invalid link'], $this->json($response));
    }

    public function testMalformedPublicLinkIsNotFound(): void
    {
        self::assertSame(404, $this->api('GET', '/api/storage/public/not-a-uuid/', token: '')->getStatusCode());
    }

    public function testPublicDownloadIsRateLimited(): void
    {
        $fileId = $this->createStoredFile($this->userId, 'popular.txt', 'x');
        $token = $this->createLink($fileId, '+1 hour');

        // Anonymous: AnonRateThrottle 5/minute applies first.
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(200, $this->api('GET', "/api/storage/public/$token/", token: '')->getStatusCode());
        }
        self::assertSame(429, $this->api('GET', "/api/storage/public/$token/", token: '')->getStatusCode());
    }
}
