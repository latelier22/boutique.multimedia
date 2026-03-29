<?php

namespace App\Controller\Tablet;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TabletLiveController extends AbstractController
{
    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {}

    private function key(string $device): string
    {
        $device = preg_replace('/[^A-Za-z0-9_-]/', '', $device) ?: 'TAB1';

        return 'tablet_live_state_' . $device;
    }

    #[Route('/admin/tablet/live/reset/{device}', name: 'app_admin_tablet_live_reset', methods: ['POST'])]
public function reset(string $device): JsonResponse
{
    $device = preg_replace('/[^A-Za-z0-9_-]/', '', $device) ?: 'TAB1';
    $this->cache->deleteItem($this->key($device));

    return $this->json([
        'ok' => true,
        'device' => $device,
    ]);
}

    #[Route('/tablet/live/publish', name: 'tablet_live_publish', methods: ['POST'])]
    public function publish(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'ok' => false,
                'error' => 'JSON invalide',
            ], 400);
        }

        $device = (string) ($data['device'] ?? 'TAB1');
        $device = preg_replace('/[^A-Za-z0-9_-]/', '', $device) ?: 'TAB1';

        $item = $this->cache->getItem($this->key($device));
        $prev = $item->isHit() && is_array($item->get()) ? $item->get() : [];

        $state = [
            'device' => $device,
            'screen' => (string) ($data['screen'] ?? ''),
            'status' => (string) ($data['status'] ?? 'editing'),
            'interventionId' => $data['interventionId'] ?? null,
            'hiboutikCustomerId' => $data['hiboutikCustomerId'] ?? null,
            'activeField' => (string) ($data['activeField'] ?? ''),
            'form' => is_array($data['form'] ?? null) ? $data['form'] : [],
            'rev' => ((int) ($prev['rev'] ?? 0)) + 1,
            'updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $item->set($state);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        return $this->json([
            'ok' => true,
            'rev' => $state['rev'],
            'updatedAt' => $state['updatedAt'],
        ]);
    }

    #[Route('/admin/tablet/live/state/{device}', name: 'app_admin_tablet_live_state', methods: ['GET'])]
    public function state(string $device): JsonResponse
    {
        $item = $this->cache->getItem($this->key($device));

        return $this->json([
            'ok' => true,
            'state' => $item->isHit() ? $item->get() : null,
        ]);
    }
}