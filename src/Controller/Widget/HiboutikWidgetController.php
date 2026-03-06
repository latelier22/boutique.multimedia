<?php

namespace App\Controller\Widget;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use Psr\Log\LoggerInterface;
use App\Service\HiboutikClient;

final class HiboutikWidgetController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $http,
        private string $account,
        private string $login,
        private string $apiKey,
        private string $widgetSecret,
        private LoggerInterface $logger, // ✅ ajoute ça
        private HiboutikClient $hib,
    ) {}

#[Route('/hiboutik/widget/product/save', name: 'hiboutik_widget_product_save', methods: ['POST','OPTIONS'])]
public function save(Request $request): JsonResponse
{
    if (!$this->isValidHiboutikToken($request)) {
        return $this->json(['ok' => false, 'error' => 'invalid token'], 403);
    }

    $data = json_decode($request->getContent(), true) ?: [];
    $productId = (int)($data['product_id'] ?? 0);
    $miscText  = (string)($data['misc_text'] ?? '');

    if ($productId <= 0) {
        return $this->json(['ok' => false, 'error' => 'missing product_id'], 400);
    }

    // ✅ UTILISATION DE TA METHODE (via wrapper public)
    $r = $this->hib->putProductAttributeSingle($productId, 'misc_text', $miscText);

    // tu renvoies tel quel pour voir l'erreur hiboutik
    return $this->json([
        'ok' => (bool)($r['ok'] ?? false),
        'status' => $r['status'] ?? 0,
        'raw' => $r['raw'] ?? null,
        'data' => $r['data'] ?? null,
    ], ($r['ok'] ?? false) ? 200 : 502);
}

    #[Route('/hiboutik/widget/product', name: 'hiboutik_widget_product', methods: ['GET','OPTIONS'])]
    public function product(Request $request): JsonResponse
    {
        // 🔹 1️⃣ Préflight CORS (évite 405 sur OPTIONS)
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => 'X-HIBOUTIK-TOKEN, X-HIBOUTIK-TOKEN-TIME, Content-Type',
                'Access-Control-Max-Age' => '86400',
            ]);
        }

        try {

            $productId = (int)(
                $request->query->get('sale_id')
                ?? $request->query->get('product_id')
                ?? 0
            );

           $this->logger->info('[HIB_WIDGET] hit', [
    'uri' => $request->getRequestUri(),
    'sale_id' => $request->query->get('sale_id'),
    'has_token' => (bool) $request->headers->get('X-HIBOUTIK-TOKEN'),
    'has_time'  => (bool) $request->headers->get('X-HIBOUTIK-TOKEN-TIME'),
]);

            if ($productId <= 0) {
                return $this->hibJson('Widget', '<p>❌ sale_id manquant</p>');
            }

            // 🔹 2️⃣ Sécurité Token (bypass possible ?debug=1)
            $debug = $request->query->get('debug') === '1';

            if (!$debug && !$this->isValidHiboutikToken($request)) {
                return $this->hibJson('Widget', '<p style="color:red;">❌ Token Hiboutik invalide</p>', 403);
            }

            // 🔹 3️⃣ Produit
            $product = $this->hibGetFirst("products/$productId");

            if (!$product) {
                return $this->hibJson('Widget', '<p>❌ Produit introuvable.</p>');
            }

            // 🔹 4️⃣ Lookups sécurisés
            $brands    = $this->hibGet("brands") ?? [];
            $cats      = $this->hibGet("categories") ?? [];
            $suppliers = $this->hibGet("suppliers") ?? [];

            $brandName = $this->findName(
                $brands,
                'brand_id',
                (string)($product['product_brand'] ?? ''),
                'brand_name'
            );

            $catName = $this->findName(
                $cats,
                'category_id',
                (string)($product['product_category'] ?? ''),
                'category_name'
            );

            $supplier = $this->findRow(
                $suppliers,
                'supplier_id',
                (string)($product['product_supplier'] ?? '')
            );

            // 🔹 5️⃣ Rendu HTML Twig
            $html = $this->renderView('hiboutik/widget/widget_product.html.twig', [
                'p' => $product,
                'brandName' => $brandName,
                'catName' => $catName,
                'supplier' => $supplier,
            ]);

            return $this->hibJson('Produit + MISC', $html);

        } catch (\Throwable $e) {

            return new JsonResponse([
                'head' => ['title' => 'Widget ERROR', 'icon' => 'fa fa-bug'],
                'body' => '<pre style="white-space:pre-wrap;color:#b91c1c;">'
                    . htmlspecialchars($e->getMessage(), ENT_QUOTES)
                    . "\n" . htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES)
                    . '</pre>'
            ], 500);
        }
    }

private function hibJson(string $title, string $body, int $status = 200): JsonResponse
{
    return new JsonResponse([
        'head' => ['title' => $title, 'icon' => 'fa fa-cube'],
        'body' => $body,
    ], $status);
}

    private function isValidHiboutikToken(Request $request): bool
    {
        $token = (string)$request->headers->get('X-HIBOUTIK-TOKEN', '');
        $time  = (string)$request->headers->get('X-HIBOUTIK-TOKEN-TIME', '');

        if ($token === '' || $time === '') return false;

        $now = time();
        $t = (int)$time;

        if (abs($now - $t) > 300) return false;

        $expected = hash_hmac('sha256', $time, $this->widgetSecret);

        return hash_equals($expected, $token);
    }

    private function hibGet(string $endpoint): ?array
    {
        $url = sprintf(
            'https://%s.hiboutik.com/api/%s',
            $this->account,
            ltrim($endpoint, '/')
        );

        $res = $this->http->request('GET', $url, [
            'auth_basic' => [$this->login, $this->apiKey],
            'timeout' => 15,
        ]);

        $code = $res->getStatusCode();

        if ($code < 200 || $code >= 300) {
            return null;
        }

        return $res->toArray(false);
    }

    private function hibGetFirst(string $endpoint): ?array
    {
        $data = $this->hibGet($endpoint);

        if (!$data) return null;

        if (isset($data[0]) && is_array($data[0])) return $data[0];
        if (isset($data['product_id'])) return $data;

        return null;
    }

    private function findName(array $rows, string $idKey, string $id, string $nameKey): string
    {
        foreach ($rows as $r) {
            if ((string)($r[$idKey] ?? '') === $id) {
                return (string)($r[$nameKey] ?? $r['name'] ?? '');
            }
        }
        return '';
    }

    private function findRow(array $rows, string $idKey, string $id): ?array
    {
        foreach ($rows as $r) {
            if ((string)($r[$idKey] ?? '') === $id) {
                return $r;
            }
        }
        return null;
    }
}