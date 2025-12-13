<?php

namespace App\Controller\Admin\Rachat;

use App\Service\HiboutikInventoryClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/arrivages', name: 'admin_arrivages_')]
final class ArrivageController extends AbstractController
{
    public function __construct(private HiboutikInventoryClient $hib) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // 🔎 Récupération et filtrage “RACHAT MENSUEL-”
        $arrivages = $this->hib->listMonthlyRachatInputs();
        // dd($arrivages);

        return $this->render('@SyliusAdmin/Arrivages/index.html.twig', [
            'arrivages' => $arrivages,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(): Response
    {
        $label = 'RACHAT MENSUEL-' . (new \DateTime())->format('m-Y');

        // Vérifie si déjà existant
        $existants = $this->hib->listMonthlyRachatInputs();
        foreach ($existants as $a) {
    if (($a['inventory_input_label'] ?? '') === $label) {
        $this->addFlash('info', "L’arrivage $label existe déjà.");
        return $this->redirectToRoute('admin_arrivages_index');
    }
}


        // Création
        $res = $this->hib->createInventoryInput(1, 3, $label);

        if (!($res['ok'] ?? false)) {
            $this->addFlash('error', 'Erreur Hiboutik (' . ($res['status'] ?? '??') . ')');
        } else {
            $this->addFlash('success', "Nouvel arrivage créé : $label");
        }

        return $this->redirectToRoute('admin_arrivages_index');
    }

    #[Route('/validate/{id}', name: 'validate', methods: ['POST'])]
    public function validate(int $id): Response
    {
        $res = $this->hib->validateInventoryInput($id);

        if (!($res['ok'] ?? false)) {
            $this->addFlash('error', 'Erreur Hiboutik (' . ($res['status'] ?? '??') . ')');
        } else {
            $this->addFlash('success', "Arrivage #$id validé !");
        }

        return $this->redirectToRoute('admin_arrivages_index');
    }

    #[Route('/details/{id}', name: 'details', methods: ['GET'])]
    public function details(int $id): Response
    {
        $res = $this->hib->listInventoryInputDetails($id);
        dd($res);
        $data = $res['data'] ?? [];

        return $this->render('@SyliusAdmin/Arrivages/details.html.twig', [
            'id' => $id,
            'details' => $data,
        ]);
    }
}
