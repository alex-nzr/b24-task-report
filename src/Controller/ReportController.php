<?php

namespace App\Controller;

use App\Bitrix\BitrixFailure;
use App\Form\ReportRequestType;
use App\Report\CsvExporter;
use App\Report\EmployeeDirectory;
use App\Report\ReportCalculator;
use App\Report\ReportPeriod;
use App\Report\ReportRepository;
use App\Report\ReportRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends AbstractController
{
    public function __construct(private ReportRepository $reports, private EmployeeDirectory $directory, private ReportRunner $runner, private string $reportTimezone, private string $portalUrl, private \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrfTokens)
    {
    }

    #[Route('/', name: 'reports', methods: ['GET'])]
    #[Route('/reports', name: 'report_create', methods: ['POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST') && !$this->isCsrfTokenValid('report_create', $request->request->all('report_request')['_token'] ?? null))
        {
            throw $this->createAccessDeniedException();
        }
        $employees = [];
        $error = null;
        try
        {
            $employees = $this->directory->all();
        }
        catch (BitrixFailure $failure)
        {
            $error = 'Справочник сотрудников недоступен: '.$failure->errorCode;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->reportTimezone));
        $form = $this->createForm(ReportRequestType::class, ['from' => $now->modify('first day of this month')->format('Y-m-d'), 'to' => $now->format('Y-m-d')], ['employees' => $employees, 'action' => $this->generateUrl('report_create')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $error === null)
        {
            $data = $form->getData();
            try
            {
                $period = new ReportPeriod($data['from'], $data['to'], $this->reportTimezone);
                $id = $this->enqueue($employees[$data['employeeId']], $period);
                if ($data['collectNow'])
                {
                    try
                    {
                        if ($this->runner->run(reportId: $id)['busy'])
                        {
                            $this->addFlash('warning', 'Сбор уже выполняется. Новый отчёт сохранён в очереди.');
                        }
                    }
                    catch (\Throwable)
                    {
                        $this->addFlash('warning', 'Немедленный сбор остановлен (COLLECTION_FAILED). Отчёт и сохранённый прогресс доступны; повторите сбор после проверки конфигурации.');
                    }
                }
                return $this->redirectToRoute('report_show', ['id' => $id]);
            }
            catch (\InvalidArgumentException)
            {
                $error = 'Проверьте период: дата начала должна быть не позже даты окончания.';
            }
        }
        $formErrors = [];
        foreach ($form->getErrors(true) as $formError)
        {
            $formErrors[] = $formError->getMessage();
        }
        return $this->render('reports/index.html.twig', ['form' => $form, 'error' => $error, 'formErrors' => $formErrors, 'employees' => array_values($employees), 'runs' => $this->recentReports(), 'timezone' => $this->reportTimezone], new Response(status: $request->isMethod('POST') ? 422 : 200));
    }

    #[Route('/reports/{id}/delete', name: 'report_delete', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_'.$id, $request->request->get('_token')))
        {
            throw $this->createAccessDeniedException();
        }
        $result = $this->reports->delete($id);
        $json = $request->getPreferredFormat() === 'json';
        if ($result === 'busy')
        {
            if ($json)
            {
                return $this->json(['error' => 'Сборщик занят. Повторите удаление после завершения команды.'], 409, ['Cache-Control' => 'private, no-store']);
            }
            $this->addFlash('warning', 'Сборщик занят. Повторите удаление позже.');
            return $this->redirectToRoute('reports');
        }
        return $json ? $this->json(['runs' => $this->recentReports()], headers: ['Cache-Control' => 'private, no-store']) : $this->redirectToRoute('reports');
    }

    private function recentReports(): array
    {
        return array_map(fn (array $run): array => $run + ['deleteCsrf' => $this->csrfTokens->getToken('delete_'.$run['id'])->getValue()], $this->reports->latest());
    }

    #[Route('/reports/{id}', name: 'report_show', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function show(string $id): Response
    {
        $snapshot = $this->snapshot($id);
        $portalBase = preg_match('~^https://[a-z0-9.-]+/?$~i', $this->portalUrl) ? rtrim($this->portalUrl, '/') : null;
        return $this->render('reports/show.html.twig', $snapshot + ['portalBase' => $portalBase]);
    }

    #[Route('/reports/{id}/progress', name: 'report_progress', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function progress(string $id): Response
    {
        return $this->json($this->snapshot($id), headers: ['Cache-Control' => 'private, no-cache, no-store']);
    }

    #[Route('/reports/{id}/workdays', name: 'report_workdays', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function workdays(string $id, \App\Report\WorkingDays $days): Response
    {
        $run = $this->reports->find($id) ?? throw $this->createNotFoundException();
        return $this->json($days->forPeriod($run['date_from'], $run['date_to']), headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/reports/{id}/csv', name: 'report_csv', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function csv(string $id, CsvExporter $exporter): Response
    {
        $snapshot = $this->snapshot($id);
        return new Response($exporter->export($snapshot['rows'], $snapshot['run'], $snapshot['errors']), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="report-'.$id.'.csv"', 'Cache-Control' => 'private, no-store']);
    }

    #[Route('/reports/{id}/refresh', name: 'report_refresh', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function refresh(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('refresh_'.$id, $request->request->get('_token')))
        {
            throw $this->createAccessDeniedException();
        }
        $run = $this->reports->find($id) ?? throw $this->createNotFoundException();
        $newId = $this->enqueue(['id' => $run['employee_id'], 'name' => $run['employee_name']], new ReportPeriod($run['date_from'], $run['date_to'], $run['timezone']));
        return $this->redirectToRoute('report_show', ['id' => $newId]);
    }

    private function enqueue(array $employee, ReportPeriod $period): string
    {
        return $this->reports->create($employee, $period);
    }

    private function snapshot(string $id): array
    {
        $run = $this->reports->find($id) ?? throw $this->createNotFoundException();
        $run = array_intersect_key($run, array_flip(['id', 'employee_id', 'employee_name', 'date_from', 'date_to', 'timezone', 'status', 'checked_tasks', 'total_tasks', 'created_at', 'started_at', 'finished_at', 'last_processed_at', 'retry_at', 'error_code']));
        $rows = array_map(static function (array $row): array
        {
            $safe = array_intersect_key($row, array_flip(['id', 'title', 'status', 'responsibleId', 'role', 'seconds']));
            $safe['entries'] = array_map(static fn (array $entry): array => array_intersect_key($entry, array_flip(['ID', 'CREATED_DATE', 'SECONDS', 'COMMENT_TEXT'])), $row['entries']);
            return $safe;
        }, $this->reports->rows($id));
        $errors = array_map(static fn (array $error): array => array_intersect_key($error, array_flip(['id', 'task_id', 'task_title', 'stage', 'error_code', 'attempts', 'state', 'retry_at', 'created_at', 'updated_at'])), $this->reports->errors($id));
        return ['run' => $run, 'rows' => $rows, 'errors' => $errors, 'total' => ReportCalculator::formatSeconds(array_sum(array_column($rows, 'seconds'))), 'complete' => in_array($run['status'], ['complete', 'complete_with_errors', 'failed'], true)];
    }
}
