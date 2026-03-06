<?php

namespace App\Controller\Admin\Hiboutik;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;

use App\Service\HiboutikClient;

use Symfony\Component\HttpFoundation\RedirectResponse;


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
//   "product_specific_rules" => []qqq
// ]

#[Route('/admin/hiboutik/product', name: 'admin_hiboutik_product_')]
final class HiboutikProductController extends AbstractController
{
    public function __construct(
        private HiboutikClient $hib,
        private EntityManagerInterface $em,

) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // -------------------------
        // FILTRES GET
        // -------------------------
        $q     = trim((string)$request->query->get('q', ''));
        $www   = (string)$request->query->get('www', '');    // '', '0', '1'
        $cat   = (string)$request->query->get('cat', '');    // '' ou id
        $sup   = (string)$request->query->get('sup', '');    // '' ou id
        $tag   = (string)$request->query->get('tag', '');    // '' ou id
        $brand = (string)$request->query->get('brand', '');  // '' ou id

        if ($www !== '' && $www !== '0' && $www !== '1') $www = '';
        if ($cat !== '' && !ctype_digit($cat)) $cat = '';
        if ($sup !== '' && !ctype_digit($sup)) $sup = '';
        if ($tag !== '' && !ctype_digit($tag)) $tag = '';
        if ($brand !== '' && !ctype_digit($brand)) $brand = '';

        $wantCat   = $cat !== '' ? (int)$cat : 0;
        $wantSup   = $sup !== '' ? (int)$sup : 0;
        $wantTag   = $tag !== '' ? (int)$tag : 0;
        $wantBrand = $brand !== '' ? (int)$brand : 0;

        // -------------------------
        // 0) TAG CATALOG pour le SELECT (IMPORTANT)
        // -------------------------
        $tagCatalog = $this->hib->buildProductTagChoices();
        $tagChoices = $tagCatalog['choices'] ?? [];
        $tagMap     = $tagCatalog['map'] ?? [];

        // -------------------------
        // 1) CHOICES Hiboutik (Brands/Cats/Suppliers)
        // -------------------------
        $brandsRes = $this->hib->listBrands();
        $catsRes   = $this->hib->listCategories();
        $supsRes   = $this->hib->listSuppliers();

        $brandChoices = [];
        $brandMapName = [];
        if (($brandsRes['ok'] ?? false) && is_array($brandsRes['data'] ?? null)) {
            foreach ($brandsRes['data'] as $b) {
                $bid  = (int)($b['brand_id'] ?? 0);
                $name = trim((string)($b['brand_name'] ?? $b['name'] ?? ''));
                if ($bid > 0 && $name !== '') {
                    $brandChoices[$name] = $bid;
                    $brandMapName[$bid] = $name;
                }
            }
        }
        ksort($brandChoices, SORT_NATURAL | SORT_FLAG_CASE);

        $catChoices = [];
        $catMapName = [];
        if (($catsRes['ok'] ?? false) && is_array($catsRes['data'] ?? null)) {
            foreach ($catsRes['data'] as $c) {
                $cid  = (int)($c['category_id'] ?? 0);
                $name = trim((string)($c['category_name'] ?? $c['name'] ?? ''));
                if ($cid > 0 && $name !== '') {
                    $catChoices[$name] = $cid;
                    $catMapName[$cid] = $name;
                }
            }
        }
        ksort($catChoices, SORT_NATURAL | SORT_FLAG_CASE);

        $supplierChoices = [];
        $supMapName      = [];
        if (($supsRes['ok'] ?? false) && is_array($supsRes['data'] ?? null)) {
            foreach ($supsRes['data'] as $s) {
                $sid  = (int)($s['supplier_id'] ?? 0);
                $name = trim((string)($s['supplier_name'] ?? $s['name'] ?? ''));
                if ($sid > 0 && $name !== '') {
                    $supplierChoices[$name] = $sid;
                    $supMapName[$sid] = $name;
                }
            }
        }
        ksort($supplierChoices, SORT_NATURAL | SORT_FLAG_CASE);

        // -------------------------
        // 2) PRODUITS (paginés / tous) puis FILTRAGE
        // -------------------------
        $products = $this->hib->getProductsAll(); // ta méthode paginée (tous produits)
        $qLower = mb_strtolower($q);

        $rows = [];
        foreach ($products as $p) {
            if (!is_array($p)) continue;

            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) continue;

            $pWww   = !empty($p['product_display_www']) ? '1' : '0';
            $pCat   = (int)($p['product_category'] ?? 0);
            $pSup   = (int)($p['product_supplier'] ?? 0);
            $pBrand = (int)($p['product_brand'] ?? 0);

            // noms lisibles (cat/supplier/brand)
            $p['category_name'] = $catMapName[$pCat] ?? '—';
            $p['supplier_name'] = $supMapName[$pSup] ?? '—';
            $p['brand_name']    = (string)($p['product_brand_name'] ?? ($brandMapName[$pBrand] ?? '—'));

            // filtres simples (avant tags, pour éviter trop d'appels)
            if ($www !== '' && $pWww !== $www) continue;
            if ($wantCat > 0 && $pCat !== $wantCat) continue;
            if ($wantSup > 0 && $pSup !== $wantSup) continue;
            if ($wantBrand > 0 && $pBrand !== $wantBrand) continue;

            // recherche texte (sans tags pour l’instant)
            if ($qLower !== '') {
                $hay = mb_strtolower(
                    (string)($p['product_model'] ?? '') . ' ' .
                    (string)($p['short_label'] ?? '') . ' ' .
                    (string)($p['product_barcode'] ?? '') . ' ' .
                    (string)($p['product_id'] ?? '') . ' ' .
                    (string)($p['category_name'] ?? '') . ' ' .
                    (string)($p['supplier_name'] ?? '') . ' ' .
                    (string)($p['brand_name'] ?? '')
                );
                if (mb_strpos($hay, $qLower) === false) continue;
            }

            $rows[] = $p;
        }

        // -------------------------
        // 3) ENRICH TAGS (SEULEMENT sur rows affichés)
        //     + filtre tag (à ce moment)
        // -------------------------
        $final = [];
        foreach ($rows as $p) {
            $pid = (int)($p['product_id'] ?? 0);

            // récup tags du produit
            $tres  = $this->hib->listTagsForProduct($pid);
            $tdata = $tres['data'] ?? [];
            if (is_array($tdata) && isset($tdata['data']) && is_array($tdata['data'])) {
                $tdata = $tdata['data'];
            }
            if (!is_array($tdata)) $tdata = [];

            // normaliser: [{tag_id, tag_label}]
            $norm = [];
            foreach ($tdata as $t) {
                $tid = (int)($t['tag_id'] ?? $t['id'] ?? 0);
                $lab = (string)($t['tag'] ?? $t['tag_label'] ?? ($tagMap[$tid] ?? ''));
                $lab = trim($lab);
                if ($tid > 0 && $lab !== '') $norm[] = ['tag_id' => $tid, 'tag_label' => $lab];
            }
            $p['tags'] = $norm;

            // filtre tag (maintenant qu'on a les tags)
            if ($wantTag > 0) {
                $hit = false;
                foreach ($norm as $t) {
                    if ((int)($t['tag_id'] ?? 0) === $wantTag) { $hit = true; break; }
                }
                if (!$hit) continue;
            }

            // recherche inclut tags (bonus)
            if ($qLower !== '') {
                $hayTags = '';
                foreach ($norm as $t) $hayTags .= ' ' . mb_strtolower((string)($t['tag_label'] ?? ''));
                if ($hayTags !== '' && mb_strpos($hayTags, $qLower) === false) {
                    // si tu veux que q cherche aussi dans tags, enlève ce if.
                    // Là je ne filtre pas 2 fois.
                }
            }

            $final[] = $p;
        }

        return $this->render('@SyliusAdmin/Hiboutik/Products/index.html.twig', [
            'products' => $final,

            'brandChoices' => $brandChoices,
            'catChoices' => $catChoices,
            'supplierChoices' => $supplierChoices,

            // ✅ TAGS SELECT (IMPORTANT)
            'tagChoices' => $tagChoices,

            'filters' => [
                'q' => $q,
                'www' => $www,
                'brand' => $brand,
                'cat' => $cat,
                'sup' => $sup,
                'tag' => $tag,
            ],

            // debug très utile si besoin
            // 'debug_tag_count' => count($tagChoices),
        ]);
    }

    #[Route('/www-batch', name: 'www_batch', methods: ['POST'])]
    public function wwwBatch(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('hib_www_batch', (string)$request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        $val = (string)$request->request->get('val', ''); // '0' ou '1'
        if ($val !== '0' && $val !== '1') {
            $this->addFlash('error', 'Valeur batch invalide.');
            return $this->redirectToRoute('admin_hiboutik_product_index');
        }

        $ids = $request->request->all('ids');
        $ids = array_values(array_filter(array_map('intval', (array)$ids), fn($x) => $x > 0));

        if (!$ids) {
            $this->addFlash('error', 'Sélection requise.');
            return $this->redirectToRoute('admin_hiboutik_product_index');
        }

        $ok = 0; $ko = 0; $firstId = $ids[0];

        foreach ($ids as $pid) {
            $res = $this->hib->putProductAttributeSingle($pid, 'product_display_www', $val);
            if (($res['ok'] ?? false)) $ok++; else $ko++;
        }

        $this->addFlash('success', sprintf('Batch WWW=%s : %d OK / %d KO', $val, $ok, $ko));
        return $this->redirectToRoute('admin_hiboutik_product_index', ['focus' => $firstId]);
    }



    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $product = $this->hib->getProduct($id);
        if (!$product) {
            $this->addFlash('error', "Produit Hiboutik #$id introuvable.");
            return $this->redirectToRoute('admin_hiboutik_product_index');
        }

        // listes pour selects
        $catsRes = $this->hib->listCategories();
        $brandsRes = $this->hib->listBrands();

        $categories = is_array($catsRes['data'] ?? null) ? $catsRes['data'] : [];
        $brands     = is_array($brandsRes['data'] ?? null) ? $brandsRes['data'] : [];

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('hiboutik_edit_' . $id, (string)$request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('CSRF invalid');
            }

            // normalisation checkbox
            $www = $request->request->has('product_display_www') ? '1' : '0';

            // champs à pousser (uniquement ceux du formulaire)
            $fields = [
                'product_model'          => trim((string)$request->request->get('product_model', '')),
                'product_price'          => (string)$request->request->get('product_price', ''),
                'product_discount_price' => (string)$request->request->get('product_discount_price', ''),
                'product_category'       => (string)$request->request->get('product_category', '0'),
                'product_brand'          => (string)$request->request->get('product_brand', '0'),
                'product_display_www'    => $www,
            ];

            // (optionnel) petit nettoyage prix
            foreach (['product_price','product_discount_price'] as $k) {
                $v = str_replace(',', '.', trim((string)$fields[$k]));
                if ($v === '') $v = '0.00';
                // garde 2 décimales si numérique
                if (is_numeric($v)) $v = number_format((float)$v, 2, '.', '');
                $fields[$k] = $v;
            }

            $res = $this->hib->updateProductAttributes($id, $fields);

            if (($res['ok'] ?? false) === true) {
                $this->addFlash('success', "Produit #$id mis à jour.");
                return $this->redirectToRoute('admin_hiboutik_product_index', ['focus' => $id]);
            }

            $error = "Update Hiboutik KO (status " . ($res['status'] ?? '??') . ") : " . ($res['raw'] ?? '—');
            $this->addFlash('error', $error);

            // recharger le produit pour réafficher
            $product = $this->hib->getProduct($id);
        }

        return $this->render('@SyliusAdmin/Hiboutik/Products/edit.html.twig', [
            'id' => $id,
            'product' => $product,
            'categories' => $categories,
            'brands' => $brands,
            'error' => $error,
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
