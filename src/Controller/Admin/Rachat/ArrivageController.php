<?php

namespace App\Controller\Admin\Rachat;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Rachat\Rachat;
use App\Service\HiboutikClient;

#[Route('/admin/arrivages', name: 'admin_arrivages_')]
final class ArrivageController extends AbstractController
{
    public function __construct(
        private HiboutikClient $hib,
        private EntityManagerInterface $em,

) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // 🔎 Récupération et filtrage “RACHAT”
        $arrivages = $this->hib->listRachatInputs();
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
        } 

        if (!($res['ok'] ?? false)) {
            $dbg = $this->hib->getLastDebug();
            $this->addFlash('error', 'Erreur Hiboutik (' . ($res['status'] ?? '??') . ') : ' . substr((string)($dbg['raw'] ?? ''), 0, 200));
            return $this->redirectToRoute('admin_arrivages_index');
        }
        else {
            $this->addFlash('success', "Arrivage #$id validé !");
        }

        return $this->redirectToRoute('admin_arrivages_index');
    }

    #[Route('/details/{id}', name: 'details', methods: ['GET'])]
    public function details(int $id): Response
    {
        $res = $this->hib->listInventoryInputDetails($id);
        
        $data = $res['data'] ?? [];

        return $this->render('@SyliusAdmin/Arrivages/details.html.twig', [
            'id' => $id,
            'details' => $data,
        ]);
    }

  #[Route('/create-from-rachats', name: 'create_from_rachats', methods: ['POST'])]
public function createFromRachats(Request $req): Response
{
    $ids = $req->request->all('ids');
    if (!$ids) {
        $this->addFlash('error', 'Sélection requise.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    $repo = $this->em->getRepository(Rachat::class);
    $rachats = [];
    foreach ($ids as $id) {
        $r = $repo->find((int)$id);
        if ($r) $rachats[] = $r;
    }
    if (!$rachats) {
        $this->addFlash('error', 'Rachats introuvables.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    // ✅ revendeur/supplier unique
    $first = $rachats[0];
    $supplierId = (int)$first->getHibSupplierId();
    $nom = (string)$first->getNom();
    $prenom = (string)$first->getPrenom();

    foreach ($rachats as $r) {
        if ((int)$r->getHibSupplierId() !== $supplierId) {
            $this->addFlash('error', 'Sélection invalide : plusieurs revendeurs (suppliers) différents.');
            return $this->redirectToRoute('admin_rachats_index');
        }
    }

    // ✅ 1 arrivage/jour/supplier
    $isMulti = count($rachats) > 1;
    $inv = $this->hib->ensureDailyRachatInput(1, $supplierId, $nom, $prenom, $isMulti);

    if (!($inv['ok'] ?? false)) {
        $this->addFlash('error', 'Erreur création/récup arrivage.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    $inventoryInputId = (int)($inv['data']['inventory_input_id'] ?? $inv['data']['id'] ?? $inv['id'] ?? 0);
    if ($inventoryInputId <= 0) {
        $this->addFlash('error', 'Impossible de récupérer l’ID de l’arrivage Hiboutik.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    // ✅ éviter doublons dans l’arrivage
    $details = $this->hib->listInventoryInputDetails($inventoryInputId);
    $already = [];
    foreach (($details['data'] ?? []) as $d) {
        $pid = (int)($d['product_id'] ?? 0);
        if ($pid) $already[$pid] = true;
    }

    $added = 0;
    foreach ($rachats as $r) {
        $hibProductId = (int)$r->getHibProductId();
        if ($hibProductId <= 0) continue; // ou: appeler ton ensureHiboutikProductForRachat ici

        if (isset($already[$hibProductId])) continue;

        $res = $this->hib->addProductToInventoryInput($inventoryInputId, $hibProductId, 1);
        if (($res['ok'] ?? false)) {
            $added++;
            $already[$hibProductId] = true;
        }
    }

    $this->addFlash('success', sprintf(
        '%s : arrivage #%d (%s) — %d produit(s) ajouté(s).',
        ($inv['created'] ?? false) ? 'Créé' : 'Réutilisé',
        $inventoryInputId,
        $inv['label'] ?? '',
        $added
    ));

    return $this->redirectToRoute('admin_arrivages_index');
}

}
