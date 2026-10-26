<?php

namespace App\Controller;

use App\Repository\ApiClientRepository;
use App\Repository\AuditLogRepository;
use App\Repository\MediaRepository;
use App\Service\QueueStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_STAFF')]
class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        ApiClientRepository $clients,
        MediaRepository $media,
        AuditLogRepository $audit,
        QueueStats $queue,
    ): Response {
        $weekAgo = new \DateTimeImmutable('-7 days');

        return $this->render('dashboard/index.html.twig', [
            'activeClients' => $clients->count(['isActive' => true]),
            'totalMedia'    => $media->count([]),
            'uploadsWeek'   => $media->countUploadedSince($weekAgo),
            'servedWeek'    => $audit->countActionSince('media.served', $weekAgo),
            'queue'         => $queue->processing(),
            'problems'      => $media->countProblems(),
            'recent'        => $media->findRecentUploads(15),
        ]);
    }
}
