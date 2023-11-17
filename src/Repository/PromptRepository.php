<?php

namespace App\Repository;

use App\Entity\Prompt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prompt>
 *
 * @method Prompt|null find($id, $lockMode = null, $lockVersion = null)
 * @method Prompt|null findOneBy(array $criteria, array $orderBy = null)
 * @method Prompt[]    findAll()
 * @method Prompt[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PromptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prompt::class);
    }

    public function findByTypeIdNotEqual($id, $type): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id != :id')
            ->andWhere('p.type = :type')
            ->setParameter('id', $id)
            ->setParameter('type', $type)
            ->getQuery()
            ->getResult()
        ;
    }
}
