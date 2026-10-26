<?php

namespace App\Controller\Admin;

use App\Repository\AppSettingRepository;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/audit')]
#[IsGranted('ROLE_ADMIN')]
class AuditController extends AbstractController
{
    private const PAGE_LIMIT = 25;

    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly UserRepository $userRepository,
        private readonly AppSettingRepository $settings,
    ) {
    }

    #[Route('', name: 'admin_audit_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $activeTab = $request->query->getString('tab', 'log') === 'retention' ? 'retention' : 'log';

        // ── Retention tab data ────────────────────────────────────────────────
        $retentionMonths = (int) $this->settings->get('data_retention_months', '36');
        $cutoff          = new \DateTimeImmutable("-{$retentionMonths} months");
        $eligibleCount   = $this->auditLogRepository->countOlderThan($cutoff);

        // ── Audit log tab data ────────────────────────────────────────────────
        [$from, $to, $userId, $action, $filterUser] = $this->parseFilters($request);

        $page  = max(1, $request->query->getInt('p', 1));
        $logs  = $this->auditLogRepository->findFilteredPaginated(
            $page,
            self::PAGE_LIMIT,
            $from,
            $to,
            $filterUser,
            $action,
        );

        $total      = count($logs);
        $totalPages = max(1, (int) ceil($total / self::PAGE_LIMIT));
        $page       = min($page, $totalPages);

        return $this->render('admin/audit/index.html.twig', [
            // tabs
            'activeTab'       => $activeTab,
            // retention panel
            'retentionMonths' => $retentionMonths,
            'cutoff'          => $cutoff,
            'eligibleCount'   => $eligibleCount,
            // log panel
            'logs'            => $logs,
            'total'           => $total,
            'page'            => $page,
            'totalPages'      => $totalPages,
            'limit'           => self::PAGE_LIMIT,
            'users'           => $this->userRepository->findAllOrderedByName(),
            'actions'         => $this->auditLogRepository->findDistinctActions(),
            'actionLabels'    => AuditLogger::ACTION_LABELS,
            'filters'         => [
                'tab'    => 'log',
                'from'   => $from?->format('Y-m-d') ?? '',
                'to'     => $to?->format('Y-m-d') ?? '',
                'userId' => $userId,
                'action' => $action,
                'p'      => $page,
            ],
        ]);
    }

    #[Route('/retention', name: 'admin_audit_retention', methods: ['POST'])]
    public function retention(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('audit-retention', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_audit_index', ['tab' => 'retention']);
        }

        $months = max(1, min(120, $request->request->getInt('retention_months', 36)));
        $this->settings->set('data_retention_months', (string) $months);
        $this->addFlash('success', "Retention period updated to {$months} months.");

        return $this->redirectToRoute('admin_audit_index', ['tab' => 'retention']);
    }

    #[Route('/purge', name: 'admin_audit_purge', methods: ['POST'])]
    public function purge(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('audit-purge', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_audit_index', ['tab' => 'retention']);
        }

        $retentionMonths = (int) $this->settings->get('data_retention_months', '36');
        $cutoff          = new \DateTimeImmutable("-{$retentionMonths} months");
        $deleted         = $this->auditLogRepository->deleteOlderThan($cutoff);

        $this->addFlash('success', "Purged {$deleted} audit log " . ($deleted === 1 ? 'entry' : 'entries') . " older than " . $cutoff->format('M j, Y') . '.');

        return $this->redirectToRoute('admin_audit_index', ['tab' => 'retention']);
    }

    #[Route('/export', name: 'admin_audit_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        [$from, $to, , $action, $filterUser] = $this->parseFilters($request);

        $logs     = $this->auditLogRepository->findFiltered($from, $to, $filterUser, $action);
        $labels   = AuditLogger::ACTION_LABELS;
        $filename = 'audit-log-' . date('Y-m-d') . '.csv';

        $response = new StreamedResponse(function () use ($logs, $labels): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            fputcsv($handle, ['When', 'Who', 'Email', 'Action', 'Entity Type', 'Entity ID', 'Details', 'IP Address'], ',', '"', '');
            foreach ($logs as $log) {
                $user = $log->getUser();
                fputcsv($handle, [
                    $log->getCreatedAt()->format('Y-m-d H:i:s'),
                    $user?->getDisplayName() ?? '(system)',
                    $user?->getEmail() ?? '',
                    $labels[$log->getAction()] ?? $log->getAction(),
                    $log->getEntityType() ?? '',
                    $log->getEntityId() ?? '',
                    $log->getMetadata() ? json_encode($log->getMetadata()) : '',
                    $log->getIpAddress() ?? '',
                ], ',', '"', '');
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Parse common filter params from the request.
     *
     * @return array{?\DateTimeImmutable, ?\DateTimeImmutable, string, string, ?\App\Entity\User}
     */
    private function parseFilters(Request $request): array
    {
        $fromStr = $request->query->getString('from');
        $toStr   = $request->query->getString('to');
        $userId  = $request->query->getString('userId');
        $action  = $request->query->getString('action');

        $from = null;
        if ($fromStr !== '') {
            try {
                $from = new \DateTimeImmutable($fromStr);
            } catch (\Exception) {
            }
        }

        $to = null;
        if ($toStr !== '') {
            try {
                $to = new \DateTimeImmutable($toStr);
            } catch (\Exception) {
            }
        }

        $filterUser = null;
        if ($userId !== '') {
            try {
                $filterUser = $this->userRepository->find(Uuid::fromString($userId));
            } catch (\Exception) {
            }
        }

        return [$from, $to, $userId, $action, $filterUser];
    }
}
