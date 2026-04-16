<?php

namespace App\Controller\Admin\Hiboutik;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ApiCacheController extends AbstractController
{
    public function __construct(
        private readonly string $apiCacheBaseUrl,
        private readonly string $webhook_secret,
    ) {
    }

    #[Route('/admin/apicache/worker-status', name: 'app_admin_apicache_worker_status', methods: ['GET'])]
    public function workerStatusIframe(): Response
    {
        $workerStatusUrl = rtrim($this->apiCacheBaseUrl, '/')
            . '/admin/ops/worker-status-page?secret='
            . rawurlencode($this->webhook_secret);

        return $this->render('@SyliusAdmin/Hiboutik/ApiCache/worker_status_iframe.html.twig', [
            'workerStatusUrl' => $workerStatusUrl,
        ]);
    }
}