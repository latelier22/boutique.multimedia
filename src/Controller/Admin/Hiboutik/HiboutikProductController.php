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

#[Route('/admin/hiboutik/product', name: 'admin_hiboutik_product_')]
final class HiboutikProductController extends AbstractController
{
    public function __construct(
        private HiboutikClient $hib,
        private CacheApiClient $cacheApi,
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
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
        'to' => 200,
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

    $error = null;

    // -------------------------
    // POST
    // -------------------------
    if ($request->isMethod('POST')) {

        // 🔒 CSRF
        if (!$this->isCsrfTokenValid(
            'hiboutik_edit_' . $id,
            (string)$request->request->get('_csrf_token')
        )) {
            throw $this->createAccessDeniedException('CSRF invalid');
        }

        // -------------------------
        // DATA
        // -------------------------
        $www = $request->request->has('product_display_www') ? '1' : '0';

        $fields = [
            'product_model'          => trim((string)$request->request->get('product_model', '')),
            'product_barcode'       => trim((string)$request->request->get('product_barcode', '')),
            'product_price'          => (string)$request->request->get('product_price', ''),
            'product_discount_price' => (string)$request->request->get('product_discount_price', ''),
            'product_category'       => (string)$request->request->get('product_category', '0'),
            'product_brand'          => (string)$request->request->get('product_brand', '0'),
            'product_display_www'    => $www,
        ];

        // -------------------------
        // FORMAT PRIX
        // -------------------------
        foreach (['product_price','product_discount_price'] as $k) {

            $v = str_replace(',', '.', trim((string)$fields[$k]));

            if ($v === '') {
                $v = '0.00';
            }

            if (is_numeric($v)) {
                $v = number_format((float)$v, 2, '.', '');
            }

            $fields[$k] = $v;
        }

        // -------------------------
        // UPDATE HIBOUTIK
        // -------------------------
        $res = $this->hib->updateProductAttributes($id, $fields);

        if (!($res['ok'] ?? false)) {
            $error = "Update Hiboutik KO";
            $this->addFlash('error', $error);

            return $this->redirectToRoute('admin_hiboutik_product_edit', [
                'id' => $id
            ]);
        }

        // -------------------------
        // REFRESH CACHE (optionnel mais conseillé)
        // -------------------------
        $this->refreshProduct($id);

        // -------------------------
        // SUCCESS
        // -------------------------
        $this->addFlash('success', "Produit #$id mis à jour.");

        return $this->redirectToRoute('admin_hiboutik_product_edit', [
            'id' => $id
        ]);
    }


    $miscValues = $this->parseMiscTextMap((string)($product['misc_text'] ?? ''));
    // -------------------------
    // GET
    // -------------------------
    return $this->render('@SyliusAdmin/Hiboutik/Products/edit.html.twig', [
        'id'         => $id,
        'product'    => $product,
        'product_barcode' => $product['product_barcode'] ?? '',
        'categories' => $categories,
        'brands'     => $brands,
        'error'      => $error,
        'miscValues' => $miscValues,

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
