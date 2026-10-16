<?php

namespace App\Entity;

use App\Repository\PublicShareLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Port of storage.models.PublicShareLink.
 */
#[ORM\Entity(repositoryClass: PublicShareLinkRepository::class)]
#[ORM\Table(name: 'public_share_link')]
#[ORM\UniqueConstraint(name: 'UNIQ_PUBLIC_SHARE_LINK_TOKEN', columns: ['token'])]
class PublicShareLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: File::class)]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private File $file;

    /** Random UUIDv4, stored as its 36-character string form. */
    #[ORM\Column(length: 36)]
    private string $token;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(File $file, \DateTimeImmutable $expiresAt)
    {
        $this->file = $file;
        $this->token = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable('@'.time());
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFile(): File
    {
        return $this->file;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('@'.time());

        return $now > $this->expiresAt;
    }

    public function __toString(): string
    {
        return sprintf('Share Link for %s (expires at %s)', $this->file->getFilePath(), $this->expiresAt->format(\DATE_ATOM));
    }
}
