<?php

namespace App\Repository;

use App\Entity\File;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<File>
 */
class FileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, File::class);
    }

    /**
     * Files the user owns, public files, and files shared with the user
     * (AccessibleFileListView: Q(owner=user) | Q(access_level="public") | Q(shared_with=user)).
     *
     * @return list<File>
     */
    public function findAccessibleBy(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->select('DISTINCT f')
            ->leftJoin('f.sharedWith', 's')
            ->where('f.owner = :user')
            ->orWhere('f.accessLevel = :public')
            ->orWhere('s = :user')
            ->setParameter('user', $user)
            ->setParameter('public', File::ACCESS_PUBLIC)
            ->orderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneOwnedBy(int $id, User $owner): ?File
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner]);
    }
}
