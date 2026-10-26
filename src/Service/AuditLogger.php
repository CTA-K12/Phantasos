<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditLogger
{
    /**
     * Generic action constants for the starter template.
     * Replace and extend these with app-specific actions as needed.
     */
    public const ACTION_LABELS = [
        'user.login'              => 'User Login',
        'user.created'            => 'User Account Created',
        'user.updated'            => 'User Account Updated',
        'export.csv'              => 'CSV Export',
        'admin.setting_changed'   => 'Admin Setting Changed',
        'data.purged'             => 'Data Purged',
        'media.served'            => 'Media File Served',
        'media.uploaded'          => 'Media Uploaded',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @param array<string, mixed> $metadata */
    public function log(
        string $action,
        string $entityType = '',
        string $entityId = '',
        array $metadata = [],
    ): void {
        /** @var User|null $currentUser */
        $currentUser = $this->security->getUser();
        $request     = $this->requestStack->getCurrentRequest();

        // Re-fetch the user through the EM to ensure we have a managed (not detached)
        // entity reference — the EM may have been cleared (e.g. after bulk DQL deletes).
        $user = null;
        if ($currentUser instanceof User) {
            /** @var Uuid $userId */
            $userId = $currentUser->getId();
            $user   = $this->em->find(User::class, $userId);
        }

        $entry = new AuditLog();
        $entry->setAction($action);
        $entry->setUser($user);
        $entry->setEntityType($entityType !== '' ? $entityType : null);
        $entry->setEntityId($entityId !== '' ? $entityId : null);
        $entry->setMetadata($metadata);
        $entry->setIpAddress($request?->getClientIp());

        $this->em->persist($entry);
        $this->em->flush();
    }
}
