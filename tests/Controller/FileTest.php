<?php

namespace App\Tests\Controller;

use App\Entity\File;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\FakeVirusScanner;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FileTest extends ApiTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function upload(string $name, string $content, array $fields = []): \Symfony\Component\HttpFoundation\Response
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $this->api('POST', '/api/storage/upload/', files: ['file' => new UploadedFile($path, $name, null, null, true)], parameters: $fields);
    }

    /** storage/tests.py: test_file_upload */
    public function testFileUpload(): void
    {
        $response = $this->upload('test.txt', 'Test file content');

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame('user_files/test.txt', $body['file']);
        self::assertSame($this->userId, $body['owner']);
        self::assertSame('private', $body['access_level']);
        self::assertNotEmpty($this->em()->getRepository(File::class)->findBy(['owner' => $this->userId]));
    }

    public function testUploadWithTitleAccessLevelAndSharing(): void
    {
        $response = $this->upload('notes.txt', 'hello', [
            'title' => 'My notes',
            'access_level' => 'shared',
            'shared_with' => [(string) $this->otherUserId],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame('My notes', $body['title']);
        self::assertSame('shared', $body['access_level']);
        self::assertSame([$this->otherUserId], $body['shared_with']);
    }

    public function testUploadNameCollisionGetsSuffix(): void
    {
        $first = $this->json($this->upload('same.txt', 'one'));
        $second = $this->json($this->upload('same.txt', 'two'));

        self::assertSame('user_files/same.txt', $first['file']);
        self::assertMatchesRegularExpression('#^user_files/same_[0-9a-f]{7}\.txt$#', $second['file']);
    }

    public function testUploadStripsPathFromFileName(): void
    {
        $body = $this->json($this->upload('../../etc/evil.txt', 'nope'));

        self::assertSame('user_files/evil.txt', $body['file']);
    }

    public function testUploadRejectsVirus(): void
    {
        $response = $this->upload('infected.txt', 'hello '.FakeVirusScanner::MARKER);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['File upload rejected due to virus detection!'], $this->json($response));
        self::assertEmpty($this->em()->getRepository(File::class)->findAll());
    }

    public function testUploadRejectsUnsupportedType(): void
    {
        // GIF magic bytes: detected from content, whatever the client claims.
        $response = $this->upload('picture.png', "GIF89a\x01\x00\x01\x00\x00\x00\x00;");

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['file' => ['Unsupported file type: image/gif']], $this->json($response));
    }

    public function testUploadRejectsTooLargeFile(): void
    {
        $response = $this->upload('big.txt', str_repeat('a', 2 * 1024 * 1024 + 1));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['file' => ['File is too large (max 02 MB).']], $this->json($response));
    }

    public function testUploadRequiresFile(): void
    {
        $response = $this->api('POST', '/api/storage/upload/', parameters: ['title' => 'x']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['file' => ['No file was submitted.']], $this->json($response));
    }

    public function testUploadRequiresAuthentication(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, 'x');
        $this->tempFiles[] = $path;

        $response = $this->api('POST', '/api/storage/upload/', token: '', files: ['file' => new UploadedFile($path, 'a.txt', null, null, true)]);

        self::assertSame(401, $response->getStatusCode());
    }

    /** storage/tests.py: test_file_download */
    public function testFileDownload(): void
    {
        $fileId = $this->createStoredFile($this->userId, 'dl.txt', 'download me');

        $response = $this->api('GET', "/api/storage/download/$fileId/");

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('download me', $this->downloadedContent());
        self::assertStringContainsString('attachment; filename=dl.txt', (string) $response->headers->get('Content-Disposition'));
    }

    /** storage/tests.py: test_access_logging_middleware (+ asserts the 403 and the log line) */
    public function testDownloadOfOthersPrivateFileIsForbiddenAndLogged(): void
    {
        $hacker = $this->createUser('hacker', 'hackerpass');
        $fileId = $this->createStoredFile($hacker->getId(), 'secret.txt', 'secret content');

        $response = $this->api('GET', "/api/storage/download/$fileId/");

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['detail' => 'You do not have permission to access this file.'], $this->json($response));

        $logs = glob(static::getContainer()->getParameter('kernel.logs_dir').'/access*.log');
        self::assertNotEmpty($logs);
        $log = implode("\n", array_map('file_get_contents', $logs));
        self::assertStringContainsString("Path: /api/storage/download/$fileId/ | Method: GET", $log);
        self::assertStringContainsString('Status: 403 | User: testuser', $log);
    }

    public function testDownloadOfSharedAndPublicFiles(): void
    {
        $sharedId = $this->createStoredFile($this->otherUserId, 'shared.txt', 'shared content');
        $file = $this->em()->find(File::class, $sharedId);
        $file->shareWith($this->user($this->userId));
        $this->em()->flush();
        $publicId = $this->createStoredFile($this->otherUserId, 'public.txt', 'public content', File::ACCESS_PUBLIC);

        self::assertSame(200, $this->api('GET', "/api/storage/download/$sharedId/")->getStatusCode());
        self::assertSame('shared content', $this->downloadedContent());
        self::assertSame(200, $this->api('GET', "/api/storage/download/$publicId/")->getStatusCode());
        self::assertSame('public content', $this->downloadedContent());
    }

    public function testDownloadUnknownFileReturns404(): void
    {
        $response = $this->api('GET', '/api/storage/download/999/');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['detail' => 'No File matches the given query.'], $this->json($response));
    }

    public function testAccessibleFileList(): void
    {
        $own = $this->createStoredFile($this->userId, 'own.txt', 'a');
        $public = $this->createStoredFile($this->otherUserId, 'pub.txt', 'b', File::ACCESS_PUBLIC);
        $shared = $this->createStoredFile($this->otherUserId, 'shr.txt', 'c');
        $hidden = $this->createStoredFile($this->otherUserId, 'hidden.txt', 'd');
        $file = $this->em()->find(File::class, $shared);
        $file->shareWith($this->user($this->userId));
        $this->em()->flush();

        $response = $this->api('GET', '/api/storage/files/');

        self::assertSame(200, $response->getStatusCode());
        $ids = array_column($this->json($response), 'id');
        self::assertSame([$own, $public, $shared], $ids);
        self::assertNotContains($hidden, $ids);
    }
}
