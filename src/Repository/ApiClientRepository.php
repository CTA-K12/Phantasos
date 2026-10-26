<?php

namespace App\Repository;

use App\Entity\ApiClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiClient>
 */
class ApiClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiClient::class);
    }

    public function save(ApiClient $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findActiveByApiKey(string $apiKey): ?ApiClient
    {
        return $this->findOneBy(['keyHash' => ApiClient::hashKey($apiKey), 'isActive' => true]);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
