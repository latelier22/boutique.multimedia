<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HiboutikClient
{
    private ?array $lastDebug = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $hibAccount,
        private string $hibLogin,
        private string $hibApiKey,
        private bool $debug = false,
        private ?LoggerInterface $logger = null,
    ) {}

    private function baseUrl(string $path): string
    {
        return sprintf('https://%s.hiboutik.com/api/%s', $this->hibAccount, ltrim($path, '/'));
    }

    private function auth(array $extra = []): array
    {
        return $extra + [
            'auth_basic' => [$this->hibLogin, $this->hibApiKey],
            'headers'    => ['Accept' => 'application/json'],
        ];
    }

    /** Requête générique + debug conservé */
    protected function req(string $method, string $path, array $options = []): array
    {
        $url = $this->baseUrl($path);
        $r   = $this->httpClient->request($method, $url, $this->auth($options));
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = [
            'url'    => $url,
            'method' => $method,
            'opts'   => $options,
            'status' => $status,
            'raw'    => $raw,
            'data'   => $data,
        ];
        if ($this->debug && $this->logger) {
            $this->logger->info('[HIBOUTIK]', $this->lastDebug);
        }

        return [
            'ok'    => $status >= 200 && $status < 300,
            'status'=> $status,
            'data'  => $data,
        ];
    }

    public function getLastDebug(): ?array
    {
        return $this->lastDebug;
    }

    /* ===================== SUPPLIERS ===================== */

    /** Retourne un tableau [ 'ok'=>..., 'status'=>..., 'data'=>[...], 'debug'=>[...] ] */
    public function listSuppliers(): array
    {
        $r = $this->httpClient->request('GET', $this->baseUrl('suppliers/'), $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = [
            'method' => 'GET',
            'url'    => $this->baseUrl('suppliers/'),
            'status' => $status,
            'raw'    => $raw,
        ];

        return [
            'ok'    => $status >= 200 && $status < 300,
            'status'=> $status,
            'data'  => $data,
            'debug' => $this->lastDebug,
        ];
    }

    public function findSupplierById(int $id): ?array
    {
        $res  = $this->listSuppliers();
        $list = $res['data'] ?? [];

        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $s) {
            if ((int)($s['supplier_id'] ?? 0) === $id) {
                return $s;
            }
        }

        return null;
    }

    public function createSupplier(array $supplier): array
    {
        $res = $this->req('POST', 'suppliers/', ['json' => $supplier]);
        return $res['data'] ?? [];
    }

    /** Mise à jour ATTRIBUT PAR ATTRIBUT (PUT x-www-form-urlencoded) */
    public function updateSupplierAttribute(int $id, string $attr, $value): array
    {
        $opts = $this->auth();
        $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        $opts['body'] = http_build_query([
            'supplier_attribute' => $attr,
            'supplier_id'        => $id,
            'new_value'          => $value,
        ]);

        $r     = $this->httpClient->request('PUT', $this->baseUrl('suppliers/'), $opts);
        $status= $r->getStatusCode();
        $raw   = $r->getContent(false);

        $this->lastDebug = [
            'method' => 'PUT',
            'url'    => $this->baseUrl('suppliers/'),
            'status' => $status,
            'raw'    => $raw,
            'sent'   => ['attr' => $attr, 'id' => $id, 'value' => $value],
        ];

        return [
            'ok'    => $status >= 200 && $status < 300,
            'status'=> $status,
            'data'  => json_decode($raw, true),
            'debug' => $this->lastDebug,
        ];
    }

    public function updateSupplierAttributes(int $id, array $fields): array
    {
        $out = [];
        foreach ($fields as $attr => $val) {
            if ($attr === 'supplier_ref_ext') {
                continue; // protégé
            }
            $out[$attr] = $this->updateSupplierAttribute($id, $attr, $val);
        }
        return $out;
    }

    public function deactivateSupplier(int $id): array
    {
        return $this->updateSupplierAttribute($id, 'supplier_enabled', 0);
    }

    public function activateSupplier(int $id): array
    {
        return $this->updateSupplierAttribute($id, 'supplier_enabled', 1);
    }

    public function toggleSupplier(int $id, int $value): array
    {
        return $this->updateSupplierAttribute($id, 'supplier_enabled', $value);
    }

    /* ===================== PRODUITS ===================== */

    /** ⚠️ La bonne route qui marche: products/search/?product_supplier={id} */
    public function listProductsBySupplier(int $supplierId): array
    {
        $url   = $this->baseUrl('products/search/?product_supplier=' . $supplierId);
        $r     = $this->httpClient->request('GET', $url, $this->auth());
        $status= $r->getStatusCode();
        $raw   = $r->getContent(false);
        $data  = json_decode($raw, true);

        $this->lastDebug = [
            'method' => 'GET',
            'url'    => $url,
            'status' => $status,
            'raw'    => $raw,
        ];

        return [
            'ok'    => $status >= 200 && $status < 300,
            'status'=> $status,
            'data'  => $data,
            'debug' => $this->lastDebug,
        ];
    }

    /** Retourne le supplier dont supplier_ref_ext == $refExt, sinon null */
    public function findSupplierByRefExt(string $refExt): ?array
    {
        $res  = $this->listSuppliers();
        $list = $res['data'] ?? [];
        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $s) {
            if (($s['supplier_ref_ext'] ?? '') === $refExt) {
                return $s;
            }
        }
        return null;
    }

    /** Crée un produit rachat minimaliste (non publié web) */
    public function createProduct(array $payload): array
    {
        $res = $this->req('POST', 'products/', ['json' => $payload]);
        return $res['data'] ?? [];
    }

    /** Création d’un arrivage (qty=1) */
    public function createArrival(int $productId, float $unitPrice, int $qty = 1): array
    {
        $opts = $this->auth();
        $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        $opts['body'] = http_build_query([
            'product_id'  => $productId,
            'quantity'    => $qty,
            'unit_price'  => $unitPrice,
            // 'store_id'  => 1, // si besoin
        ]);

        $r      = $this->httpClient->request('POST', $this->baseUrl('arrivals/'), $opts);
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);

        return [
            'ok'    => $status >= 200 && $status < 300,
            'status'=> $status,
            'data'  => json_decode($raw, true),
        ];
    }

    /* ===================== STORES & TILL ===================== */

    public function getStores(): array
    {
        $r      = $this->httpClient->request('GET', $this->baseUrl('stores'), $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = [
            'method' => 'GET',
            'url'    => $this->baseUrl('stores'),
            'status' => $status,
            'raw'    => $raw,
        ];

        return is_array($data) ? $data : [];
    }

    /** Récup info store par défaut (id + devise) */
    public function getDefaultStoreMeta(): array
    {
        $stores = $this->getStores();
        $s      = $stores[0] ?? [];

        return [
            'store_id'      => (int)($s['store_id'] ?? 1),
            'currency_code' => (string)($s['store_default_currency'] ?? 'EUR'),
        ];
    }

    /** /till/{store_id}/{year}/{month} */
    public function listTillMovements(int $storeId, int $year, int $month): array
    {
        $path = sprintf('till/%d/%d/%d', $storeId, $year, $month);
        $res  = $this->req('GET', $path);

        if (!$res['ok'] || !is_array($res['data'])) {
            return [
                'ok'     => false,
                'status' => $res['status'] ?? 500,
                'data'   => [],
            ];
        }

        $data = $res['data'];

        // Certains comptes renvoient { "data": [ ... ] }
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        if (!is_array($data)) {
            $data = [];
        }

        return [
            'ok'     => true,
            'status' => $res['status'] ?? 200,
            'data'   => $data,
        ];
    }

    public function tillCashOut(int $storeId, float $amount, string $currencyCode, string $comments): array
    {
        $opts = $this->auth();
        $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        $opts['body'] = http_build_query([
            'store_id'      => $storeId,
            'amount'        => number_format($amount, 2, '.', ''),
            'currency_code' => $currencyCode,
            'comments'      => $comments,
        ]);

        $r      = $this->httpClient->request('POST', $this->baseUrl('till/cash_out'), $opts);
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = [
            'method' => 'POST',
            'url'    => $this->baseUrl('till/cash_out'),
            'status' => $status,
            'raw'    => $raw,
            'sent'   => [
                'store_id'      => $storeId,
                'amount'        => number_format($amount, 2, '.', ''),
                'currency_code' => $currencyCode,
                'comments'      => $comments,
            ],
        ];

        return [
            'ok'    => $status >= 200 && $status < 300,
            'status'=> $status,
            'data'  => $data,
            'debug' => $this->lastDebug,
        ];
    }

    public function tillCashIn(int $storeId, float $amount, string $currencyCode, string $comments): array
    {
        $opts = $this->auth();
        $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        $opts['body'] = http_build_query([
            'store_id'      => $storeId,
            'amount'        => number_format($amount, 2, '.', ''),
            'currency_code' => $currencyCode,
            'comments'      => $comments,
        ]);

        $r      = $this->httpClient->request('POST', $this->baseUrl('till/cash_in'), $opts);
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = [
            'method' => 'POST',
            'url'    => $this->baseUrl('till/cash_in'),
            'status' => $status,
            'raw'    => $raw,
            'sent'   => [
                'store_id'      => $storeId,
                'amount'        => number_format($amount, 2, '.', ''),
                'currency_code' => $currencyCode,
                'comments'      => $comments,
            ],
        ];

        return [
            'ok'    => $status >= 200 && $status < 300,
            'status'=> $status,
            'data'  => $data,
            'debug' => $this->lastDebug,
        ];
    }

    /* ===================== IMAGES PRODUITS ===================== */

    public function uploadProductThumb100(int $productId, string $path, string $framing = 'default', ?string $filename = null): array
    {
        $filename = $filename ?: basename($path);
        $mime     = function_exists('mime_content_type') ? mime_content_type($path) : 'image/jpeg';
        $url      = sprintf('https://%s.hiboutik.com/api/products_images/%d', $this->hibAccount, $productId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->hibLogin . ':' . $this->hibApiKey,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
            CURLOPT_POSTFIELDS     => [
                'image'        => new \CURLFile($path, $mime, $filename),
                'framing_type' => $framing,
            ],
        ]);

        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok'    => $code >= 200 && $code < 300,
            'status'=> $code,
            'raw'   => $res,
            'error' => $err,
        ];
    }

    public function uploadProductWeb1000(int $productId, string $path, int $imageId = 1, string $framing = 'default', ?string $filename = null): array
    {
        $filename = $filename ?: basename($path);
        $mime     = function_exists('mime_content_type') ? mime_content_type($path) : 'image/jpeg';
        $url      = sprintf('https://%s.hiboutik.com/api/products_images_1000x1000/%d', $this->hibAccount, $productId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->hibLogin . ':' . $this->hibApiKey,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
            CURLOPT_POSTFIELDS     => [
                'image'        => new \CURLFile($path, $mime, $filename),
                'framing_type' => $framing,
                'image_id'     => (string) $imageId,
            ],
        ]);

        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok'    => $code >= 200 && $code < 300,
            'status'=> $code,
            'raw'   => $res,
            'error' => $err,
        ];
    }

    /** GET /api/products_images/?product_id=ID  (liste les images d’un produit) */
    public function listProductImages(int $productId): array
    {
        $url    = $this->baseUrl('products_images/?product_id=' . $productId);
        $r      = $this->httpClient->request('GET', $url, $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = [
            'method' => 'GET',
            'url'    => $url,
            'status' => $status,
            'raw'    => $raw,
        ];

        return [
            'ok'    => $status >= 200 && $status < 300,
            'status'=> $status,
            'data'  => $data,
            'debug' => $this->lastDebug,
        ];
    }

    /** DELETE /api/products_images/{image_id}  (suppression) */
    public function deleteProductImage(int $imageId): array
    {
        $url    = $this->baseUrl('products_images/' . $imageId);
        $r      = $this->httpClient->request('DELETE', $url, $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = [
            'method' => 'DELETE',
            'url'    => $url,
            'status' => $status,
            'raw'    => $raw,
        ];

        return [
            'ok'    => $status >= 200 && $status < 300,
            'status'=> $status,
            'data'  => $data,
            'debug' => $this->lastDebug,
        ];
    }

    public function getProduct(int $productId): array
    {
        $url    = $this->baseUrl('products/' . $productId);
        $r      = $this->httpClient->request('GET', $url, $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = [
            'method' => 'GET',
            'url'    => $url,
            'status' => $status,
            'raw'    => $raw,
        ];

        // L’API renvoie parfois [ { ... } ]
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }

        return is_array($data) ? $data : [];
    }

    /** Optionnel : tenter d’inférer image_id (1..4) depuis image_name big_{id}-{n}.jpg */
    public static function inferImageIdFromName(?string $imageName): ?int
    {
        if (!$imageName) {
            return null;
        }
        if (preg_match('/^big_\d+-(\d+)\.(jpg|jpeg)$/i', $imageName, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    // 🔽 AJOUTER DANS HiboutikClient


    // Marquer une ligne comme reçue (= received_quantity = quantity)
public function receiveInventoryInputDetail(int $detailId, int $receivedQty): array
{
    $opts = $this->auth();
    $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
    $opts['body'] = http_build_query(['received_quantity' => $receivedQty]);

    $url = $this->baseUrl('inventory_input_details/' . $detailId);
    $r   = $this->httpClient->request('PUT', $url, $opts);
    return ['ok' => $r->getStatusCode()>=200 && $r->getStatusCode()<300,
            'status'=>$r->getStatusCode(),
            'raw'=>$r->getContent(false)];
}

// Remplace ENTIEREMENT ta méthode addProductToInventoryInput par celle-ci
public function addProductToInventoryInput(int $inventoryInputId, int $productId, int $qty): array
{
    $opts = $this->auth();
    $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
    $opts['body'] = http_build_query([
        'product_id' => $productId,
        'quantity'   => $qty,
        'received'  => $qty,
    ]);

    $url = $this->baseUrl('inventory_input_details/' . $inventoryInputId);
    $r   = $this->httpClient->request('POST', $url, $opts);

    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    // log debug lisible si tu as un logger
    $this->lastDebug = [
        'method' => 'POST',
        'url'    => $url,
        'status' => $status,
        'headers'=> $opts['headers'],
        'body'   => $opts['body'],
        'raw'    => $raw,
        'data'   => $data,
    ];
    if ($this->debug && $this->logger) {
        $this->logger->info('[HIB ADD INVENTORY DETAIL]', $this->lastDebug);
    }

    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'raw' => $raw];
}


public function findCurrentMonthlyArrivalId(string $prefix = 'RACHAT MENSUEL'): ?int
{
    $r = $this->httpClient->request('GET', $this->baseUrl('inventory_inputs/'), $this->auth());
    $list = json_decode($r->getContent(false), true) ?? [];
    $target = sprintf('%s-%02d-%04d', $prefix, (int)date('m'), (int)date('Y'));

    foreach ($list as $row) {
        if (stripos($row['inventory_input_label'] ?? '', $target) === 0) {
            return (int)$row['inventory_input_id'];
        }
    }
    return null;
}

public function findInventoryInputByLabel(string $label): ?array
{
    // 1) endpoint "search" si dispo (plus rapide)
    $url = $this->baseUrl('inventory_inputs/search/?inventory_input_label=' . urlencode($label));
    $r   = $this->httpClient->request('GET', $url, $this->auth());
    $arr = json_decode($r->getContent(false), true);
    if (is_array($arr) && !empty($arr)) {
        // certains comptes renvoient [ {...} ], d’autres {"data":[...]}
        $list = isset($arr['data']) && is_array($arr['data']) ? $arr['data'] : $arr;
        foreach ($list as $row) {
            if (($row['inventory_input_label'] ?? '') === $label) {
                return $row;
            }
        }
    }

    // 2) fallback full list
    $url = $this->baseUrl('inventory_inputs/');
    $r   = $this->httpClient->request('GET', $url, $this->auth());
    $arr = json_decode($r->getContent(false), true) ?: [];
    if (is_array($arr)) {
        foreach ($arr as $row) {
            if (($row['inventory_input_label'] ?? '') === $label) {
                return $row;
            }
        }
    }

    return null;
}

/**
 * Retourne l’ID de l’arrivage mensuel "RACHAT MENSUEL-mm-YYYY" existant,
 * ou le crée si absent, en gardant même stock & fournisseur (par défaut 1 et 3).
 */
public function getOrCreateMonthlyRachatInput(int $stockId = 1, int $supplierId = 3, string $prefix = 'RACHAT MENSUEL'): array
{
    $label = sprintf('%s-%02d-%04d', $prefix, (int)date('m'), (int)date('Y'));

    // 🔎 1) Réutiliser si déjà présent
    $found = $this->findInventoryInputByLabel($label);
    if ($found && !empty($found['inventory_input_id'])) {
        return ['ok' => true, 'id' => (int)$found['inventory_input_id'], 'label' => $label, 'created' => false];
    }

    // 🆕 2) Créer sinon (⚠️ mêmes champs que ton exemple qui marche)
    $opts = $this->auth();
    $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
    $opts['body'] = http_build_query([
        'inventory_input_stock_id'    => $stockId,
        'inventory_input_supplier_id' => $supplierId,
        'inventory_input_date'        => date('Y-m-d'),
        'inventory_input_quantity'    => 0,
        'inventory_input_amount'      => '0.00',
        'inventory_input_label'       => $label,
        'supplier_invoice_number'     => '',
        'delivery_amount'             => '0.00',
        'payment_date'                => '0000-00-00',
        'invoice_date'                => '0000-00-00',
    ]);

    $r      = $this->httpClient->request('POST', $this->baseUrl('inventory_inputs/'), $opts);
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    if ($status >= 200 && $status < 300) {
        // l’API renvoie souvent un tableau avec la ligne créée dedans
        $row = is_array($data) && isset($data[0]) ? $data[0] : $data;
        $id  = (int)($row['inventory_input_id'] ?? 0);
        if ($id > 0) {
            return ['ok' => true, 'id' => $id, 'label' => $label, 'created' => true];
        }
    }

    return ['ok' => false, 'status' => $status, 'error' => $raw];
}

}
