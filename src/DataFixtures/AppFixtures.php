<?php

namespace App\DataFixtures;

use App\Entity\AppSetting;
use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // doctrine:fixtures:load empties every table before this runs. Refuse anything but
        // a local database, so it can never wipe appdev-pg-prod (imported media, users).
        // (The purge has already happened by the time load() runs on a remote host, so the
        // real guard is the event listener in FixturesGuard; this is a second line.)
        if ($manager instanceof EntityManagerInterface) {
            FixturesGuard::assertLocal($manager->getConnection()->getParams());
        }

        // ── Users ─────────────────────────────────────────────────────────────
        $userData = [
            ['dcramble@mesd.k12.or.us', null, 'dcramble',        ['ROLE_ADMIN'],      true],
            ['thand@mesd.k12.or.us',    null, 'thand',           ['ROLE_ADMIN'],      true],
            ['staylor@mesd.k12.or.us',  null, 'staylor',         ['ROLE_ADMIN'],      true],
            ['jdhruv@mesd.k12.or.us',   null, 'jdhruv',          ['ROLE_ADMIN'],      true],
            ['supervisor@cta.org',      null, 'Supervisor',      ['ROLE_SUPERVISOR'], true],
            ['staff@cta.org',           null, 'Staff Member',    ['ROLE_STAFF'],      true],
            ['pending@cta.org',         null, 'Pending Account', ['ROLE_STAFF'],      false],
        ];

        /** @var User[] $users */
        $users = [];
        foreach ($userData as [$email, $googleId, $name, $roles, $active]) {
            $user = new User();
            $user->setEmail($email)
                ->setGoogleId($googleId)
                ->setDisplayName($name)
                ->setRoles($roles)
                ->setIsActive($active)
                ->setLastLoginAt(new \DateTimeImmutable());
            $manager->persist($user);
            $users[$email] = $user;
        }

        $manager->flush(); // flush users first so they have IDs for audit log FK

        // ── App settings ──────────────────────────────────────────────────────
        $manager->persist(new AppSetting('data_retention_months', '36'));

        // ── Sample audit log entries ──────────────────────────────────────────
        // Demonstrates the various action types available in the starter template.
        // Replace / extend with real application actions in your app.
        $auditEntries = [
            // User management (by dcramble, spread over past few weeks)
            [
                'user'       => $users['dcramble@mesd.k12.or.us'],
                'action'     => 'user.created',
                'entityType' => 'User',
                'metadata'   => ['email' => 'supervisor@cta.org', 'role' => 'ROLE_SUPERVISOR'],
                'ip'         => '10.0.1.42',
                'daysAgo'    => 14,
            ],
            [
                'user'       => $users['dcramble@mesd.k12.or.us'],
                'action'     => 'user.created',
                'entityType' => 'User',
                'metadata'   => ['email' => 'staff@cta.org', 'role' => 'ROLE_STAFF'],
                'ip'         => '10.0.1.42',
                'daysAgo'    => 14,
            ],
            [
                'user'       => $users['dcramble@mesd.k12.or.us'],
                'action'     => 'user.created',
                'entityType' => 'User',
                'metadata'   => ['email' => 'pending@cta.org', 'role' => 'ROLE_STAFF'],
                'ip'         => '10.0.1.42',
                'daysAgo'    => 13,
            ],
            [
                'user'       => $users['dcramble@mesd.k12.or.us'],
                'action'     => 'user.updated',
                'entityType' => 'User',
                'metadata'   => ['email' => 'staff@cta.org', 'isActive' => true],
                'ip'         => '10.0.1.42',
                'daysAgo'    => 10,
            ],
            // Login events
            [
                'user'       => $users['supervisor@cta.org'],
                'action'     => 'user.login',
                'entityType' => null,
                'metadata'   => [],
                'ip'         => '192.168.5.18',
                'daysAgo'    => 7,
            ],
            [
                'user'       => $users['staff@cta.org'],
                'action'     => 'user.login',
                'entityType' => null,
                'metadata'   => [],
                'ip'         => '192.168.5.31',
                'daysAgo'    => 5,
            ],
            [
                'user'       => $users['dcramble@mesd.k12.or.us'],
                'action'     => 'user.login',
                'entityType' => null,
                'metadata'   => [],
                'ip'         => '10.0.1.42',
                'daysAgo'    => 3,
            ],
            // Exports
            [
                'user'       => $users['staff@cta.org'],
                'action'     => 'export.csv',
                'entityType' => null,
                'metadata'   => ['rows' => 128, 'filter' => 'last 30 days'],
                'ip'         => '192.168.5.31',
                'daysAgo'    => 5,
            ],
            [
                'user'       => $users['supervisor@cta.org'],
                'action'     => 'export.csv',
                'entityType' => null,
                'metadata'   => ['rows' => 412, 'filter' => 'all time'],
                'ip'         => '192.168.5.18',
                'daysAgo'    => 2,
            ],
            // Admin settings change
            [
                'user'       => $users['dcramble@mesd.k12.or.us'],
                'action'     => 'admin.setting_changed',
                'entityType' => 'AppSetting',
                'metadata'   => ['key' => 'data_retention_months', 'old' => '24', 'new' => '36'],
                'ip'         => '10.0.1.42',
                'daysAgo'    => 1,
            ],
        ];

        foreach ($auditEntries as $entry) {
            $log = new AuditLog();
            $log->setUser($entry['user'])
                ->setAction($entry['action'])
                ->setEntityType($entry['entityType'])
                ->setMetadata($entry['metadata'])
                ->setIpAddress($entry['ip']);

            // Manually set createdAt via reflection so fixture data spans realistic dates
            $createdAt = new \DateTimeImmutable('-' . $entry['daysAgo'] . ' days');
            $ref = new \ReflectionProperty(AuditLog::class, 'createdAt');
            $ref->setValue($log, $createdAt);

            $manager->persist($log);
        }

        $manager->flush();
    }
}
