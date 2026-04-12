<?php

namespace App\Controller\Admin\Hiboutik;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ApiCacheController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/admin/apicache/worker-status', name: 'app_admin_apicache_worker_status', methods: ['GET'])]
    public function workerStatus(): Response
    {
        $apiUrl = 'https://api.multimedia-services.fr/api/worker-status';

        try {
            $response = $this->httpClient->request('GET', $apiUrl);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $data = [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }

        return $this->render('@SyliusAdmin/Hiboutik/ApiCache/worker_status.html.twig', [
            'status' => $data,
        ]);
    }

    #[Route('/admin/apicache/worker-status-iframe', name: 'app_admin_apicache_worker_status', methods: ['GET'])]
    public function workerStatusIframe(): Response
    {
        return $this->render('@SyliusAdmin/Hiboutik/ApiCache/worker_status_iframe.html.twig', [
            'workerStatusUrl' => 'https://api.multimedia-services.fr/worker-status',
        ]);
    }
    
}
