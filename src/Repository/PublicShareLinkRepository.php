<?php

namespace App\Repository;

use App\Entity\PublicShareLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicShareLink>
 */
class PublicShareLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicShareLink::class);
    }

    public function findOneByToken(string $token): ?PublicShareLink
    {
        return $this->createQueryBuilder('l')
            ->addSelect('f')
            ->join('l.file', 'f')
            ->where('l.token = :token')
            ->setParameter('token', strtolower($token))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
