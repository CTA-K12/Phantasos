<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Aggregates for the dashboard charts (plain SQL: date bucketing and JSON metadata). */
class DashboardStats
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MediaStorage $storage,
    ) {
    }

    /**
     * Uploads per day per client for the last $days days (missing days filled with 0).
     *
     * @return array{days: list<string>, series: list<array{name: string, data: list<int>}>}
     */
    public function uploadsPerDay(int $days = 30): array
    {
        $start = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days - 1));
        $rows  = $this->connection->fetchAllAssociative(
            'SELECT to_char(m.created_at, \'YYYY-MM-DD\') AS day, c.name AS client, COUNT(*) AS n
               FROM media m JOIN api_client c ON c.id = m.client_id
              WHERE m.uploaded AND m.created_at >= :start
              GROUP BY 1, 2',
            ['start' => $start->format('Y-m-d H:i:s')],
        );

        $dayKeys = [];
        for ($d = $start, $i = 0; $i < $days; $i++, $d = $d->modify('+1 day')) {
            $dayKeys[] = $d->format('Y-m-d');
        }
        $byClient = [];
        foreach ($rows as $row) {
            $byClient[(string) $row['client']][(string) $row['day']] = (int) $row['n'];
        }
        ksort($byClient, SORT_NATURAL | SORT_FLAG_CASE);

        $series = [];
        foreach ($byClient as $client => $counts) {
            $series[] = ['name' => $client, 'data' => array_map(static fn (string $k): int => $counts[$k] ?? 0, $dayKeys)];
        }

        return ['days' => $dayKeys, 'series' => $series];
    }

    /**
     * requestMedia downloads per client over the last $days days, from the audit log.
     *
     * @return list<array{client: string, n: int}>
     */
    public function downloadsPerClient(int $days = 30): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT COALESCE(metadata->>'client', '(unknown)') AS client, COUNT(*) AS n
               FROM audit_log
              WHERE action = 'media.served' AND created_at >= :start
              GROUP BY 1 ORDER BY 2 DESC",
            ['start' => (new \DateTimeImmutable(sprintf('-%d days', $days)))->format('Y-m-d H:i:s')],
        );

        return array_map(static fn (array $r): array => ['client' => (string) $r['client'], 'n' => (int) $r['n']], $rows);
    }

    /**
     * Usage of the volume holding the media store (the whole /media filesystem).
     *
     * @return array{used: int, total: int, percent: float}|null null when MEDIA_ROOT is absent (e.g. local dev)
     */
    public function storage(): ?array
    {
        $root = $this->storage->getMediaRoot();
        if (!is_dir($root)) {
            return null;
        }
        $total = @disk_total_space($root);
        $free  = @disk_free_space($root);
        if ($total === false || $free === false || $total <= 0) {
            return null;
        }
        $used = (int) ($total - $free);

        return ['used' => $used, 'total' => (int) $total, 'percent' => round($used / $total * 100, 1)];
    }
}
