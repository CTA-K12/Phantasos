<?php

namespace App\DataFixtures;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Stops `doctrine:fixtures:load` before it purges, unless the database is local.
 *
 * The purge deletes every row in every table, so pointed at appdev-pg-prod it would wipe
 * the imported media and users. Running against the local Docker database (dev and the
 * `_test` database) is unaffected.
 */
#[AsEventListener(event: ConsoleEvents::COMMAND)]
final class FixturesGuard
{
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', 'host.docker.internal'];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        if ($event->getCommand()?->getName() !== 'doctrine:fixtures:load') {
            return;
        }
        self::assertLocal($this->connection->getParams());
    }

    /** @param array<string, mixed> $params */
    public static function assertLocal(array $params): void
    {
        $host = $params['host'] ?? null;
        if (!is_string($host) || !in_array(strtolower($host), self::LOCAL_HOSTS, true)) {
            throw new \RuntimeException(sprintf(
                'Refusing to load fixtures into database host "%s": fixtures purge every table. Only local databases are allowed.',
                is_string($host) ? $host : '(unknown)',
            ));
        }
    }
}
