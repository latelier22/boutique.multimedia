<?php

namespace App\Controller\Api;

use App\Repository\DisplayMessageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class DisplayMessageApiController extends AbstractController
{
    #[Route('/api/display-messages/{slot}', name: 'app_api_display_messages_by_slot', methods: ['GET'])]
    public function bySlot(string $slot, DisplayMessageRepository $repository): JsonResponse
    {
        $messages = $repository->findActiveForSlot($slot);

        
        $payload = array_map(static function ($m) {
            return [
                'id' => $m->getId(),
                'name' => $m->getName(),
                'slot' => $m->getSlot(),
                'template' => $m->getTemplate(),
                'badge' => $m->getBadge(),
                'title' => $m->getTitle(),
                'line1' => $m->getLine1(),
                'line2' => $m->getLine2(),
                'line3' => $m->getLine3(),
                'footerText' => $m->getFooterText(),
                'backgroundType' => $m->getBackgroundType(),
                'textAlign' => $m->getTextAlign(),
                'accentColor' => $m->getAccentColor(),
                'intervalSeconds' => $m->getIntervalSeconds(),
                'durationSeconds' => $m->getDurationSeconds(),
                'sortOrder' => $m->getSortOrder(),
                'startsAt' => $m->getStartsAt()?->format(\DateTimeInterface::ATOM),
                'endsAt' => $m->getEndsAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $messages);

        return $this->json($payload, 200, [
            // À adapter si ton front Next tourne sur Vercel avec un autre domaine
            // 'Access-Control-Allow-Origin' => 'https://ton-site.vercel.app',
        ]);
    }
}