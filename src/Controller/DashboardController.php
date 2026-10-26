<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig');
    }

    /**
     * Returns sample chart data for the "Activity by Type" donut chart.
     *
     * Replace this with real data from your domain repositories.
     * The donut chart expects: [{label: string, count: int, color: string}]
     *
     * The response also includes the current school/fiscal year date range,
     * which can be used by the frontend to set default filter values.
     */
    #[Route('/dashboard/data', name: 'app_dashboard_data')]
    public function data(): JsonResponse
    {
        [$from, $to] = $this->currentSchoolYear();

        // Sample hardcoded data — replace with real queries in your app
        $activityByType = [
            ['label' => 'Category A', 'count' => 42, 'color' => '#7a5af8'],
            ['label' => 'Category B', 'count' => 28, 'color' => '#a78bfa'],
            ['label' => 'Category C', 'count' => 19, 'color' => '#c4b5fd'],
            ['label' => 'Category D', 'count' => 35, 'color' => '#6d28d9'],
            ['label' => 'Category E', 'count' => 11, 'color' => '#4c1d95'],
        ];

        // Sample horizontal bar chart data — replace with real queries in your app
        // Uses the hbar chart type: [{label, count, color}]
        $activityByUser = [
            ['label' => 'Staff Member',    'count' => 47, 'color' => '#7a5af8'],
            ['label' => 'Supervisor',      'count' => 31, 'color' => '#a78bfa'],
            ['label' => 'jdhruv',          'count' => 24, 'color' => '#c4b5fd'],
            ['label' => 'staylor',         'count' => 18, 'color' => '#6d28d9'],
            ['label' => 'thand',           'count' => 12, 'color' => '#4c1d95'],
        ];

        return $this->json([
            'activityByType' => $activityByType,
            'activityByUser' => $activityByUser,
            'periodFrom'     => $from->format('Y-m-d'),
            'periodTo'       => $to->format('Y-m-d'),
        ]);
    }

    /**
     * Returns [from, to] DateTimeImmutable for the current school/fiscal year.
     * Default: July 1 – June 30 (adjust as needed for your organization).
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function currentSchoolYear(): array
    {
        $now       = new \DateTimeImmutable('today');
        $year      = (int) $now->format('Y');
        $month     = (int) $now->format('n');
        $startYear = $month >= 7 ? $year : $year - 1;

        return [
            new \DateTimeImmutable("{$startYear}-07-01"),
            new \DateTimeImmutable(($startYear + 1) . '-06-30'),
        ];
    }
}
