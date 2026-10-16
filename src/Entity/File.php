<?php

namespace App\Entity;

use App\Repository\FileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Port of storage.models.File.
 */
#[ORM\Entity(repositoryClass: FileRepository::class)]
#[ORM\Table(name: 'stored_file')]
class File
{
    public const ACCESS_PRIVATE = 'private';
    public const ACCESS_PUBLIC = 'public';
    public const ACCESS_SHARED = 'shared';
    public const ACCESS_CHOICES = [self::ACCESS_PRIVATE, self::ACCESS_PUBLIC, self::ACCESS_SHARED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Path relative to the storage directory, e.g. "user_files/report.pdf" (Django: FileField upload_to="user_files/"). */
    #[ORM\Column(length: 255)]
    private string $filePath;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\Column(length: 10)]
    private string $accessLevel = self::ACCESS_PRIVATE;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'stored_file_shared_with')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $sharedWith;

    public function __construct(User $owner, string $filePath)
    {
        $this->owner = $owner;
        $this->filePath = $filePath;
        $this->uploadedAt = new \DateTimeImmutable('@'.time());
        $this->sharedWith = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getFileName(): string
    {
        return basename($this->filePath);
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner->getId() === $user->getId();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function getAccessLevel(): string
    {
        return $this->accessLevel;
    }

    public function setAccessLevel(string $accessLevel): self
    {
        if (!\in_array($accessLevel, self::ACCESS_CHOICES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid access level "%s".', $accessLevel));
        }
        $this->accessLevel = $accessLevel;

        return $this;
    }

    public function isPublic(): bool
    {
        return self::ACCESS_PUBLIC === $this->accessLevel;
    }

    /** @return Collection<int, User> */
    public function getSharedWith(): Collection
    {
        return $this->sharedWith;
    }

    public function isSharedWith(User $user): bool
    {
        foreach ($this->sharedWith as $shared) {
            if ($shared->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    public function shareWith(User $user): self
    {
        if (!$this->isSharedWith($user)) {
            $this->sharedWith->add($user);
        }

        return $this;
    }

    /** Same rule as FileDownloadView: owner, public, or explicitly shared. */
    public function canBeAccessedBy(User $user): bool
    {
        return $this->isOwnedBy($user) || $this->isPublic() || $this->isSharedWith($user);
    }

    public function __toString(): string
    {
        return '' !== $this->title ? $this->title : $this->filePath;
    }
}
