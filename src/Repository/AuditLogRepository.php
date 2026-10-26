<?php

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /** @return Paginator<AuditLog> */
    public function findFilteredPaginated(
        int $page = 1,
        int $limit = 20,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?User $user = null,
        ?string $action = null,
    ): Paginator {
        $qb = $this->buildFilteredQuery($from, $to, $user, $action)
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb->getQuery());
    }

    /** @return AuditLog[] */
    public function findFiltered(
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?User $user = null,
        ?string $action = null,
    ): array {
        return $this->buildFilteredQuery($from, $to, $user, $action)
            ->getQuery()
            ->getResult();
    }

    /** @return string[] */
    public function findDistinctActions(): array
    {
        $rows = $this->createQueryBuilder('al')
            ->select('DISTINCT al.action')
            ->orderBy('al.action', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'action');
    }

    private function buildFilteredQuery(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?User $user,
        ?string $action,
    ): \Doctrine\ORM\QueryBuilder {
        $qb = $this->createQueryBuilder('al')
            ->leftJoin('al.user', 'u')->addSelect('u')
            ->orderBy('al.createdAt', 'DESC');

        if ($from !== null) {
            $qb->andWhere('al.createdAt >= :from')
                ->setParameter('from', $from->setTime(0, 0, 0));
        }
        if ($to !== null) {
            $qb->andWhere('al.createdAt <= :to')
                ->setParameter('to', $to->setTime(23, 59, 59));
        }
        if ($user !== null) {
            $qb->andWhere('al.user = :user')
                ->setParameter('user', $user);
        }
        if ($action !== null && $action !== '') {
            $qb->andWhere('al.action = :action')
                ->setParameter('action', $action);
        }

        return $qb;
    }

    public function countOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('al')
            ->select('COUNT(al.id)')
            ->where('al.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Bulk-deletes audit log entries older than the cutoff. Returns count deleted.
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\AuditLog al WHERE al.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->execute();
    }

    public function save(AuditLog $log, bool $flush = false): void
    {
        $this->getEntityManager()->persist($log);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function countActionSince(string $action, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.action = :action')->andWhere('a.createdAt >= :since')
            ->setParameter('action', $action)->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }
}
