<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ForceRemoveFrameOptionsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // priorité max négative = exécuté en dernier
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/hiboutik/widget/')) {
            return;
        }

        $response = $event->getResponse();

        // 🔥 suppression définitive
        $response->headers->remove('X-Frame-Options');

        // 🔥 CSP moderne uniquement
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors https://multimediaservices22.hiboutik.com"
        );
    }
}