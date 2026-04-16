<?php

namespace App\Controller\Admin\Rachat;

use App\Entity\Rachat\RachatItem;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ReconditioningPrintController extends AbstractController
{
    #[Route('/admin/reconditionnement/item/{id}/ticket', name: 'admin_reconditioning_print_ticket', methods: ['GET'])]
    public function printTicket(RachatItem $item): Response
    {
        $dossier = $item->getDossier();

        return $this->render('@SyliusAdmin/Rachat/Reconditioning/print_ticket.html.twig', [
            'ticket' => [
                'itemId' => $item->getId(),
                'dossierRef' => $dossier?->getReference(),
                'date' => $item->getUpdatedAt() ?? $item->getCreatedAt(),
                'status' => $item->getReconditioningStatus(),
                'boxCode' => $item->getBoite()?->getCode(),
                'lastName' => $dossier?->getNomSnapshot(),
                'firstName' => $dossier?->getPrenomSnapshot(),
                'phone' => $dossier?->getTelephoneSnapshot(),
                'email' => $dossier?->getEmailSnapshot(),
                'deviceLabel' => $item->getMarqueModele() ?: $item->getDesignation(),
                'imei' => $item->getImei(),
                'workToDo' => $item->getReconditioningWorkToDo(),
                'notes' => $item->getReconditioningNotes(),
                'hibProductId' => $item->getHibProductId(),
            ],
        ]);
    }
}