<?php

namespace App\Repository;

use App\Entity\ApiClient;
use App\Entity\Media;
use App\Enum\MediaStatus;
use App\Search\MediaSearch;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Media>
 */
class MediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Media::class);
    }

    public function save(Media $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Uploaded but never finished: still queued/processing well past any normal run.
     * A ticket is valid for 2 h and the longest encode is bounded by the worker's
     * redeliver timeout (4 h), so anything older than this is stuck, not slow.
     */
    public const STUCK_AFTER = '-6 hours';

    /** @return list<Media> failed media, newest first */
    public function findFailed(int $limit = 500): array
    {
        /** @var list<Media> */
        return $this->createQueryBuilder('m')
            ->addSelect('c')->join('m.client', 'c')
            ->where('m.status = :failed')->setParameter('failed', MediaStatus::Failed)
            ->orderBy('m.createdAt', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<Media> stuck media (see STUCK_AFTER), oldest first */
    public function findStuck(int $limit = 500): array
    {
        /** @var list<Media> */
        return $this->stuckQuery()
            ->addSelect('c')->join('m.client', 'c')
            ->orderBy('m.createdAt', 'ASC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function countProblems(): int
    {
        $failed = (int) $this->count(['status' => MediaStatus::Failed]);
        $stuck  = (int) $this->stuckQuery()->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
        return $failed + $stuck;
    }

    private function stuckQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->where('m.uploaded = true')
            ->andWhere('m.ready = false')
            ->andWhere('m.status IN (:open)')
            ->andWhere('m.createdAt < :cutoff')
            ->setParameter('open', [MediaStatus::Queued, MediaStatus::Processing])
            ->setParameter('cutoff', new \DateTimeImmutable(self::STUCK_AFTER));
    }

    /**
     * Per-client media figures for the clients list.
     *
     * @return array<string, array{media: int, ready: int, lastUpload: ?\DateTimeImmutable}> keyed by client id (RFC 4122)
     */
    public function statsByClient(): array
    {
        $stats = [];
        $rows  = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.client) AS client', 'COUNT(m.id) AS media',
                'SUM(CASE WHEN m.ready = true THEN 1 ELSE 0 END) AS ready')
            ->groupBy('m.client')
            ->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $stats[(string) $row['client']] = ['media' => (int) $row['media'], 'ready' => (int) $row['ready'], 'lastUpload' => null];
        }

        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.client) AS client', 'MAX(m.createdAt) AS lastUpload')
            ->where('m.uploaded = true')
            ->groupBy('m.client')
            ->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            if (isset($stats[(string) $row['client']]) && $row['lastUpload'] !== null) {
                $stats[(string) $row['client']]['lastUpload'] = new \DateTimeImmutable((string) $row['lastUpload']);
            }
        }
        return $stats;
    }

    public function countUploadedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.uploaded = true')->andWhere('m.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<Media> */
    public function findRecentUploads(int $limit = 10, ?ApiClient $client = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('c')->join('m.client', 'c')
            ->where('m.uploaded = true')
            ->orderBy('m.createdAt', 'DESC')->setMaxResults($limit);
        if ($client !== null) {
            $qb->andWhere('m.client = :client')->setParameter('client', $client);
        }
        /** @var list<Media> */
        return $qb->getQuery()->getResult();
    }

    /** @return Paginator<Media> newest first */
    public function search(MediaSearch $search): Paginator
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('c')->join('m.client', 'c')
            ->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC')
            ->setFirstResult(($search->page - 1) * MediaSearch::PER_PAGE)
            ->setMaxResults(MediaSearch::PER_PAGE);

        if ($search->clientId !== null) {
            $qb->andWhere('c.id = :client')->setParameter('client', $search->clientId, 'uuid');
        }
        if ($search->id !== '') {
            // A media ID or one of its file IDs; partial input matches as a prefix.
            $qb->andWhere('m.id LIKE :id OR EXISTS (SELECT 1 FROM App\Entity\MediaFile f WHERE f.media = m AND f.id LIKE :id)')
                ->setParameter('id', $search->id . '%');
        }
        if ($search->type !== null) {
            $qb->andWhere('m.mediaType = :type')->setParameter('type', $search->type);
        }
        if ($search->status !== null) {
            $qb->andWhere('m.status = :status')->setParameter('status', $search->status);
        }
        if (!$search->tickets) {
            $qb->andWhere('m.uploaded = true');
        }
        if ($search->from !== null) {
            $qb->andWhere('m.createdAt >= :from')->setParameter('from', $search->from);
        }
        if ($search->to !== null) {
            $qb->andWhere('m.createdAt < :to')->setParameter('to', $search->to->modify('+1 day'));
        }

        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
