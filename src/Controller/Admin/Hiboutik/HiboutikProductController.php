<?php

namespace App\Controller\Admin\Hiboutik;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;

use App\Service\HiboutikClient;

//  array:28 [▼
//   "product_id" => 892
//   "product_model" => "Vitre Arrière Noire iPhone 15 (Pulled A)"
//   "product_barcode" => "2430000008924"
//   "product_brand" => 0
//   "product_supplier" => 0
//   "product_price" => "0.00"
//   "product_discount_price" => "0.00"
//   "product_supply_price" => "0.00"
//   "points_in" => 0
//   "points_out" => 0
//   "product_category" => 0
//   "product_size_type" => 0
//   "product_package" => 0
//   "product_stock_management" => 0
//   "product_supplier_reference" => ""
//   "product_vat" => 1
//   "product_display" => 1
//   "product_display_www" => 0
//   "product_arch" => 0
//   "products_desc" => ""
//   "product_font_color" => "#000000"
//   "product_bck_btn_color" => "#CCCCCC"
//   "products_ref_ext" => ""
//   "product_order" => 8
//   "weight" => "0.00"
//   "multiple" => 1
//   "updated_at" => "2026-02-25 11:43:36"
//   "product_specific_rules" => []
// ]

#[Route('/admin/hiboutik/product', name: 'admin_hiboutik_product_')]
final class HiboutikProductController extends AbstractController
{
    public function __construct(
        private HiboutikClient $hib,
        private EntityManagerInterface $em,

) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // 🔎 Récupération et filtrage “RACHAT”
        $products = $this->hib->getProducts();
        // dd($products);

        return $this->render('@SyliusAdmin/Hiboutik/Products/index.html.twig', [
            'products' => $products,
        ]);
    }

//     #[Route('/create', name: 'create', methods: ['POST'])]
//     public function create(): Response
//     {
//         $label = 'RACHAT MENSUEL-' . (new \DateTime())->format('m-Y');

//         // Vérifie si déjà existant
//         $existants = $this->hib->listMonthlyRachatInputs();
//         foreach ($existants as $a) {
//     if (($a['inventory_input_label'] ?? '') === $label) {
//         $this->addFlash('info', "L’arrivage $label existe déjà.");
//         return $this->redirectToRoute('admin_arrivages_index');
//     }
// }


//         // Création
//         $res = $this->hib->createInventoryInput(1, 3, $label);

//         if (!($res['ok'] ?? false)) {
//             $this->addFlash('error', 'Erreur Hiboutik (' . ($res['status'] ?? '??') . ')');
//         } else {
//             $this->addFlash('success', "Nouvel arrivage créé : $label");
//         }

//         return $this->redirectToRoute('admin_arrivages_index');
//     }

    // #[Route('/validate/{id}', name: 'validate', methods: ['POST'])]
    // public function validate(int $id): Response
    // {
    //     $res = $this->hib->validateInventoryInput($id);

    //     if (!($res['ok'] ?? false)) {
    //         $this->addFlash('error', 'Erreur Hiboutik (' . ($res['status'] ?? '??') . ')');
    //     } else {
    //         $this->addFlash('success', "Arrivage #$id validé !");
    //     }

    //     return $this->redirectToRoute('admin_arrivages_index');
    // }

    // #[Route('/details/{id}', name: 'details', methods: ['GET'])]
    // public function details(int $id): Response
    // {
    //     $res = $this->hib->listInventoryInputDetails($id);
        
    //     $data = $res['data'] ?? [];

    //     return $this->render('@SyliusAdmin/Arrivages/details.html.twig', [
    //         'id' => $id,
    //         'details' => $data,
    //     ]);
    // }

//   #[Route('/create-from-rachats', name: 'create_from_rachats', methods: ['POST'])]
// public function createFromRachats(Request $req): Response
// {
//     $ids = $req->request->all('ids');
//     if (!$ids) {
//         $this->addFlash('error', 'Sélection requise.');
//         return $this->redirectToRoute('admin_rachats_index');
//     }

//     $repo = $this->em->getRepository(Rachat::class);
//     $rachats = [];
//     foreach ($ids as $id) {
//         $r = $repo->find((int)$id);
//         if ($r) $rachats[] = $r;
//     }
//     if (!$rachats) {
//         $this->addFlash('error', 'Rachats introuvables.');
//         return $this->redirectToRoute('admin_rachats_index');
//     }

//     // ✅ revendeur/supplier unique
//     $first = $rachats[0];
//     $supplierId = (int)$first->getHibSupplierId();
//     $nom = (string)$first->getNom();
//     $prenom = (string)$first->getPrenom();

//     foreach ($rachats as $r) {
//         if ((int)$r->getHibSupplierId() !== $supplierId) {
//             $this->addFlash('error', 'Sélection invalide : plusieurs revendeurs (suppliers) différents.');
//             return $this->redirectToRoute('admin_rachats_index');
//         }
//     }

//     // ✅ 1 arrivage/jour/supplier
//     $isMulti = count($rachats) > 1;
//     $inv = $this->hib->ensureDailyRachatInput(1, $supplierId, $nom, $prenom, $isMulti);

//     if (!($inv['ok'] ?? false)) {
//         $this->addFlash('error', 'Erreur création/récup arrivage.');
//         return $this->redirectToRoute('admin_rachats_index');
//     }

//     $inventoryInputId = (int)($inv['data']['inventory_input_id'] ?? $inv['data']['id'] ?? $inv['id'] ?? 0);
//     if ($inventoryInputId <= 0) {
//         $this->addFlash('error', 'Impossible de récupérer l’ID de l’arrivage Hiboutik.');
//         return $this->redirectToRoute('admin_rachats_index');
//     }

//     // ✅ éviter doublons dans l’arrivage
//     $details = $this->hib->listInventoryInputDetails($inventoryInputId);
//     $already = [];
//     foreach (($details['data'] ?? []) as $d) {
//         $pid = (int)($d['product_id'] ?? 0);
//         if ($pid) $already[$pid] = true;
//     }

//     $added = 0;
//     foreach ($rachats as $r) {
//         $hibProductId = (int)$r->getHibProductId();
//         if ($hibProductId <= 0) continue; // ou: appeler ton ensureHiboutikProductForRachat ici

//         if (isset($already[$hibProductId])) continue;

//         $res = $this->hib->addProductToInventoryInput($inventoryInputId, $hibProductId, 1);
//         if (($res['ok'] ?? false)) {
//             $added++;
//             $already[$hibProductId] = true;
//         }
//     }

//     $this->addFlash('success', sprintf(
//         '%s : arrivage #%d (%s) — %d produit(s) ajouté(s).',
//         ($inv['created'] ?? false) ? 'Créé' : 'Réutilisé',
//         $inventoryInputId,
//         $inv['label'] ?? '',
//         $added
//     ));

//     return $this->redirectToRoute('admin_arrivages_index');
// }

}
