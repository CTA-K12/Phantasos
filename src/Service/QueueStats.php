<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Reads the Messenger queue table directly (the Doctrine transport has no count API we can rely on). */
class QueueStats
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /** @return array{waiting: int, running: int} */
    public function processing(): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) FILTER (WHERE delivered_at IS NULL) AS waiting,
                    COUNT(*) FILTER (WHERE delivered_at IS NOT NULL) AS running
               FROM messenger_messages WHERE queue_name = 'process_media'"
        ) ?: [];

        return ['waiting' => (int) ($row['waiting'] ?? 0), 'running' => (int) ($row['running'] ?? 0)];
    }
}
