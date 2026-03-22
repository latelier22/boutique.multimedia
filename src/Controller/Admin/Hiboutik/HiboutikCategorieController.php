<?php

namespace App\Controller\Admin\Hiboutik;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use App\Service\HiboutikClient;
use App\Entity\Rachat\AttributeDefinition;



// array:35 [▼
//   0 => array:11 [▼
//     "category_id" => 37
//     "category_name" => "Dalle"
//     "category_id_parent" => 10
//     "category_enabled" => 1
//     "category_enabled_www" => 0
//     "category_position" => 1
//     "category_bck_color" => "#c36c7f"
//     "category_color" => "#ffffff"
//     "category_desc" => ""
//     "accounting_account" => ""
//     "category_ref_ext" => ""
//   ]

#[Route('/admin/hiboutik/categories', name: 'admin_hiboutik_categories_')]
final class HiboutikCategorieController extends AbstractController
{
    public function __construct(
        private HiboutikClient $hib,
        private EntityManagerInterface $em,
         private HttpClientInterface $httpClient,

) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
       $categories = $this->hib->getCategories(); // ta liste plate

    // map id => true
    $idMap = [];
    foreach ($categories as $c) {
        $id = (int)($c['category_id'] ?? 0);
        if ($id) $idMap[$id] = true;
    }

    // childrenByParent : parentId => [cat, cat...]
    $childrenByParent = [];
    foreach ($categories as $c) {
        $pid = (int)($c['category_id_parent'] ?? 0);
        $childrenByParent[$pid] ??= [];
        $childrenByParent[$pid][] = $c;
    }

    // (optionnel) tri par position puis nom
    foreach ($childrenByParent as &$kids) {
        usort($kids, function($a, $b) {
            $pa = (int)($a['category_position'] ?? 0);
            $pb = (int)($b['category_position'] ?? 0);
            if ($pa !== $pb) return $pa <=> $pb;
            return strcmp((string)($a['category_name'] ?? ''), (string)($b['category_name'] ?? ''));
        });
    }
    unset($kids);

    // roots = parent == 0 OU parent absent
    $roots = [];
    foreach ($categories as $c) {
        $pid = (int)($c['category_id_parent'] ?? 0);
        if ($pid === 0 || !isset($idMap[$pid])) {
            $roots[] = $c;
        }
    }

    // var_dump($categories, $roots, $childrenByParent);

    return $this->render('@SyliusAdmin/Hiboutik/Categories/index.html.twig', [
        'categories' => $categories,
        'roots' => $roots,
        'childrenByParent' => $childrenByParent,
    ]);
    }





#[Route('/toggle-www/{id}', name: 'toggle_www', methods: ['POST'])]
public function toggleWww(int $id, Request $request): JsonResponse
{
    $this->denyAccessUnlessGranted('ROLE_ADMINISTRATION_ACCESS');

    if (!$this->isCsrfTokenValid('hib_cat_toggle_www_'.$id, (string)$request->request->get('_token'))) {
        return new JsonResponse(['ok' => false, 'error' => 'bad_csrf'], 403);
    }

    $current = (int)$request->request->get('current', 0);
    $next = $current ? 0 : 1;

    $resp = $this->hib->setCategoryAttribute($id, 'category_enabled_www', $next);

    $status = (int)($resp['status'] ?? 0);
    if ($status < 200 || $status >= 300) {
        // On renvoie debug brut si Hiboutik refuse
        return new JsonResponse([
            'ok' => false,
            'error' => 'hiboutik_failed',
            'status' => $status,
            'raw' => $resp['raw'] ?? null,
        ], 502);
    }

    /**
 * 🔥 Ici on force le refresh cache
 */
try {
    $this->httpClient->request('POST',
    'https://api.multimedia-services.fr/webhook/hiboutik?secret=TON_SECRET',
    [
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'body' => [
            'category_id' => $id,
        ],
    ]
);
} catch (\Throwable $e) {
    // on log mais on ne bloque pas le toggle
}


    // ✅ On renvoie toujours un JSON simple (peu importe ce que Hiboutik renvoie)
    return new JsonResponse([
        'ok' => true,
        'id' => $id,
        'category_enabled_www' => $next,
    ]);
}


private function makeReadableAttributeCode(string $label): string
{
    $code = mb_strtolower(trim($label), 'UTF-8');
    $code = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $code);
    $code = preg_replace('/[^a-z0-9]+/', '_', $code);
    $code = trim((string)$code, '_');

    if ($code === '') {
        $code = 'attribut';
    }

    $base = $code;
    $i = 2;

    while ($this->em->getRepository(\App\Entity\Rachat\AttributeDefinition::class)->findOneBy(['code' => $code])) {
        $code = $base . '_' . $i;
        $i++;
    }

    return $code;
}



// ============================
    // 📥 LISTE ATTRIBUTS PAR CAT
    #[Route('/attributes/{categoryId}', name: 'attributes_list', methods: ['GET'])]
public function attributesList(int $categoryId): JsonResponse
{
    $rows = $this->em->getConnection()->fetchAllAssociative(
        'SELECT id, code, label, type, category, options
         FROM attributes_definitions
         WHERE category = :cat
         ORDER BY id ASC',
        ['cat' => (string)$categoryId]
    );

    foreach ($rows as &$row) {
        $row['options'] = !empty($row['options'])
            ? json_decode($row['options'], true)
            : [];
    }

    return $this->json($rows);
}

    // ============================
    // ➕ CREATION ATTRIBUT
    // ============================
   #[Route('/attributes/create', name: 'attributes_create', methods: ['POST'])]
public function attributesCreate(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);

    if (!$data || empty($data['label']) || empty($data['category_id'])) {
        return $this->json(['ok' => false, 'error' => 'bad_request'], 400);
    }

    $label = trim((string)$data['label']);
    $type = trim((string)($data['type'] ?? 'text'));
    $categoryId = (string)$data['category_id'];

    if ($label === '') {
        return $this->json(['ok' => false, 'error' => 'label_required'], 400);
    }

    if (!in_array($type, ['text', 'select'], true)) {
        $type = 'text';
    }

    $attr = new AttributeDefinition();
    $attr->setCode($this->makeReadableAttributeCode($label));
    $attr->setLabel($label);
    $attr->setType($type);
    $attr->setCategory($categoryId);

    if ($type === 'select') {
        $opts = array_values(array_filter(array_map('trim', explode(',', (string)($data['options'] ?? ''))), fn($v) => $v !== ''));
        $attr->setOptions($opts);
    } else {
        $attr->setOptions([]);
    }

    $this->em->persist($attr);
    $this->em->flush();

    return $this->json([
        'ok' => true,
        'item' => [
            'id' => $attr->getId(),
            'code' => $attr->getCode(),
            'label' => $attr->getLabel(),
            'type' => $attr->getType(),
            'category' => $attr->getCategory(),
            'options' => $attr->getOptions() ?? [],
        ]
    ]);
}

    #[Route('/attributes/{id}/update', name: 'attributes_update', methods: ['POST'])]
public function attributesUpdate(int $id, Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);

    /** @var AttributeDefinition|null $attr */
    $attr = $this->em->getRepository(AttributeDefinition::class)->find($id);

    if (!$attr) {
        return $this->json(['ok' => false, 'error' => 'not_found'], 404);
    }

    $label = trim((string)($data['label'] ?? ''));
    $type = trim((string)($data['type'] ?? 'text'));
    $optionsRaw = (string)($data['options'] ?? '');

    if ($label === '') {
        return $this->json(['ok' => false, 'error' => 'label_required'], 400);
    }

    if (!in_array($type, ['text', 'select'], true)) {
        $type = 'text';
    }

    $attr->setLabel($label);
    $attr->setType($type);

    if ($type === 'select') {
        $opts = array_values(array_filter(array_map('trim', explode(',', $optionsRaw)), fn($v) => $v !== ''));
        $attr->setOptions($opts);
    } else {
        $attr->setOptions([]);
    }

    $this->em->flush();

    return $this->json([
        'ok' => true,
        'item' => [
            'id' => $attr->getId(),
            'code' => $attr->getCode(),
            'label' => $attr->getLabel(),
            'type' => $attr->getType(),
            'category' => $attr->getCategory(),
            'options' => $attr->getOptions() ?? [],
        ]
    ]);
}

#[Route('/attributes/{id}/delete', name: 'attributes_delete', methods: ['POST'])]
public function attributesDelete(int $id): JsonResponse
{
    /** @var AttributeDefinition|null $attr */
    $attr = $this->em->getRepository(AttributeDefinition::class)->find($id);

    if (!$attr) {
        return $this->json(['ok' => false, 'error' => 'not_found'], 404);
    }

    $this->em->remove($attr);
    $this->em->flush();

    return $this->json(['ok' => true, 'id' => $id]);
}


}
