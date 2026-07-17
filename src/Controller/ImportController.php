<?php

namespace App\Controller;

use App\Service\AccessControl;
use App\Service\AppDatabase;
use App\Service\ImportService;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class ImportController extends AbstractController
{
    #[Route('/import', name: 'app_import', methods: ['GET'])]
    public function index(Request $request, AppDatabase $db, ImportService $imports, AccessControl $access): Response
    {
        $user = $this->requireImporter($request, $db, $access);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return $this->render('app/import.html.twig', [
            'user' => $user,
            'entities' => $imports->entities(),
            'tokens' => $this->importTokens($request, array_keys($imports->entities())),
        ]);
    }

    #[Route('/import/{entity}/template', name: 'app_import_template', methods: ['GET'])]
    public function template(string $entity, Request $request, AppDatabase $db, ImportService $imports, AccessControl $access): Response
    {
        $user = $this->requireImporter($request, $db, $access);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $response = new Response($imports->template($entity));
        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'simauto-' . $entity . '-template.csv');
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $disposition);
        return $response;
    }

    #[Route('/import/{entity}', name: 'app_import_upload', methods: ['POST'])]
    public function upload(string $entity, Request $request, AppDatabase $db, ImportService $imports, AccessControl $access): Response
    {
        $user = $this->requireImporter($request, $db, $access);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $report = null;
        $error = null;
        try {
            $this->verifyCsrf($request, 'import_' . $entity);
            $file = $request->files->get('file');
            if (!$file || !$file->isValid()) {
                throw new InvalidArgumentException('المرجو اختيار ملف CSV صحيح');
            }
            $report = $imports->import($entity, $file->getPathname(), (int) $user['id']);
        } catch (Throwable $e) {
            $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'تعذر تنفيذ الاستيراد';
        }

        return $this->render('app/import_result.html.twig', [
            'user' => $user,
            'entities' => $imports->entities(),
            'entity' => $entity,
            'report' => $report,
            'error' => $error,
            'tokens' => $this->importTokens($request, array_keys($imports->entities())),
        ]);
    }

    private function requireImporter(Request $request, AppDatabase $db, AccessControl $access): array|RedirectResponse
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
        if (!$access->can('import', $sessionUser)) {
            $this->addFlash('error', 'access.denied');
            return $this->redirectToRoute('app_dashboard');
        }
        return $sessionUser;
    }

    private function importTokens(Request $request, array $entities): array
    {
        $tokens = [];
        foreach ($entities as $entity) {
            $tokens[$entity] = $this->csrfToken($request, 'import_' . $entity);
        }
        return $tokens;
    }

    private function csrfToken(Request $request, string $key): string
    {
        $tokens = $request->getSession()->get('csrf_tokens', []);
        if (!isset($tokens[$key])) {
            $tokens[$key] = bin2hex(random_bytes(24));
            $request->getSession()->set('csrf_tokens', $tokens);
        }
        return $tokens[$key];
    }

    private function verifyCsrf(Request $request, string $key): void
    {
        $tokens = $request->getSession()->get('csrf_tokens', []);
        $expected = (string) ($tokens[$key] ?? '');
        $provided = (string) $request->request->get('_token', '');
        if ($expected === '' || !hash_equals($expected, $provided)) {
            throw new InvalidArgumentException('انتهت صلاحية الطلب، المرجو إعادة المحاولة');
        }
    }
}
