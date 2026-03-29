<?php

namespace App\Controller\Admin\Hiboutik;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;

use App\Service\HiboutikClient;
use App\Service\CacheApiClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use App\Entity\Rachat\AttributeDefinition;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Service\ProductLabelPdfGenerator;
use App\Service\ProductLabelTcpdfGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Picqer\Barcode\BarcodeGeneratorPNG;

#[Route('/admin/hiboutik/product', name: 'admin_hiboutik_product_')]
final class HiboutikProductController extends AbstractController
{
    public function __construct(
        private HiboutikClient $hib,
        private CacheApiClient $cacheApi,
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
        private ProductLabelPdfGenerator $labelPdfGenerator,
        private ProductLabelTcpdfGenerator $labelTcpdfGenerator,
        private RequestStack $requestStack, // pour le flash
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
    // -------------------------
    // FILTRES GET
    // -------------------------
    $q     = trim((string)$request->query->get('q', ''));
    $www   = (string)$request->query->get('www', '');
    $cat   = (string)$request->query->get('cat', '');
    $sup   = (string)$request->query->get('sup', '');
    $tag   = (string)$request->query->get('tag', '');
    $brand = (string)$request->query->get('brand', '');

    if (!in_array($www, ['', '0', '1'], true)) $www = '';
    if ($cat !== '' && !ctype_digit($cat)) $cat = '';
    if ($sup !== '' && !ctype_digit($sup)) $sup = '';
    if ($tag !== '' && !ctype_digit($tag)) $tag = '';
    if ($brand !== '' && !ctype_digit($brand)) $brand = '';

    $wantCat   = $cat !== '' ? (int)$cat : 0;
    $wantSup   = $sup !== '' ? (int)$sup : 0;
    $wantTag   = $tag !== '' ? (int)$tag : 0;
    $wantBrand = $brand !== '' ? (int)$brand : 0;

    // -------------------------
    // TAGS (pour SELECT seulement)
    // -------------------------
    $tagCatalog = $this->hib->buildProductTagChoices();
    $tagChoices = $tagCatalog['choices'] ?? [];

    // -------------------------
    // CHOICES Hiboutik (léger → OK)
    // -------------------------
    $brandsRes = $this->hib->listBrands();
    $catsRes   = $this->hib->listCategories();
    $supsRes   = $this->hib->listSuppliers();

    $brandChoices = [];
    $brandMapName = [];
    foreach (($brandsRes['data'] ?? []) as $b) {
        $id = (int)($b['brand_id'] ?? 0);
        $name = trim((string)($b['brand_name'] ?? ''));
        if ($id && $name) {
            $brandChoices[$name] = $id;
            $brandMapName[$id] = $name;
        }
    }

    $catChoices = [];
    $catMapName = [];
    foreach (($catsRes['data'] ?? []) as $c) {
        $id = (int)($c['category_id'] ?? 0);
        $name = trim((string)($c['category_name'] ?? ''));
        if ($id && $name) {
            $catChoices[$name] = $id;
            $catMapName[$id] = $name;
        }
    }

    $supplierChoices = [];
    $supMapName = [];
    foreach (($supsRes['data'] ?? []) as $s) {
        $id = (int)($s['supplier_id'] ?? 0);
        $name = trim((string)($s['supplier_name'] ?? ''));
        if ($id && $name) {
            $supplierChoices[$name] = $id;
            $supMapName[$id] = $name;
        }
    }

    // -------------------------
    // PRODUITS → API CACHE 🚀
    // -------------------------
    $params = [
        'from' => 0,
        'to' => 2000,
    ];

    if ($q !== '') $params['q'] = $q;
    if ($wantCat > 0) $params['product_category'] = $wantCat;

    // 👉 TAG → endpoint dédié
    if ($wantTag > 0) {
        $products = $this->cacheApi->getProductsByTag($wantTag);
    } else {
        $products = $this->cacheApi->getProducts($params);
    }

    $qLower = mb_strtolower($q);

    // -------------------------
    // FILTRAGE FINAL (rapide)
    // -------------------------
    $final = [];

    foreach ($products as $p) {
        if (!is_array($p)) continue;

        $pid = (int)($p['product_id'] ?? 0);
        if ($pid <= 0) continue;

        $pWww   = !empty($p['product_display_www']) ? '1' : '0';
        $pCat   = (int)($p['product_category'] ?? 0);
        $pSup   = (int)($p['product_supplier'] ?? 0);
        $pBrand = (int)($p['product_brand'] ?? 0);

        // noms lisibles
        $p['category_name'] = $catMapName[$pCat] ?? '—';
        $p['supplier_name'] = $supMapName[$pSup] ?? '—';
        $p['brand_name']    = $brandMapName[$pBrand] ?? '—';

        // filtres
        if ($www !== '' && $pWww !== $www) continue;
        if ($wantSup > 0 && $pSup !== $wantSup) continue;
        if ($wantBrand > 0 && $pBrand !== $wantBrand) continue;

        // recherche
        if ($qLower !== '') {
            $hay = mb_strtolower(
                ($p['product_model'] ?? '') . ' ' .
                ($p['product_barcode'] ?? '') . ' ' .
                ($p['category_name'] ?? '') . ' ' .
                ($p['supplier_name'] ?? '') . ' ' .
                ($p['brand_name'] ?? '')
            );

            if (mb_strpos($hay, $qLower) === false) continue;
        }

        // tags déjà présents dans le cache
        $p['tags'] = $p['tags'] ?? [];

        $final[] = $p;    

    }

    usort($final, function ($a, $b) {
    $aTime = strtotime($a['updatedAt'] ?? $a['updated_at'] ?? '1970-01-01');
    $bTime = strtotime($b['updatedAt'] ?? $b['updated_at'] ?? '1970-01-01');

    return $bTime <=> $aTime;
});

    // -------------------------
    // RENDER
    // -------------------------
    return $this->render('@SyliusAdmin/Hiboutik/Products/index.html.twig', [
        'products' => $final,
        'brandChoices' => $brandChoices,
        'catChoices' => $catChoices,
        'supplierChoices' => $supplierChoices,
        'tagChoices' => $tagChoices,
        'filters' => [
            'q' => $q,
            'www' => $www,
            'brand' => $brand,
            'cat' => $cat,
            'sup' => $sup,
            'tag' => $tag,
        ],
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


#[Route('/new', name: 'new', methods: ['POST'])]
public function new(Request $request): Response
{
    if (!$this->isCsrfTokenValid('hiboutik_product_new', (string)$request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $catsRes = $this->hib->listCategories();
    $categories = is_array($catsRes['data'] ?? null) ? $catsRes['data'] : [];

    $defaultCategoryId = $this->findDefaultPhoneOccasionCategoryId($categories);

    $productModel = trim((string)$request->request->get('product_model', 'Nouveau produit'));
    $productPrice = str_replace(',', '.', trim((string)$request->request->get('product_price', '0.00')));
    if ($productPrice === '' || !is_numeric($productPrice)) {
        $productPrice = '0.00';
    } else {
        $productPrice = number_format((float)$productPrice, 2, '.', '');
    }

    $payload = [
        'product_model'            => $productModel,
        'product_price'            => $productPrice,
        'product_supply_price'     => $productPrice,
        'product_stock_management' => 1,
        'product_display_www'      => 0,
        'product_arch'             => 0,
    ];

    if ($defaultCategoryId) {
        $payload['product_category'] = (string)$defaultCategoryId;
    }

    $created = $this->hib->createProduct($payload);

    $productId = 0;

    if (is_array($created)) {
        if (isset($created['product_id'])) {
            $productId = (int)$created['product_id'];
        } elseif (isset($created[0]['product_id'])) {
            $productId = (int)$created[0]['product_id'];
        }
    }

    if ($productId <= 0) {
        $this->addFlash('error', 'Impossible de créer le produit Hiboutik.');
        return $this->redirectToRoute('admin_hiboutik_product_index');
    }

    $this->refreshProduct($productId);

    $this->addFlash('success', sprintf('Produit Hiboutik #%d créé.', $productId));

    return $this->redirectToRoute('admin_hiboutik_product_edit', [
        'id' => $productId,
    ]);
}



#[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
public function edit(int $id, Request $request): Response
{
    $product = $this->hib->getProduct($id);

    if (!$product) {
        $this->addFlash('error', "Produit Hiboutik #$id introuvable.");
        return $this->redirectToRoute('admin_hiboutik_product_index');
    }

    // -------------------------
    // LISTES POUR SELECT
    // -------------------------
    $catsRes   = $this->hib->listCategories();
    $brandsRes = $this->hib->listBrands();

    $categories = is_array($catsRes['data'] ?? null) ? $catsRes['data'] : [];
    $brands     = is_array($brandsRes['data'] ?? null) ? $brandsRes['data'] : [];

    $tagCatalog = $this->hib->buildGroupedProductTagCatalog();
    $tagGroups = $tagCatalog['groups'] ?? [];

    $currentTagIds = [];
    foreach (($product['tags'] ?? []) as $tag) {
        $tid = (int)($tag['tag_id'] ?? 0);
        if ($tid > 0) {
            $currentTagIds[] = $tid;
        }
    }
    $currentTagIds = array_values(array_unique($currentTagIds));

    usort($brands, function ($a, $b) {
        return strcmp(
            mb_strtolower((string)($a['brand_name'] ?? ''), 'UTF-8'),
            mb_strtolower((string)($b['brand_name'] ?? ''), 'UTF-8')
        );
    });

    // arbre catégories pour le select
    $categoryOptions = $this->buildCategorySelectOptions($categories);

    // ids de catégories sélectionnables = feuilles uniquement
    $leafCategoryIds = [];
    foreach ($categoryOptions as $opt) {
        if (!empty($opt['selectable'])) {
            $leafCategoryIds[(int)$opt['id']] = true;
        }
    }

    // catégorie par défaut = "Occasion / reconditionné" enfant de "Téléphones"
    $defaultCategoryId = $this->findDefaultPhoneOccasionCategoryId($categories);

    $currentCategoryId = (int)($product['product_category'] ?? 0);
    $selectedCategoryId = (
        $currentCategoryId > 0
        && isset($leafCategoryIds[$currentCategoryId])
    )
        ? $currentCategoryId
        : ((int)$defaultCategoryId ?: 0);

    $error = null;

    // -------------------------
    // POST
    // -------------------------
    if ($request->isMethod('POST')) {

        if (!$this->isCsrfTokenValid(
            'hiboutik_edit_' . $id,
            (string)$request->request->get('_csrf_token')
        )) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        $www = $request->request->has('product_display_www') ? '1' : '0';

        // catégorie
        $categoryRaw = trim((string)$request->request->get('product_category', '0'));
        $categoryId = ctype_digit($categoryRaw) ? (int)$categoryRaw : 0;

        if ($categoryId <= 0 && $defaultCategoryId) {
            $categoryId = (int)$defaultCategoryId;
        }

        if ($categoryId <= 0) {
            $this->addFlash('error', 'La catégorie est obligatoire.');
            return $this->redirectToRoute('admin_hiboutik_product_edit', ['id' => $id]);
        }

        if (!isset($leafCategoryIds[$categoryId])) {
            $this->addFlash('error', 'Vous devez choisir une sous-catégorie, pas une catégorie parente.');
            return $this->redirectToRoute('admin_hiboutik_product_edit', ['id' => $id]);
        }

        // marque
        $brandRaw = trim((string)$request->request->get('product_brand', '0'));
$brandId = 0;

if ($brandRaw === '__new__') {
    $newBrandName = trim((string)$request->request->get('new_brand_name', ''));

    if ($newBrandName === '') {
        $this->addFlash('error', 'Le nom de la nouvelle marque est obligatoire.');
        return $this->redirectToRoute('admin_hiboutik_product_edit', ['id' => $id]);
    }

    // position max + 1
    $maxPosition = 0;
    foreach ($brands as $b) {
        $pos = (int)($b['brand_position'] ?? 0);
        if ($pos > $maxPosition) {
            $maxPosition = $pos;
        }
    }

    $createRes = $this->hib->createBrand([
        'brand_name' => $newBrandName,
        'brand_enabled' => 1,
        'brand_enabled_www' => 0,
        'brand_position' => $maxPosition + 1,
    ]);

    if (!($createRes['ok'] ?? false)) {
        $this->addFlash('error', 'Impossible de créer la nouvelle marque Hiboutik.');
        return $this->redirectToRoute('admin_hiboutik_product_edit', ['id' => $id]);
    }

    // on essaie de récupérer l'id directement
    $brandId = (int)($createRes['data']['brand_id'] ?? $createRes['brand_id'] ?? 0);

    // fallback : on relit les marques et on retrouve par nom
    if ($brandId <= 0) {
        $brandsReload = $this->hib->listBrands();
        $brandsReloadData = is_array($brandsReload['data'] ?? null) ? $brandsReload['data'] : [];

        $targetNorm = $this->normalizeBrandName($newBrandName);

        foreach ($brandsReloadData as $b) {
            $bid = (int)($b['brand_id'] ?? 0);
            $bname = trim((string)($b['brand_name'] ?? ''));

            if ($bid > 0 && $this->normalizeBrandName($bname) === $targetNorm) {
                $brandId = $bid;
                break;
            }
        }
    }

    if ($brandId <= 0) {
        $this->addFlash('error', 'La marque a peut-être été créée, mais son identifiant est introuvable.');
        return $this->redirectToRoute('admin_hiboutik_product_edit', ['id' => $id]);
    }
} else {
    $brandId = ctype_digit($brandRaw) ? (int)$brandRaw : 0;
}

        // tags cochés
        $selectedTagIds = array_values(array_unique(array_filter(
            array_map('intval', (array)$request->request->all('product_tags')),
            fn ($x) => $x > 0
        )));

        // attributs postés
        $postedMiscValues = json_decode((string)$request->request->get('misc_values_json', '{}'), true);
        if (!is_array($postedMiscValues)) {
            $postedMiscValues = [];
        }

        // IMPORTANT : on repart de l'existant pour ne RIEN perdre
        $existingMiscValues = $this->parseMiscTextMap((string)($product['misc_text'] ?? ''));
        if (!is_array($existingMiscValues)) {
            $existingMiscValues = [];
        }

        // les valeurs postées écrasent les anciennes, mais si une clé n'est pas repostée on la garde
        $effectiveMiscValues = array_replace($existingMiscValues, $postedMiscValues);

        $defs = $this->em->getRepository(AttributeDefinition::class)
            ->findBy(['category' => (string)$categoryId], ['id' => 'ASC']);

        $miscText = $this->buildProductMiscText($defs, $effectiveMiscValues);

        $fields = [
            'product_model'          => trim((string)$request->request->get('product_model', '')),
            'product_barcode'        => trim((string)$request->request->get('product_barcode', '')),
            'product_price'          => (string)$request->request->get('product_price', ''),
            'product_discount_price' => (string)$request->request->get('product_discount_price', ''),
            'product_category'       => (string)$categoryId,
            'product_brand'          => (string)$brandId,
            'product_display_www'    => $www,
            'misc_text'              => $miscText,
        ];

        // format prix
        foreach (['product_price', 'product_discount_price'] as $k) {
            $v = str_replace(',', '.', trim((string)$fields[$k]));

            if ($v === '') {
                $v = '0.00';
            }

            if (is_numeric($v)) {
                $v = number_format((float)$v, 2, '.', '');
            }

            $fields[$k] = $v;
        }

        // update Hiboutik
        $res = $this->hib->updateProductAttributes($id, $fields);

        if (!($res['ok'] ?? false)) {
            $error = "Update Hiboutik KO";
            $this->addFlash('error', $error);

            return $this->redirectToRoute('admin_hiboutik_product_edit', [
                'id' => $id
            ]);
        }

        // sync tags
        $tagErrors = [];

        $toAdd = array_diff($selectedTagIds, $currentTagIds);
        $toRemove = array_diff($currentTagIds, $selectedTagIds);

        foreach ($toAdd as $tagId) {
            $r = $this->hib->addTagToProduct($id, (int)$tagId);
            if (!($r['ok'] ?? false)) {
                $tagErrors[] = 'Ajout tag #' . $tagId;
            }
        }

        foreach ($toRemove as $tagId) {
            $r = $this->hib->deleteTagForProduct($id, (int)$tagId);
            if (!($r['ok'] ?? false)) {
                $tagErrors[] = 'Suppression tag #' . $tagId;
            }
        }

        $this->refreshProduct($id);

        $this->addFlash('success', "Produit #$id mis à jour.");

        if ($tagErrors) {
            $this->addFlash('warning', 'Produit enregistré, mais certains tags n’ont pas pu être mis à jour : ' . implode(' | ', $tagErrors));
        }

        return $this->redirectToRoute('admin_hiboutik_product_edit', [
            'id' => $id
        ]);
    }

    // -------------------------
    // GET
    // -------------------------
    $miscValues = $this->parseMiscTextMap((string)($product['misc_text'] ?? ''));

    // attributs préchargés pour éviter le fetch lent au premier affichage
    $initialAttributeDefs = [];

    if ($selectedCategoryId > 0) {
        $initialAttributeDefs = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, code, label, type, category, options
             FROM attributes_definitions
             WHERE category = :cat
             ORDER BY id ASC',
            ['cat' => (string)$selectedCategoryId]
        );

        foreach ($initialAttributeDefs as &$row) {
            $row['options'] = !empty($row['options'])
                ? (json_decode($row['options'], true) ?: [])
                : [];
        }
        unset($row);
    }

    return $this->render('@SyliusAdmin/Hiboutik/Products/edit.html.twig', [
        'id'                   => $id,
        'product'              => $product,
        'product_barcode'      => $product['product_barcode'] ?? '',
        'categories'           => $categories,
        'categoryOptions'      => $categoryOptions,
        'selectedCategoryId'   => $selectedCategoryId,
        'defaultCategoryId'    => $defaultCategoryId,
        'brands'               => $brands,
        'error'                => $error,
        'miscValues'           => $miscValues,
        'initialAttributeDefs' => $initialAttributeDefs,
        'tagGroups'            => $tagGroups,
        'currentTagIds'        => $currentTagIds,
    ]);
}

#[Route('/{id}/misc-save', name: 'misc_save', requirements: ['id' => '\d+'], methods: ['POST'])]
public function miscSave(int $id, Request $request): JsonResponse
{
    if (!$this->isCsrfTokenValid(
        'hiboutik_misc_' . $id,
        (string)$request->request->get('_csrf_token')
    )) {
        return $this->json(['ok' => false, 'error' => 'csrf'], 403);
    }

    $product = $this->hib->getProduct($id);

    if (!$product) {
        return $this->json(['ok' => false, 'error' => 'product_not_found'], 404);
    }

    $categoryId = trim((string)$request->request->get(
        'category_id',
        (string)($product['product_category'] ?? '')
    ));

    $values = json_decode((string)$request->request->get('values_json', '{}'), true);
    if (!is_array($values)) {
        $values = [];
    }

    $defs = $this->em->getRepository(AttributeDefinition::class)
        ->findBy(['category' => $categoryId], ['id' => 'ASC']);

    $miscText = $this->buildProductMiscText($defs, $values);

    $res = $this->hib->putProductAttributeSingle($id, 'misc_text', $miscText);

    if (!($res['ok'] ?? false)) {
        return $this->json([
            'ok' => false,
            'error' => 'hiboutik_update_failed',
            'status' => $res['status'] ?? 0,
            'raw' => $res['raw'] ?? null,
        ], 502);
    }

    $this->refreshProduct($id);

    return $this->json([
        'ok' => true,
        'misc_text' => $miscText,
    ]);
}


#[Route('/batch', name: 'batch', methods: ['POST'])]
public function batch(Request $request): Response
{
    $ids = $request->request->all('ids');
    $val = $request->request->get('val');
    $action = $request->request->get('action');
    $tagId = (int)$request->request->get('tag_id');
    $discountPercent = (int)$request->request->get('discount_percent');

    if (!$ids) {
        $this->addFlash('error', 'Aucun produit sélectionné');
        return $this->redirectToRoute('admin_hiboutik_product_index');
    }

    foreach ($ids as $id) {

        $id = (int)$id;

        // =========================
        // ✅ WWW
        // =========================
        if ($val !== null) {
            $this->hib->setProductWWW($id, (int)$val);
        }

        // =========================
        // ✅ TAG ADD
        // =========================
        if ($action === 'add_tag' && $tagId > 0) {
            $this->hib->addTagToProduct($id, $tagId);
        }

        // =========================
        // ✅ TAG REMOVE
        // =========================
        if ($action === 'remove_tag' && $tagId > 0) {
            $this->hib->deleteTagForProduct($id, $tagId);
        }

        // =========================
        // 💸 APPLY DISCOUNT
        // =========================
        if ($action === 'apply_discount' && $discountPercent > 0) {

            $product = $this->hib->getProduct($id);

            $price = (float)($product['product_price'] ?? 0);

            if ($price > 0) {

                $discount = $price * (1 - ($discountPercent / 100));

                // 👉 arrondi propre
                $discount = floor($discount);

                $discount = number_format($discount, 2, '.', '');

                $this->hib->updateProductAttributes($id, [
                    'product_discount_price' => $discount
                ]);
            }
        }

        // =========================
        // ♻️ RESET DISCOUNT
        // =========================
        if ($action === 'reset_discount') {

            $this->hib->updateProductAttributes($id, [
                'product_discount_price' => '0.00'
            ]);
        }

        // =========================
        // 🔄 REFRESH CACHE
        // =========================
        $this->refreshProduct($id);
    }

    $this->addFlash('success', 'Action OK');

    return $this->redirectToRoute('admin_hiboutik_product_index');
}

    #[Route('/{id}/images', name: 'images', methods: ['GET','POST'])]
public function images(int $id, Request $request): Response
{
    $product = $this->hib->getProduct($id);

    if (!$product) {
        throw $this->createNotFoundException();
    }

    // =========================
    // 🔥 POST GLOBAL
    // =========================
    if ($request->isMethod('POST')) {


        // =========================
// 🔥 DELETE IMAGE
// =========================
if ($request->request->get('delete_image')) {

    $imageName = $request->request->get('delete_image');

    $res = $this->hib->deleteProductImageByName($imageName);

    
    $this->refreshProduct($id);


    return $this->json($res);
}


        // =========================
        // 🔥 CAS 1 : UNE IMAGE (AJAX)
        // =========================
        if ($request->files->get('image')) {

            $file = $request->files->get('image');
            $imageId = (int)$request->request->get('image_id');

            if (!$file || !$imageId) {
                return $this->json(['error' => 'missing data'], 400);
            }

            $res = $this->hib->uploadProductImage(
                $id,
                $file->getPathname(),
                $imageId,
                $file->getClientOriginalName()
            );

           
            $this->refreshProduct($id);


            return $this->json($res);
        }

        // =========================
        // 🔥 CAS 2 : MULTIPLE
        // =========================
        $files = $request->files->get('images');

        if (!$files) {
            $this->addFlash('error', 'Aucun fichier reçu');
            return $this->redirectToRoute('admin_hiboutik_product_images', ['id'=>$id]);
        }

        if (!is_array($files)) {
            $files = [$files];
        }

        $i = 1;
        $errors = [];
        $success = 0;

        foreach ($files as $file) {

            if (!$file) continue;

            if (!$file->isValid()) {
                $errors[] = $file->getClientOriginalName().' invalide';
                continue;
            }

            $res = $this->hib->uploadProductImage(
                $id,
                $file->getPathname(),
                $i,
                $file->getClientOriginalName()
            );

           
    $this->refreshProduct($id);


            if (!($res['ok'] ?? false)) {
                $errors[] = $file->getClientOriginalName().' ('.$res['status'].') '.$res['raw'];
            } else {
                $success++;
            }

            $i++;
            if ($i > 4) break;
        }

        if ($errors) {
            $this->addFlash('error', implode(' | ', $errors));
        } else {
            $this->addFlash('success', "$success image(s) uploadée(s)");
        }

        return $this->redirectToRoute('admin_hiboutik_product_images', ['id'=>$id]);
    }

    // =========================
    // 🔥 GET
    // =========================
    return $this->render('@SyliusAdmin/Hiboutik/Products/images.html.twig', [
        'product' => $product,
        'images' => $product['images'] ?? [],
    ]);
}

private function refreshProduct(int $id): void
{
    $url = "https://api.multimedia-services.fr/webhook/hiboutik?secret=untrucbienlong_auhasard_123";

    $body = http_build_query([
        'product_id' => $id,
        'shop_id' => 1
    ]);

    error_log("REFRESH PRODUCT ID=".$id);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 2
        ]
    ]);

    try {
        $response = @file_get_contents($url, false, $context);
        error_log("REFRESH OK=".$response);
    } catch (\Throwable $e) {
        error_log("REFRESH ERROR=".$e->getMessage());
    }
}

private function parseMiscTextMap(string $miscText): array
{
    $miscText = trim($miscText);
    if ($miscText === '') {
        return [];
    }

    $decoded = json_decode($miscText, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];

    // format moderne :
    // [
    //   {"code":"batterie","label":"Batterie","value":"87%"}
    // ]
    if (isset($decoded[0]) && is_array($decoded[0])) {
        foreach ($decoded as $row) {
            $code = trim((string)($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $out[$code] = is_scalar($row['value'] ?? null)
                ? trim((string)($row['value'] ?? ''))
                : '';
        }

        return $out;
    }

    // ancien format objet :
    // {"batterie":"87%","stockage":"128Go"}
    foreach ($decoded as $code => $value) {
        $out[(string)$code] = is_scalar($value)
            ? trim((string)$value)
            : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    return $out;
}

private function buildProductMiscText(array $defs, array $values): string
{
    $out = [];

    foreach ($defs as $def) {
        if (!$def instanceof AttributeDefinition) {
            continue;
        }

        $code = (string)$def->getCode();

        $out[] = [
            'code'  => $code,
            'label' => (string)$def->getLabel(),
            'value' => isset($values[$code]) && is_scalar($values[$code])
                ? trim((string)$values[$code])
                : '',
        ];
    }

    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return $json !== false ? $json : '[]';
}


#[Route('/import-external-brands', name: 'import_external_brands', methods: ['POST'])]
public function importExternalBrands(Request $request): RedirectResponse
{
    if (!$this->isCsrfTokenValid('hib_import_external_brands', (string)$request->request->get('_csrf_token'))) {
        throw $this->createAccessDeniedException('CSRF invalid');
    }

    $dry = $request->request->getBoolean('dry', false);

    // 1) Marques Hiboutik existantes
    $hibBrandsRes = $this->hib->listBrands();
    $hibBrands = is_array($hibBrandsRes['data'] ?? null) ? $hibBrandsRes['data'] : [];

    $existingByNorm = [];
    $maxPosition = 0;

    foreach ($hibBrands as $b) {
        $id = (int)($b['brand_id'] ?? 0);
        $name = trim((string)($b['brand_name'] ?? ''));
        $pos = (int)($b['brand_position'] ?? 0);

        if ($pos > $maxPosition) {
            $maxPosition = $pos;
        }

        if ($id > 0 && $name !== '') {
            $existingByNorm[$this->normalizeBrandName($name)] = $b;
        }
    }

    // 2) Source externe
    $res = $this->httpClient->request('GET', 'https://phone-specs-api.vercel.app//brands', [
        'timeout' => 20,
    ]);

    $payload = $res->toArray(false);
    $externalBrands = is_array($payload['data'] ?? null) ? $payload['data'] : [];

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];

    foreach ($externalBrands as $ext) {
        $extName = trim((string)($ext['brand_name'] ?? ''));
        $extSlug = trim((string)($ext['brand_slug'] ?? ''));
        $extDetail = trim((string)($ext['detail'] ?? ''));

        if ($extName === '' || $extSlug === '') {
            $skipped++;
            continue;
        }

        $norm = $this->normalizeBrandName($extName);

        // Marque déjà présente dans Hiboutik
        if (isset($existingByNorm[$norm])) {
            $hibBrand = $existingByNorm[$norm];
            $hibBrandId = (int)($hibBrand['brand_id'] ?? 0);

            $fields = [];

            // on ne touche PAS au nom
            if (trim((string)($hibBrand['brand_ref_ext'] ?? '')) === '') {
                $fields['brand_ref_ext'] = $extSlug;
            }

            if (trim((string)($hibBrand['brand_url'] ?? '')) === '' && $extDetail !== '') {
                $fields['brand_url'] = $extDetail;
            }

            if (!$fields) {
                $skipped++;
                continue;
            }

            if ($dry) {
                $updated++;
                continue;
            }

            $r = $this->hib->updateBrandAttributes($hibBrandId, $fields);

            if ($r['ok'] ?? false) {
                $updated++;
            } else {
                $errors[] = 'MAJ marque #' . $hibBrandId . ' (' . $extName . ')';
            }

            continue;
        }

        // Marque absente => création
        $maxPosition++;

        $fields = [
            'brand_name' => $extName,
            'brand_enabled' => 1,
            'brand_enabled_www' => 0,
            'brand_position' => $maxPosition,
            'brand_ref_ext' => $extSlug,
            'brand_url' => $extDetail,
        ];

        if ($dry) {
            $created++;
            continue;
        }

        $r = $this->hib->createBrand($fields);

        if ($r['ok'] ?? false) {
            $created++;
        } else {
            $errors[] = 'CREATE marque (' . $extName . ')';
        }
    }

    if ($errors) {
        $this->addFlash('warning', sprintf(
            'Import terminé : %d créées, %d mises à jour, %d ignorées, %d erreurs.',
            $created,
            $updated,
            $skipped,
            count($errors)
        ));
        $this->addFlash('warning', implode(' | ', array_slice($errors, 0, 10)));
    } else {
        $this->addFlash('success', sprintf(
            'Import terminé : %d créées, %d mises à jour, %d ignorées.',
            $created,
            $updated,
            $skipped
        ));
    }

    return $this->redirectToRoute('admin_hiboutik_product_index');
}


private function normalizeBrandName(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = str_replace(['&', '+'], ' and ', $name);
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $name = preg_replace('/[^a-z0-9]+/', '', $name);

    return (string)$name;
}

#[Route('/{id}/label-test', name: 'label_test', requirements: ['id' => '\d+'], methods: ['GET'])]
public function labelTest(int $id): Response
{
    $product = $this->hib->getProduct($id);

    if (!$product) {
        throw $this->createNotFoundException("Produit introuvable");
    }

    $miscValues = $this->parseMiscTextMap((string)($product['misc_text'] ?? ''));

    $brandName = trim((string)($product['brand_name'] ?? ''));
    if ($brandName === '' && !empty($product['product_brand'])) {
        $brandsRes = $this->hib->listBrands();
        foreach (($brandsRes['data'] ?? []) as $b) {
            if ((int)($b['brand_id'] ?? 0) === (int)$product['product_brand']) {
                $brandName = (string)($b['brand_name'] ?? '');
                break;
            }
        }
    }

    $price = (float)($product['product_discount_price'] ?? 0);
    if ($price <= 0) {
        $price = (float)($product['product_price'] ?? 0);
    }

    $priceLabel = number_format($price, 2, ',', ' ');

    $productView = [
        'product_id'      => $product['product_id'] ?? null,
        'product_model'   => $product['product_model'] ?? '',
        'product_barcode' => $product['product_barcode'] ?? '',
        'brand_name'      => $brandName ?: 'XIAOMI',
        'storage'         => $miscValues['stockage'] ?? $miscValues['capacity'] ?? '',
        'state_label'     => $miscValues['etat'] ?? 'BON ÉTAT',
        'price_label'     => $priceLabel,
        'spec_1'          => !empty($miscValues['photo']) ? 'Appareil photo princ. : ' . $miscValues['photo'] : null,
        'spec_2'          => !empty($miscValues['os']) ? 'Syst. exploit. : ' . $miscValues['os'] : null,
        'spec_3'          => !empty($miscValues['ecran']) ? 'Taille d\'écran : ' . $miscValues['ecran'] : null,
        'spec_4'          => !empty($miscValues['das']) ? 'DAS tête : ' . $miscValues['das'] : null,
        'spec_5'          => !empty($miscValues['batterie']) ? 'Batterie : ' . $miscValues['batterie'] : null,
    ];

    $productUrl = 'https://votre-site.fr/produit/' . ($product['product_id'] ?? $id);

    $qrCodeDataUri = null; // à brancher ensuite

    $file = $this->labelPdfGenerator->generateCenteredA4([
        'product' => $productView,
        'qr_code_data_uri' => $qrCodeDataUri,
    ], 'label-product-' . $id . '.pdf');

    return $this->redirect($file['url']);
}

#[Route('/{id}/labels-a5-x3', name: 'labels_a5_x3', requirements: ['id' => '\d+'], methods: ['GET'])]
public function labelsA5x3(int $id): Response
{
    $product = $this->hib->getProduct($id);


    
    if (!$product) {
        throw $this->createNotFoundException("Produit introuvable");
    }

    $miscValues = $this->parseMiscTextMap((string)($product['misc_text'] ?? ''));

    $brandName = trim((string)($product['brand_name'] ?? ''));
    if ($brandName === '' && !empty($product['product_brand'])) {
        $brandsRes = $this->hib->listBrands();
        foreach (($brandsRes['data'] ?? []) as $b) {
            if ((int)($b['brand_id'] ?? 0) === (int)$product['product_brand']) {
                $brandName = (string)($b['brand_name'] ?? '');
                break;
            }
        }
    }

    $price = (float)($product['product_discount_price'] ?? 0);
    if ($price <= 0) {
        $price = (float)($product['product_price'] ?? 0);
    }

    $priceLabel = number_format($price, 2, ',', ' ');

    $productView = [
        'product_id'      => $product['product_id'] ?? null,
        'product_model'   => (string)($product['product_model'] ?? ''),
        'product_barcode' => (string)($product['product_barcode'] ?? ''),
        'brand_name'      => $brandName,
        'storage'         => (string)($miscValues['stockage'] ?? $miscValues['capacity'] ?? ''),
        'state_label'     => (string)($miscValues['etat'] ?? 'BON ÉTAT'),
        'price_label'     => $priceLabel,

        'spec_1' => !empty($miscValues['photo']) ? 'Appareil photo princ. : ' . $miscValues['photo'] : null,
        'spec_2' => !empty($miscValues['os']) ? 'Syst. exploit. : ' . $miscValues['os'] : null,
        'spec_3' => !empty($miscValues['ecran']) ? 'Taille d’écran : ' . $miscValues['ecran'] : null,
        'spec_4' => !empty($miscValues['das']) ? 'DAS tête : ' . $miscValues['das'] : null,
        'spec_5' => !empty($miscValues['batterie']) ? 'Batterie : ' . $miscValues['batterie'] : null,

        'footer_label' => 'GARANTIE 2 ANS',
        'spec_footer'  => null,
    ];

    $productUrl = 'https://votre-site.fr/produit/' . ($product['product_id'] ?? $id);

    $qrCodeDataUri = null;
    // plus tard : $qrCodeDataUri = $this->qrCodeToDataUri($productUrl);

    $file = $this->labelPdfGenerator->generateA5ThreeSameLabels([
        'product' => $productView,
        'qr_code_data_uri' => $qrCodeDataUri,
    ], 'labels-a5-x3-product-' . $id . '.pdf');

    return $this->redirect($file['url']);
}

#[Route('/{id}/labels-a5-x4', name: 'labels_a5_x4', requirements: ['id' => '\d+'], methods: ['GET'])]
public function labelsA5x4(int $id): Response
{
    $product = $this->hib->getProduct($id);

    if (!$product) {
        throw $this->createNotFoundException("Produit introuvable");
    }

    $miscRows = $this->parseMiscTextRows((string)($product['misc_text'] ?? ''));

    $brandName = trim((string)($product['product_brand_name'] ?? ''));
    if ($brandName === '' && !empty($product['product_brand'])) {
        $brandsRes = $this->hib->listBrands();
        foreach (($brandsRes['data'] ?? []) as $b) {
            if ((int)($b['brand_id'] ?? 0) === (int)$product['product_brand']) {
                $brandName = (string)($b['brand_name'] ?? '');
                break;
            }
        }
    }

    $stateMeta = $this->resolveStateMetaFromTags($product['tags'] ?? []);

    $price = (float)($product['product_discount_price'] ?? 0);
    if ($price <= 0) {
        $price = (float)($product['product_price'] ?? 0);
    }

    $priceLabel = number_format($price, 2, ',', '');

    $productUrl = 'https://shop.multimedia-services.fr/produits/' . ($product['product_id'] ?? $id);
    $qrCodeDataUri = $this->qrCodeToDataUri($productUrl);

    $storage = '';
    foreach ($miscRows as $row) {
        if (mb_strtolower(trim((string)$row['label'])) === 'stockage') {
            $storage = trim((string)$row['value']);
            break;
        }
    }

    $footerLabel = '';
foreach ($miscRows as $row) {
    if (mb_strtolower(trim((string) ($row['label'] ?? ''))) === 'garantie') {
        $footerLabel = "Garantie " . trim((string) ($row['value'] ?? ''));
        break;
    }
}

    $productView = [
        'product_id'      => $product['product_id'] ?? null,
        'product_model'   => (string)($product['product_model'] ?? ''),
        'product_barcode' => (string)($product['product_barcode'] ?? ''),
        'brand_name'      => $brandName,
        'storage'         => $storage,
        'state_label'     => $stateMeta['label'],
        'state_class'     => $stateMeta['class'],
        'price_label'     => $priceLabel,
        'misc_rows'       => $miscRows,
        'footer_label'    => $footerLabel
    ];

    $filename = sprintf('labels-a5-x4-product-%d-%s.pdf', $id, date('Ymd-His'));

    $file = $this->labelTcpdfGenerator->generateA5FourLabels60x105([
        'product' => $productView,
        'qr_code_data_uri' => $qrCodeDataUri,
    ], $filename);

    return $this->redirect($file['url']);
}


private function getLabelBuilderSlots(\Symfony\Component\HttpFoundation\RequestStack $requestStack): array
{
    $session = $requestStack->getSession();
    $slots = $session->get('hib_label_builder_slots', [null, null, null, null]);

    if (!is_array($slots) || count($slots) !== 4) {
        $slots = [null, null, null, null];
    }

    return array_values($slots);
}

private function saveLabelBuilderSlots(\Symfony\Component\HttpFoundation\RequestStack $requestStack, array $slots): void
{
    $slots = array_values(array_pad(array_slice($slots, 0, 4), 4, null));
    $requestStack->getSession()->set('hib_label_builder_slots', $slots);
}

private function addProductToLabelBuilder(\Symfony\Component\HttpFoundation\RequestStack $requestStack, int $productId): bool
{
    $slots = $this->getLabelBuilderSlots($requestStack);

    foreach ($slots as $i => $slot) {
        if ($slot === null) {
            $slots[$i] = $productId;
            $this->saveLabelBuilderSlots($requestStack, $slots);
            return true;
        }
    }

    return false;
}

private function removeSlotFromLabelBuilder(\Symfony\Component\HttpFoundation\RequestStack $requestStack, int $slotIndex): void
{
    $slots = $this->getLabelBuilderSlots($requestStack);

    if (isset($slots[$slotIndex])) {
        $slots[$slotIndex] = null;
    }

    $this->saveLabelBuilderSlots($requestStack, $slots);
}

private function clearLabelBuilder(\Symfony\Component\HttpFoundation\RequestStack $requestStack): void
{
    $this->saveLabelBuilderSlots($requestStack, [null, null, null, null]);
}

#[Route('/labels-builder/add/{id}', name: 'labels_builder_add', requirements: ['id' => '\d+'], methods: ['POST'])]
public function addToLabelsBuilder(int $id): Response
{
    $product = $this->hib->getProduct($id);
    if (!$product) {
        throw $this->createNotFoundException('Produit introuvable');
    }

    $ok = $this->addProductToLabelBuilder($this->requestStack, $id);

    if ($ok) {
        $this->addFlash('success', 'Produit ajouté à la planche étiquettes.');
    } else {
        $this->addFlash('error', 'La planche contient déjà 4 produits.');
    }

    return $this->redirectToRoute('admin_hiboutik_product_labels_builder');
}


#[Route('/labels-builder/fill-from-index', name: 'labels_builder_fill_from_index', methods: ['POST'])]
public function fillLabelsBuilderFromIndex(Request $request): Response
{
    $ids = $request->request->all('ids');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (!$ids) {
        $this->addFlash('error', 'Aucun produit sélectionné.');
        return $this->redirectToRoute('admin_hiboutik_products_index');
    }

    if (count($ids) > 4) {
        $this->addFlash('error', 'Vous ne pouvez sélectionner que 4 produits maximum.');
        return $this->redirectToRoute('admin_hiboutik_products_index');
    }

    $slots = [null, null, null, null];
    foreach ($ids as $i => $id) {
        $slots[$i] = $id;
    }

    $this->saveLabelBuilderSlots($this->requestStack, $slots);

    return $this->redirectToRoute('admin_hiboutik_product_labels_builder');
}


#[Route('/labels-builder/add-batch', name: 'labels_builder_add_batch', methods: ['POST'])]
public function addBatchToLabelsBuilder(Request $request): Response
{
    $ids = $request->request->all('ids');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (!$ids) {
        $this->addFlash('error', 'Aucun produit sélectionné.');
        return $this->redirectToRoute('admin_hiboutik_product_index');
    }

    $slots = $this->getLabelBuilderSlots($this->requestStack);

    foreach ($ids as $id) {
        foreach ($slots as $i => $slot) {
            if ($slot === null) {
                $slots[$i] = $id;
                continue 2;
            }
        }

        $this->addFlash('error', 'La planche est pleine (4 slots max).');
        $this->saveLabelBuilderSlots($this->requestStack, $slots);

        return $this->redirectToRoute('admin_hiboutik_product_labels_builder');
    }

    $this->saveLabelBuilderSlots($this->requestStack, $slots);
    $this->addFlash('success', 'Produit(s) ajouté(s) à la planche.');

    return $this->redirectToRoute('admin_hiboutik_product_labels_builder');
}

#[Route('/labels-builder', name: 'labels_builder', methods: ['GET'])]
public function labelsBuilder(Request $request): Response
{
    $slots = $this->getLabelBuilderSlots($this->requestStack);

    $slotViews = [];

    foreach ($slots as $i => $productId) {
        if ($productId === null) {
            $slotViews[] = [
                'slot' => $i,
                'product' => null,
            ];
            continue;
        }

        $product = $this->hib->getProduct((int) $productId);

        if (!$product) {
            $slotViews[] = [
                'slot' => $i,
                'product' => null,
            ];
            continue;
        }

        $miscRows = $this->parseMiscTextRows((string) ($product['misc_text'] ?? ''));

        $brandName = trim((string) ($product['product_brand_name'] ?? ''));
        if ($brandName === '' && !empty($product['product_brand'])) {
            $brandsRes = $this->hib->listBrands();
            foreach (($brandsRes['data'] ?? []) as $b) {
                if ((int) ($b['brand_id'] ?? 0) === (int) $product['product_brand']) {
                    $brandName = (string) ($b['brand_name'] ?? '');
                    break;
                }
            }
        }

        $stateMeta = $this->resolveStateMetaFromTags($product['tags'] ?? []);

        $price = (float) ($product['product_discount_price'] ?? 0);
        if ($price <= 0) {
            $price = (float) ($product['product_price'] ?? 0);
        }

        $priceLabel = number_format($price, 2, ',', '');

        $storage = '';
        foreach ($miscRows as $row) {
            if (mb_strtolower(trim((string) $row['label'])) === 'stockage') {
                $storage = trim((string) $row['value']);
                break;
            }
        }

        $productUrl = 'https://shop.multimedia-services.fr/produits/' . ($product['product_id'] ?? $productId);
$qrCodeDataUri = $this->qrCodeToDataUri($productUrl);

$barcode = (string)($product['product_barcode'] ?? '');
$barcodePngDataUri = $this->barcodePngDataUri($barcode);

$footerLabel = '';
foreach ($miscRows as $row) {
    if (mb_strtolower(trim((string)($row['label'] ?? ''))) === 'garantie') {
        $footerLabel = "GARANTIE " . trim((string)($row['value'] ?? ''));
        break;
    }
}

        $slotViews[] = [
    'slot' => $i,
    'product' => [
        'product_id'            => $product['product_id'] ?? null,
        'product_model'         => (string)($product['product_model'] ?? ''),
        'product_barcode'       => (string)($product['product_barcode'] ?? ''),
        'brand_name'            => $brandName,
        'storage'               => $storage,
        'state_label'           => $stateMeta['label'],
        'state_class'           => $stateMeta['class'],
        'price_label'           => $priceLabel,
        'misc_rows'             => $miscRows,
        'footer_label'          => $footerLabel,
        'qr_code_data_uri'      => $qrCodeDataUri,
        'barcode_png_data_uri'  => $barcodePngDataUri,
    ],
];
    }

    $q = trim((string) $request->query->get('q', ''));
    $results = [];

    if ($q !== '') {
        $all = $this->hib->getProductsAll();

        foreach ($all as $p) {
            $pid = (int) ($p['product_id'] ?? 0);
            $model = (string) ($p['product_model'] ?? '');
            $barcode = (string) ($p['product_barcode'] ?? '');
            $brand = (string) ($p['product_brand_name'] ?? '');

            $haystack = mb_strtolower(trim($pid . ' ' . $model . ' ' . $barcode . ' ' . $brand));
            $needle = mb_strtolower($q);

            if (!str_contains($haystack, $needle)) {
                continue;
            }

            $results[] = [
                'product_id'         => $pid,
                'product_model'      => $model,
                'product_barcode'    => $barcode,
                'product_price'      => $p['product_price'] ?? '',
                'product_brand_name' => $brand,
            ];

            if (count($results) >= 20) {
                break;
            }
        }
    }

    if ($request->query->get('ajax') === '1') {
        return $this->render('@SyliusAdmin/Hiboutik/Products/_labels_builder_results.html.twig', [
            'q' => $q,
            'results' => $results,
        ]);
    }

    return $this->render('@SyliusAdmin/Hiboutik/Products/labels_builder.html.twig', [
        'slots'   => $slotViews,
        'q'       => $q,
        'results' => $results,
    ]);
}

#[Route('/labels-builder/remove/{slot}', name: 'labels_builder_remove', requirements: ['slot' => '\d+'], methods: ['POST'])]
public function removeFromLabelsBuilder(int $slot): Response
{
    if ($slot < 0 || $slot > 3) {
        throw $this->createNotFoundException('Slot invalide');
    }

    $this->removeSlotFromLabelBuilder($this->requestStack, $slot);
    $this->addFlash('success', 'Slot vidé.');

    return $this->redirectToRoute('admin_hiboutik_product_labels_builder');
}

#[Route('/labels-builder/clear', name: 'labels_builder_clear', methods: ['POST'])]
public function clearLabelsBuilder(): Response
{
    $this->clearLabelBuilder($this->requestStack);
    $this->addFlash('success', 'Planche vidée.');

    return $this->redirectToRoute('admin_hiboutik_product_labels_builder');
}



#[Route('/labels-builder/pdf', name: 'labels_builder_pdf', methods: ['POST'])]
public function labelsBuilderPdf(): Response
{
    $slots = $this->getLabelBuilderSlots($this->requestStack);

    $pdfSlots = [];

    foreach ($slots as $productId) {
        if ($productId === null) {
            $pdfSlots[] = null;
            continue;
        }

        $product = $this->hib->getProduct((int) $productId);

        if (!$product) {
            $pdfSlots[] = null;
            continue;
        }

        $miscRows = $this->parseMiscTextRows((string)($product['misc_text'] ?? ''));

        $brandName = trim((string)($product['product_brand_name'] ?? ''));
        if ($brandName === '' && !empty($product['product_brand'])) {
            $brandsRes = $this->hib->listBrands();
            foreach (($brandsRes['data'] ?? []) as $b) {
                if ((int)($b['brand_id'] ?? 0) === (int)$product['product_brand']) {
                    $brandName = (string)($b['brand_name'] ?? '');
                    break;
                }
            }
        }

        $stateMeta = $this->resolveStateMetaFromTags($product['tags'] ?? []);

        $price = (float)($product['product_discount_price'] ?? 0);
        if ($price <= 0) {
            $price = (float)($product['product_price'] ?? 0);
        }

        $priceLabel = number_format($price, 2, ',', '');

        $productUrl = 'https://shop.multimedia-services.fr/produits/' . ($product['product_id'] ?? $productId);
        $qrCodeDataUri = $this->qrCodeToDataUri($productUrl);

        $storage = '';
        foreach ($miscRows as $row) {
            if (mb_strtolower(trim((string)$row['label'])) === 'stockage') {
                $storage = trim((string)$row['value']);
                break;
            }
        }

        $footerLabel = '';
foreach ($miscRows as $row) {
    if (mb_strtolower(trim((string)($row['label'] ?? ''))) === 'garantie') {
        $footerLabel = "GARANTIE " . trim((string)($row['value'] ?? ''));

        break;
    }
}

        $pdfSlots[] = [
            'product' => [
                'product_id'      => $product['product_id'] ?? null,
                'product_model'   => (string)($product['product_model'] ?? ''),
                'product_barcode' => (string)($product['product_barcode'] ?? ''),
                'brand_name'      => $brandName,
                'storage'         => $storage,
                'state_label'     => $stateMeta['label'],
                'state_class'     => $stateMeta['class'],
                'price_label'     => $priceLabel,
                'misc_rows'       => $miscRows,
                'footer_label'    => $footerLabel
            ],
            'qr_code_data_uri' => $qrCodeDataUri,
        ];
    }

    if (count(array_filter($pdfSlots)) === 0) {
        $this->addFlash('error', 'La planche est vide.');
        return $this->redirectToRoute('admin_hiboutik_product_labels_builder');
    }

    $filename = sprintf('labels-a5-builder-%s.pdf', date('Ymd-His'));

    $file = $this->labelTcpdfGenerator->generateA5Slots60x105($pdfSlots, $filename);

    return $this->redirect($file['url']);
}

// #[Route('/{id}/labels-a5-x4', name: 'labels_a5_x4', requirements: ['id' => '\d+'], methods: ['GET'])]
// public function labelsA5x4(int $id): Response
// {
//     $product = $this->hib->getProduct($id);

//     if (!$product) {
//         throw $this->createNotFoundException("Produit introuvable");
//     }

//     $miscRows = $this->parseMiscTextRows((string)($product['misc_text'] ?? ''));

//     $brandName = trim((string)($product['product_brand_name'] ?? ''));
//     if ($brandName === '' && !empty($product['product_brand'])) {
//         $brandsRes = $this->hib->listBrands();
//         foreach (($brandsRes['data'] ?? []) as $b) {
//             if ((int)($b['brand_id'] ?? 0) === (int)$product['product_brand']) {
//                 $brandName = (string)($b['brand_name'] ?? '');
//                 break;
//             }
//         }
//     }

//     $stateMeta = $this->resolveStateMetaFromTags($product['tags'] ?? []);

//     $price = (float)($product['product_discount_price'] ?? 0);
//     if ($price <= 0) {
//         $price = (float)($product['product_price'] ?? 0);
//     }

//     $priceLabel = number_format($price, 2, ',', '');

//     $productUrl = 'https://shop.multimedia-services.fr/produits/' . ($product['product_id'] ?? $id);
//     $qrCodeDataUri = $this->qrCodeToDataUri($productUrl);

//     // on peut essayer de repérer le stockage pour le haut de l'étiquette
//     $storage = '';
//     foreach ($miscRows as $row) {
//         if (mb_strtolower($row['label']) === 'stockage') {
//             $storage = $row['value'];
//             break;
//         }
//     }

//     $productView = [
//         'product_id'      => $product['product_id'] ?? null,
//         'product_model'   => (string)($product['product_model'] ?? ''),
//         'product_barcode' => (string)($product['product_barcode'] ?? ''),
//         'brand_name'      => $brandName,
//         'storage'         => $storage,

//         'state_label'     => $stateMeta['label'],
//         'state_class'     => $stateMeta['class'],

//         'price_label'     => $priceLabel,

//         // TOUTES les lignes lues depuis misc_text
//         'misc_rows'       => $miscRows,

//         'footer_label'    => 'GARANTIE 2 ANS',
//     ];

//     $filename = sprintf(
//     'labels-a5-x4-product-%d-%s.pdf',
//     $id,
//     date('Ymd-His')
// );

// $file = $this->labelPdfGenerator->generateA5FourSameLabels([
//     'product' => $productView,
//     'qr_code_data_uri' => $qrCodeDataUri,
// ], $filename);

// return $this->redirect($file['url']);
// }


private function parseMiscTextRows(string $miscText): array
{
    $miscText = trim($miscText);
    if ($miscText === '') {
        return [];
    }

    $decoded = json_decode($miscText, true);
    if (!is_array($decoded)) {
        return [];
    }

    $rows = [];

    // format moderne : tableau d'objets
    if (isset($decoded[0]) && is_array($decoded[0])) {
        foreach ($decoded as $row) {
            $label = trim((string)($row['label'] ?? ''));
            $value = trim((string)($row['value'] ?? ''));

            if ($label === '' || $value === '') {
                continue;
            }

            $rows[] = [
                'code'  => trim((string)($row['code'] ?? '')),
                'label' => $label,
                'value' => $value,
            ];
        }

        return $rows;
    }

    // ancien format objet
    foreach ($decoded as $code => $value) {
        $v = is_scalar($value) ? trim((string)$value) : '';
        if ($v === '') {
            continue;
        }

        $rows[] = [
            'code'  => (string)$code,
            'label' => (string)$code,
            'value' => $v,
        ];
    }

    return $rows;
}
private function resolveStateMetaFromTags(array $tags): array
{
    $label = '';

    foreach ($tags as $tag) {
        if (!is_array($tag)) {
            continue;
        }

        if ((int)($tag['tag_cat'] ?? 0) === 19) {
            $label = trim((string)($tag['tag_label'] ?? ''));
            break;
        }
    }

    if ($label === '') {
        return [
            'label' => 'BON ÉTAT',
            'class' => 'state-yellow',
        ];
    }

    $norm = mb_strtolower($label);

    if (str_contains($norm, 'neuf')) {
        return ['label' => 'NEUF', 'class' => 'state-blue'];
    }

    if (str_contains($norm, 'très bon état') || str_contains($norm, 'tres bon etat') || str_contains($norm, 'reconditionné') || str_contains($norm, 'reconditionne')) {
        return ['label' => 'TRÈS BON ÉTAT', 'class' => 'state-green'];
    }

    if (str_contains($norm, 'bon état') || str_contains($norm, 'bon etat')) {
        return ['label' => 'BON ÉTAT', 'class' => 'state-yellow'];
    }

    if (str_contains($norm, 'correct') || str_contains($norm, 'défaut') || str_contains($norm, 'defaut')) {
        return ['label' => 'ÉTAT CORRECT', 'class' => 'state-red'];
    }

    return [
        'label' => strtoupper($label),
        'class' => 'state-yellow',
    ];
}
private function firstNonEmptySpec(string $prefix, array $values, array $keys): ?string
{
    foreach ($keys as $key) {
        $v = trim((string)($values[$key] ?? ''));
        if ($v !== '') {
            return $prefix . $v;
        }
    }

    return null;
}

private function barcodePngDataUri(string $barcode): ?string
{
    $barcode = preg_replace('/\s+/', '', $barcode);
    $barcode = trim((string) $barcode);

    if ($barcode === '') {
        return null;
    }

    try {
        $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();

        $png = $generator->getBarcode(
            $barcode,
            $generator::TYPE_CODE_128,
            1,
            50
        );

        return 'data:image/png;base64,' . base64_encode($png);
    } catch (\Throwable $e) {
        return null;
    }
}

private function qrCodeToDataUri(string $text): ?string
{
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($text);

    try {
        $png = @file_get_contents($url);
        if ($png === false) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($png);
    } catch (\Throwable $e) {
        return null;
    }
}


#[Route('/{id}/label-business-card', name: 'label_business_card', requirements: ['id' => '\d+'], methods: ['GET'])]
public function labelBusinessCard(int $id): Response
{
    $product = $this->hib->getProduct($id);

    if (!$product) {
        throw $this->createNotFoundException("Produit introuvable");
    }

    $miscRows = $this->parseMiscTextRows((string)($product['misc_text'] ?? ''));

    $brandName = trim((string)($product['product_brand_name'] ?? ''));
    if ($brandName === '' && !empty($product['product_brand'])) {
        $brandsRes = $this->hib->listBrands();
        foreach (($brandsRes['data'] ?? []) as $b) {
            if ((int)($b['brand_id'] ?? 0) === (int)$product['product_brand']) {
                $brandName = (string)($b['brand_name'] ?? '');
                break;
            }
        }
    }

    $stateMeta = $this->resolveStateMetaFromTags($product['tags'] ?? []);

    $price = (float)($product['product_discount_price'] ?? 0);
    if ($price <= 0) {
        $price = (float)($product['product_price'] ?? 0);
    }

    $priceLabel = number_format($price, 2, ',', '');

    $productUrl = 'https://shop.multimedia-services.fr/produits/' . ($product['product_id'] ?? $id);
    $qrCodeDataUri = $this->qrCodeToDataUri($productUrl);

    $storage = '';
    foreach ($miscRows as $row) {
        if (mb_strtolower(trim((string)$row['label'])) === 'stockage') {
            $storage = trim((string)$row['value']);
            break;
        }
    }

    $productView = [
        'product_id'      => $product['product_id'] ?? null,
        'product_model'   => (string)($product['product_model'] ?? ''),
        'product_barcode' => (string)($product['product_barcode'] ?? ''),
        'brand_name'      => $brandName,
        'storage'         => $storage,
        'state_label'     => $stateMeta['label'],
        'state_class'     => $stateMeta['class'],
        'price_label'     => $priceLabel,
        'misc_rows'       => $miscRows,
        'footer_label'    => 'GARANTIE 2 ANS',
    ];

    $file = $this->labelPdfGenerator->generateBusinessCardLabel([
        'product' => $productView,
        'qr_code_data_uri' => $qrCodeDataUri,
    ], 'label-business-card-' . $id . '.pdf');

    return $this->redirect($file['url']);
}

private function flattenTagChoices(array $choices, ?string $group = null): array
{
    $out = [];

    foreach ($choices as $label => $value) {
        if (is_array($value)) {
            $out = array_merge($out, $this->flattenTagChoices($value, (string)$label));
            continue;
        }

        $id = (int)$value;
        if ($id <= 0) {
            continue;
        }

        $out[] = [
            'id' => $id,
            'label' => (string)$label,
            'group' => $group,
        ];
    }

    return $out;
}





private function normalizeCategoryLabel(string $label): string
{
    $label = mb_strtolower(trim($label), 'UTF-8');
    $label = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
    $label = preg_replace('/[^a-z0-9]+/', ' ', (string)$label);
    $label = trim((string)$label);

    return $label;
}

private function buildCategorySelectOptions(array $categories): array
{
    $byId = [];
    $childrenByParent = [];

    foreach ($categories as $c) {
        $id = (int)($c['category_id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $c;
        }
    }

    foreach ($categories as $c) {
        $pid = (int)($c['category_id_parent'] ?? 0);
        $childrenByParent[$pid] ??= [];
        $childrenByParent[$pid][] = $c;
    }

    foreach ($childrenByParent as &$kids) {
        usort($kids, function ($a, $b) {
            $pa = (int)($a['category_position'] ?? 0);
            $pb = (int)($b['category_position'] ?? 0);

            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcmp(
                (string)($a['category_name'] ?? ''),
                (string)($b['category_name'] ?? '')
            );
        });
    }
    unset($kids);

    $roots = [];
    foreach ($categories as $c) {
        $pid = (int)($c['category_id_parent'] ?? 0);
        if ($pid === 0 || !isset($byId[$pid])) {
            $roots[] = $c;
        }
    }

    $options = [];

    $walk = function (array $nodes, array $parents = []) use (&$walk, &$options, $childrenByParent) {
        foreach ($nodes as $c) {
            $id = (int)($c['category_id'] ?? 0);
            $name = trim((string)($c['category_name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }

            $children = $childrenByParent[$id] ?? [];
            $hasChildren = count($children) > 0;

            $pathParts = array_merge($parents, [$name]);
            $fullLabel = implode(' › ', $pathParts);

            $options[] = [
                'id' => $id,
                'label' => $fullLabel,
                'selectable' => !$hasChildren,
                'is_parent' => $hasChildren,
                'level' => count($parents),
            ];

            if ($hasChildren) {
                $walk($children, $pathParts);
            }
        }
    };

    $walk($roots, []);

    return $options;
}

private function findDefaultPhoneOccasionCategoryId(array $categories): ?int
{
    $byId = [];
    $childrenByParent = [];

    foreach ($categories as $c) {
        $id = (int)($c['category_id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $c;
        }
    }

    foreach ($categories as $c) {
        $pid = (int)($c['category_id_parent'] ?? 0);
        $childrenByParent[$pid] ??= [];
        $childrenByParent[$pid][] = $c;
    }

    $targetParentNorm = $this->normalizeCategoryLabel('Téléphones');
    $targetChildNorm  = $this->normalizeCategoryLabel('Occasion / reconditionné');

    foreach ($categories as $c) {
        $parentId = (int)($c['category_id'] ?? 0);
        $parentName = $this->normalizeCategoryLabel((string)($c['category_name'] ?? ''));

        if ($parentName !== $targetParentNorm) {
            continue;
        }

        foreach ($childrenByParent[$parentId] ?? [] as $child) {
            $childId = (int)($child['category_id'] ?? 0);
            $childName = $this->normalizeCategoryLabel((string)($child['category_name'] ?? ''));

            if ($childId > 0 && $childName === $targetChildNorm) {
                return $childId;
            }
        }
    }

    return null;
}


}
