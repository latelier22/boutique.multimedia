<?php

namespace App\Controller\Admin\Rachat;

use App\Entity\Rachat\RachatItem;
use App\Repository\Rachat\RachatItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/reconditionnement', name: 'admin_reconditioning_')]
final class ReconditioningController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private RachatItemRepository $itemRepository,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = trim((string) $request->query->get('status', 'open'));
        $q = trim((string) $request->query->get('q', ''));

        $items = $this->itemRepository->findForReconditioning($status, $q);
        $counts = $this->itemRepository->countByReconditioningStatus($q);

        return $this->render('@SyliusAdmin/Rachat/Reconditioning/index.html.twig', [
            'items' => $items,
            'status' => $status,
            'q' => $q,
            'counts' => $counts,
            'statusChoices' => $this->getStatusChoices(),
            'statusValues' => [
                'none' => 'none',
                'todo' => 'todo',
                'in_progress' => 'in_progress',
                'done' => 'done',
                'failed' => 'failed',
                'parts' => 'parts',
            ],
        ]);
    }

    #[Route('/item/{id}/status', name: 'item_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateStatus(Request $request, RachatItem $item): Response
    {
        if (!$this->isCsrfTokenValid('reconditioning_status_' . $item->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $status = trim((string) $request->request->get('status', 'none'));
        $allowed = array_keys($this->getStatusChoices());

        if (!in_array($status, $allowed, true)) {
            $this->addFlash('error', 'Statut atelier invalide.');
            return $this->redirect($this->safeRedirect($request));
        }

        $item->setReconditioningStatus($status);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Item #%d mis à jour : %s.',
            $item->getId(),
            $this->getStatusChoices()[$status]
        ));

        return $this->redirect($this->safeRedirect($request));
    }

    private function getStatusChoices(): array
    {
        return [
            'none' => 'Pas de reconditionnement',
            'todo' => 'À reconditionner',
            'in_progress' => 'En cours',
            'done' => 'Reconditionné',
            'failed' => 'Échec',
            'parts' => 'Pour pièces',
        ];
    }

    private function safeRedirect(Request $request): string
    {
        $redirect = (string) $request->request->get('_redirect', '');
        $path = (string) (parse_url($redirect, PHP_URL_PATH) ?? '');

        if ($redirect !== '' && str_starts_with($path, '/admin/reconditionnement')) {
            return $redirect;
        }

        return $this->generateUrl('admin_reconditioning_index');
    }
}