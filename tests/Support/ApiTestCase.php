<?php

namespace App\Tests\Support;

use App\Entity\File;
use App\Entity\PublicShareLink;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Shared setup, equivalent of SecureStorageTests.setUp() in storage/tests.py:
 * a fresh schema, "testuser" + "otheruser", and a JWT for testuser.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected int $userId;
    protected int $otherUserId;
    protected string $accessToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // A unique client IP per test, so rate-limit counters never leak between tests.
        $this->client->setServerParameter('REMOTE_ADDR', sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254)));

        $em = $this->em();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        (new Filesystem())->remove(static::getContainer()->getParameter('app.storage_dir'));

        $user = $this->createUser('testuser', 'testpass');
        $other = $this->createUser('otheruser', 'otherpass');
        $this->userId = $user->getId();
        $this->otherUserId = $other->getId();
        $this->accessToken = $this->tokenFor($user);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(static::getContainer()->getParameter('app.storage_dir'));
        parent::tearDown();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $username, string $password): User
    {
        $user = new User($username);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    protected function user(int $id): User
    {
        return $this->em()->find(User::class, $id);
    }

    protected function tokenFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');

        return $manager->create($user);
    }

    /** Creates a stored file directly (like File.objects.create(..., file=SimpleUploadedFile(...))). */
    protected function createStoredFile(int $ownerId, string $name, string $content, string $accessLevel = File::ACCESS_PRIVATE): int
    {
        $dir = static::getContainer()->getParameter('app.storage_dir').'/user_files';
        (new Filesystem())->dumpFile($dir.'/'.$name, $content);

        $file = new File($this->user($ownerId), 'user_files/'.$name);
        $file->setAccessLevel($accessLevel);
        $this->em()->persist($file);
        $this->em()->flush();

        return $file->getId();
    }

    protected function createLink(int $fileId, string $modify): string
    {
        $link = new PublicShareLink(
            $this->em()->find(File::class, $fileId),
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($modify),
        );
        $this->em()->persist($link);
        $this->em()->flush();

        return $link->getToken();
    }

    /** @param array<string, mixed>|null $json */
    protected function api(string $method, string $uri, ?array $json = null, ?string $token = null, array $files = [], array $parameters = []): Response
    {
        $server = [];
        $token ??= $this->accessToken;
        if ('' !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $content = null;
        if (null !== $json) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($json, \JSON_THROW_ON_ERROR);
        }

        $this->client->request($method, $uri, $parameters, $files, $server, $content);

        return $this->client->getResponse();
    }

    /** @return array<mixed> */
    protected function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    protected function downloadedContent(): string
    {
        return (string) $this->client->getInternalResponse()->getContent();
    }
}
