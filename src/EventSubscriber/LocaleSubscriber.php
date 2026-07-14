<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LocaleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $locale = $request->getSession()->get('_locale') ?: $request->cookies->get('simauto_locale') ?: 'ar';
        if (!in_array($locale, ['ar', 'fr'], true)) {
            $locale = 'ar';
        }
        $request->setLocale($locale);
    }
}
