<?php

namespace App\Controller;

use App\Service\AccessControl;
use App\Service\AppDatabase;
use App\Service\CompanyProfile;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReportController extends AbstractController
{
    #[Route('/reports/finance', name: 'app_report_finance', methods: ['GET'])]
    public function finance(Request $request, AppDatabase $db, AccessControl $access): Response
    {
        $user = $this->requireReportUser($request, $db, $access);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $period = $this->resolvePeriod($request);
        if ($period['invalid']) {
            $this->addFlash('warning', 'reports.invalid_dates');
        }

        return $this->render('app/report_finance.html.twig', [
            'user' => $user,
            'period' => $period,
            'summary' => $db->getFinancialSummary($period['from'], $period['to']),
            'operations' => $db->getFinancialOperations($period['from'], $period['to']),
        ]);
    }

    #[Route('/reports/finance/operation/{id}', name: 'app_report_finance_operation', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function operation(int $id, Request $request, AppDatabase $db, AccessControl $access): Response
    {
        $user = $this->requireReportUser($request, $db, $access);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $operation = $db->getOperationMarginDetails($id);
        if (!$operation) {
            throw $this->createNotFoundException();
        }

        return $this->render('app/report_finance_operation.html.twig', [
            'user' => $user,
            'operation' => $operation,
            'return_filters' => $request->query->all(),
        ]);
    }

    #[Route('/reports/finance/day-receipt', name: 'app_report_day_receipt', methods: ['GET'])]
    public function dayReceipt(Request $request, AppDatabase $db, AccessControl $access, CompanyProfile $company): Response
    {
        $user = $this->requireReportUser($request, $db, $access);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $date = $this->validDate((string) $request->query->get('date')) ?? $this->today()->format('Y-m-d');

        return $this->render('documents/day_receipt.html.twig', [
            'user' => $user,
            'company' => $company->data(),
            'report' => $db->getDailySessionReport($date),
        ]);
    }

    public function resolvePeriod(Request $request): array
    {
        $today = $this->today();
        $preset = (string) $request->query->get('preset', 'today');
        $invalid = false;

        if (!in_array($preset, ['today', 'week', 'month', 'custom'], true)) {
            $preset = 'today';
            $invalid = true;
        }

        if ($preset === 'custom') {
            $from = $this->validDate((string) $request->query->get('from'));
            $to = $this->validDate((string) $request->query->get('to'));
            if (!$from || !$to || $from > $to) {
                $preset = 'today';
                $invalid = true;
                $from = $today->format('Y-m-d');
                $to = $from;
            }

            return [
                'preset' => $preset,
                'from' => $from,
                'to' => $to,
                'invalid' => $invalid,
                'is_single_day' => $from === $to,
            ];
        }

        if ($preset === 'week') {
            $from = $today->modify('monday this week')->format('Y-m-d');
            $to = $today->modify('sunday this week')->format('Y-m-d');
        } elseif ($preset === 'month') {
            $from = $today->modify('first day of this month')->format('Y-m-d');
            $to = $today->modify('last day of this month')->format('Y-m-d');
        } else {
            $from = $today->format('Y-m-d');
            $to = $from;
        }

        return [
            'preset' => $preset,
            'from' => $from,
            'to' => $to,
            'invalid' => $invalid,
            'is_single_day' => $from === $to,
        ];
    }

    private function requireReportUser(Request $request, AppDatabase $db, AccessControl $access): array|RedirectResponse
    {
        $sessionUser = $request->getSession()->get('user');
        if (!$sessionUser) {
            return $this->redirectToRoute('app_login');
        }

        $user = $db->userById((int) $sessionUser['id']);
        if (!$user || (int) $user['active'] !== 1) {
            $request->getSession()->clear();
            return $this->redirectToRoute('app_login');
        }

        $sessionUser = ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']];
        $request->getSession()->set('user', $sessionUser);
        if (!$access->can('reports.view', $sessionUser)) {
            $this->addFlash('error', 'reports.access_denied');
            return $this->redirectToRoute('app_dashboard');
        }

        return $sessionUser;
    }

    private function validDate(string $date): ?string
    {
        $date = trim($date);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $date;
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }
}
