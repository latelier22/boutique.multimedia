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
    $$this->httpClient->request('POST',
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

}
