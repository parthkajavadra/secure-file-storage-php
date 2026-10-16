<?php

namespace App\Controller;

use App\Entity\File;
use App\Entity\PublicShareLink;
use App\Entity\User;
use App\Http\FieldValidator;
use App\Http\RequestData;
use App\Message\ScanFileMessage;
use App\Repository\FileRepository;
use App\Repository\PublicShareLinkRepository;
use App\Repository\UserRepository;
use App\Service\FileStorage;
use App\Service\VirusScannerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Http\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Port of storage/views.py — all routes live under /api/storage/ (storage/urls.py).
 */
#[Route('/api/storage', name: 'storage_')]
final class StorageController extends AbstractController
{
    /** UploadSerializer.py: MAX_FILE_SIZE = 2 MB */
    public const MAX_FILE_SIZE = 2 * 1024 * 1024;

    /** UploadSerializer.py: ALLOWED_CONTENT_TYPES */
    public const ALLOWED_CONTENT_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'text/plain',
    ];

    /** Upper bound for expires_in_minutes (10 years) so the expiry date cannot overflow. */
    private const MAX_LINK_MINUTES = 5_256_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly FileRepository $files,
        private readonly PublicShareLinkRepository $links,
        private readonly FileStorage $storage,
        private readonly VirusScannerInterface $scanner,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $accessLogger,
    ) {
    }

    /** RegisterUserAPIView — POST /api/storage/register/ */
    #[Route('/register/', name: 'register', methods: ['POST'])]
    public function register(Request $request, UserPasswordHasherInterface $hasher): ApiResponse
    {
        $v = new FieldValidator(RequestData::from($request));
        $username = $v->string('username', maxLength: 150);
        $password = $v->string('password', maxLength: 128);

        if (null !== $username) {
            if (!preg_match('/^[\w.@+-]+$/u', $username)) {
                $v->addError('username', 'Enter a valid username. This value may contain only letters, numbers, and @/./+/-/_ characters.');
            } elseif (null !== $this->users->findOneByUsername($username)) {
                $v->addError('username', 'A user with that username already exists.');
            }
        }
        if ($v->hasErrors()) {
            return new ApiResponse($v->getErrors(), Response::HTTP_BAD_REQUEST);
        }

        $user = new User($username);
        $user->setPassword($hasher->hashPassword($user, $password));
        $this->em->persist($user);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return new ApiResponse(['username' => ['A user with that username already exists.']], Response::HTTP_BAD_REQUEST);
        }

        return new ApiResponse(['id' => $user->getId(), 'username' => $user->getUsername()], Response::HTTP_CREATED);
    }

    /** FileUploadView — POST /api/storage/upload/ (multipart/form-data) */
    #[Route('/upload/', name: 'file_upload', methods: ['POST'])]
    public function upload(Request $request): ApiResponse
    {
        $user = $this->currentUser();
        $v = new FieldValidator($request->request->all());

        $upload = $request->files->get('file');
        if (!$upload instanceof UploadedFile) {
            $v->addError('file', 'No file was submitted.');
        } elseif (!$upload->isValid()) {
            $v->addError('file', \in_array($upload->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? 'File is too large (max 02 MB).'
                : 'The submitted data was not a file. Check the encoding type on the form.');
        } elseif (0 === $upload->getSize()) {
            $v->addError('file', 'The submitted file is empty.');
        } elseif ($upload->getSize() > self::MAX_FILE_SIZE) {
            $v->addError('file', 'File is too large (max 02 MB).');
        } else {
            // Detected from the file contents, not the client-supplied Content-Type.
            $mimeType = $upload->getMimeType() ?? 'application/octet-stream';
            if (!\in_array($mimeType, self::ALLOWED_CONTENT_TYPES, true)) {
                $v->addError('file', sprintf('Unsupported file type: %s', $mimeType));
            }
        }

        $title = $v->string('title', required: false, maxLength: 255, default: '') ?? '';
        $accessLevel = $v->choice('access_level', File::ACCESS_CHOICES, File::ACCESS_PRIVATE);
        $sharedWith = $this->resolveSharedWith($request, $v);

        if ($v->hasErrors()) {
            return new ApiResponse($v->getErrors(), Response::HTTP_BAD_REQUEST);
        }

        // Scan before the file is stored (scan_file_for_virus in perform_create).
        // Any non-null result — a detection OR a scanner error — rejects the upload (fail closed).
        $scan = $this->scanner->scan($upload->getPathname());
        if (null !== $scan) {
            $this->accessLogger->warning(sprintf(
                '[Upload Rejected] User: %s | IP: %s | File: %s (%d bytes) | Scan: %s',
                $user->getUsername(), $request->getClientIp(), FileStorage::safeName($upload->getClientOriginalName()),
                $upload->getSize(), json_encode($scan, \JSON_UNESCAPED_SLASHES),
            ));

            return new ApiResponse(['File upload rejected due to virus detection!'], Response::HTTP_BAD_REQUEST);
        }

        $file = new File($user, $this->storage->store($upload));
        $file->setTitle($title)->setAccessLevel($accessLevel);
        foreach ($sharedWith as $recipient) {
            $file->shareWith($recipient);
        }
        $this->em->persist($file);
        $this->em->flush();

        // async_scan_file.delay(...) — background re-scan of the stored file.
        $this->bus->dispatch(new ScanFileMessage($file->getId(), $file->getFilePath()));

        return new ApiResponse($this->serializeFile($file), Response::HTTP_CREATED);
    }

    /** AccessibleFileListView — GET /api/storage/files/ */
    #[Route('/files/', name: 'file_list', methods: ['GET'])]
    public function list(): ApiResponse
    {
        $files = $this->files->findAccessibleBy($this->currentUser());

        return new ApiResponse(array_map($this->serializeFile(...), $files));
    }

    /** FileDownloadView — GET /api/storage/download/{file_id}/ */
    #[Route('/download/{fileId}/', name: 'file_download', requirements: ['fileId' => Requirement::DIGITS], methods: ['GET'])]
    public function download(int $fileId): Response
    {
        $user = $this->currentUser();
        $file = $this->files->find($fileId);
        if (null === $file) {
            return $this->fileNotFound();
        }

        if (!$file->canBeAccessedBy($user)) {
            return new ApiResponse(['detail' => 'You do not have permission to access this file.'], Response::HTTP_FORBIDDEN);
        }

        return $this->fileResponse($file);
    }

    /** ShareFileView — POST /api/storage/share/  {"file_id": 1, "share_with_username": "bob"} */
    #[Route('/share/', name: 'share_file', methods: ['POST'])]
    public function share(Request $request): ApiResponse
    {
        $user = $this->currentUser();
        $v = new FieldValidator(RequestData::from($request));
        $fileId = $v->integer('file_id');
        $username = $v->string('share_with_username');
        if ($v->hasErrors()) {
            return new ApiResponse($v->getErrors(), Response::HTTP_BAD_REQUEST);
        }

        // Same checks, in the same order, as FileShareSerializer.validate()
        $file = $this->files->find($fileId);
        if (null === $file) {
            return new ApiResponse(['non_field_errors' => ['File not found']], Response::HTTP_BAD_REQUEST);
        }
        $recipient = $this->users->findOneByUsername($username);
        if (null === $recipient) {
            return new ApiResponse(['share_with_errors' => ['User to share with not found']], Response::HTTP_BAD_REQUEST);
        }
        if (!$file->isOwnedBy($user)) {
            return new ApiResponse(['permission denied' => ['You do not have permission to share this file']], Response::HTTP_BAD_REQUEST);
        }

        $file->shareWith($recipient);
        $this->em->flush();

        return new ApiResponse(['message' => sprintf('File shared with %s successfully!', $recipient->getUsername())]);
    }

    /** GeneratePublicLinkView — POST /api/storage/share/{file_id}/  {"expires_in_minutes": 60} */
    #[Route('/share/{fileId}/', name: 'generate_public_link', requirements: ['fileId' => Requirement::DIGITS], methods: ['POST'])]
    public function generatePublicLink(Request $request, int $fileId): ApiResponse
    {
        $user = $this->currentUser();
        $ip = $request->getClientIp();
        $userAgent = $request->headers->get('User-Agent', 'Unknown');

        $file = $this->files->findOneOwnedBy($fileId, $user);
        if (null === $file) {
            $this->accessLogger->warning(sprintf(
                '[Unauthorized Access Attempt] User: %s | IP: %s | File ID: %d | Reason: Not owner',
                $user->getUsername(), $ip, $fileId,
            ));

            return $this->fileNotFound();
        }

        $v = new FieldValidator(RequestData::from($request));
        $minutes = $v->integer('expires_in_minutes', -self::MAX_LINK_MINUTES, self::MAX_LINK_MINUTES);
        if ($v->hasErrors()) {
            return new ApiResponse($v->getErrors(), Response::HTTP_BAD_REQUEST);
        }

        $expiresAt = (new \DateTimeImmutable('@'.time()))->modify(sprintf('%+d minutes', $minutes));
        $link = new PublicShareLink($file, $expiresAt);
        $this->em->persist($link);
        $this->em->flush();

        $url = $this->generateUrl('storage_public_download', ['token' => $link->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->accessLogger->info(sprintf(
            '[Public Link Created] User: %s | IP: %s | File: %s | Token: %s | Expires: %s | UA: %s',
            $user->getUsername(), $ip, $file->getFilePath(), $link->getToken(), $expiresAt->format('Y-m-d H:i:sP'), $userAgent,
        ));

        return new ApiResponse(['share_link' => $url], Response::HTTP_CREATED);
    }

    /** PublicFileDownloadView — GET /api/storage/public/{token}/ (no authentication) */
    #[Route('/public/{token}/', name: 'public_download', requirements: ['token' => Requirement::UUID], methods: ['GET'])]
    public function publicDownload(Request $request, string $token): Response
    {
        $ip = $request->getClientIp();
        $userAgent = $request->headers->get('User-Agent', 'unknown');

        $link = $this->links->findOneByToken($token);
        if (null === $link) {
            $this->accessLogger->warning(sprintf('[Invalid Token Attempt] Token: %s | IP: %s | UA: %s', $token, $ip, $userAgent));

            return new ApiResponse(['detail' => 'Invalid link'], Response::HTTP_NOT_FOUND);
        }
        if ($link->isExpired()) {
            $this->accessLogger->warning(sprintf('[Expired Link Attempt] Token: %s | IP: %s | UA: %s', $token, $ip, $userAgent));

            return new ApiResponse(['detail' => 'Link expired'], Response::HTTP_NOT_FOUND);
        }

        $this->accessLogger->info(sprintf(
            '[Public File Download] File: %s | Token: %s | IP: %s | UA: %s',
            $link->getFile()->getFilePath(), $token, $ip, $userAgent,
        ));

        return $this->fileResponse($link->getFile());
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            // The firewall's access_control guarantees an authenticated user on these routes.
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * Optional "shared_with" on upload (FileUploadSerializer has fields = "__all__").
     * Send as shared_with[]=1&shared_with[]=2 in the multipart body.
     *
     * @return list<User>
     */
    private function resolveSharedWith(Request $request, FieldValidator $v): array
    {
        $raw = $request->request->all()['shared_with'] ?? [];
        if ('' === $raw) {
            return [];
        }
        $ids = \is_array($raw) ? $raw : [$raw];

        $users = [];
        foreach ($ids as $rawId) {
            $id = FieldValidator::toInt($rawId);
            if (null === $id) {
                $v->addError('shared_with', sprintf('Incorrect type. Expected pk value, received %s.', get_debug_type($rawId)));
                continue;
            }
            $recipient = $this->users->find($id);
            if (null === $recipient) {
                $v->addError('shared_with', sprintf('Invalid pk "%d" - object does not exist.', $id));
                continue;
            }
            $users[] = $recipient;
        }

        return $users;
    }

    /**
     * Same fields as FileSerializer(fields="__all__").
     * "file" is the stored path; files are only downloadable through "download_url" (access-checked).
     *
     * @return array<string, mixed>
     */
    private function serializeFile(File $file): array
    {
        return [
            'id' => $file->getId(),
            'file' => $file->getFilePath(),
            'download_url' => $this->generateUrl('storage_file_download', ['fileId' => $file->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            'title' => $file->getTitle(),
            'uploaded_at' => $file->getUploadedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'access_level' => $file->getAccessLevel(),
            'owner' => $file->getOwner()->getId(),
            'shared_with' => array_values(array_map(static fn (User $u) => $u->getId(), $file->getSharedWith()->toArray())),
        ];
    }

    private function fileResponse(File $file): Response
    {
        if (!$this->storage->exists($file->getFilePath())) {
            return new ApiResponse(['detail' => 'File is missing from storage.'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($this->storage->absolutePath($file->getFilePath()));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getFileName());
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function fileNotFound(): ApiResponse
    {
        return new ApiResponse(['detail' => 'No File matches the given query.'], Response::HTTP_NOT_FOUND);
    }
}
