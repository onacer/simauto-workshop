<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class LocaleController extends AbstractController
{
    #[Route('/locale/{locale}', name: 'app_locale', requirements: ['locale' => 'ar|fr'])]
    public function switch(string $locale, Request $request): RedirectResponse
    {
        $request->getSession()->set('_locale', $locale);
        $target = $request->headers->get('referer') ?: $this->generateUrl('app_dashboard');
        $response = new RedirectResponse($target);
        $response->headers->setCookie(Cookie::create('simauto_locale', $locale, strtotime('+1 year'), '/', null, false, false, false, Cookie::SAMESITE_LAX));

        return $response;
    }
}
