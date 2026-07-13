<?php

namespace App\Controller;

use App\Service\AppDatabase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AppDatabase $db): Response
    {
        if ($request->getSession()->get('user')) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $session = $request->getSession();
            $blockedUntil = (int) $session->get('login_block_until', 0);
            if ($blockedUntil > time()) {
                return $this->render('auth/login.html.twig', [
                    'error' => 'تم إيقاف المحاولة مؤقتا. المرجو الانتظار قليلا',
                ]);
            }

            $email = (string) $request->request->get('email', '');
            $password = (string) $request->request->get('password', '');
            $user = $db->userByEmail($email);

            if ($user && (int) $user['active'] !== 1) {
                $error = 'هذا الحساب غير نشط';
            } elseif ($user && $db->isPasswordValid($user, $password)) {
                if (password_needs_rehash((string) $user['password'], PASSWORD_BCRYPT) || hash_equals((string) $user['password'], $password)) {
                    $db->rehashUserPassword((int) $user['id'], $password);
                }

                $session->remove('login_failures');
                $session->remove('login_block_until');
                $session->set('user', [
                    'id' => (int) $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                ]);

                return $this->redirectToRoute('app_dashboard');
            } else {
                $failures = (int) $session->get('login_failures', 0) + 1;
                $session->set('login_failures', $failures);
                if ($failures >= 5) {
                    $session->set('login_block_until', time() + 60);
                    $error = 'محاولات كثيرة. المرجو الانتظار دقيقة واحدة';
                } else {
                    $error = 'بيانات الدخول غير صحيحة';
                }
            }
        }

        return $this->render('auth/login.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(Request $request): RedirectResponse
    {
        $request->getSession()->clear();
        return $this->redirectToRoute('app_login');
    }
}
