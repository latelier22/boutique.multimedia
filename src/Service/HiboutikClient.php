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
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'data'   => $data,
            'raw'    => $raw,
        ];
    }

    public function getLastDebug(): ?array
    {
        return $this->lastDebug;
    }

    /* ===================== SUPPLIERS ===================== */

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
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'data'   => $data,
            'debug'  => $this->lastDebug,
        ];
    }

    public function findSupplierById(int $id): ?array
    {
        $res  = $this->listSuppliers();
        $list = $res['data'] ?? [];
        if (!is_array($list)) return null;

        foreach ($list as $s) {
            if ((int)($s['supplier_id'] ?? 0) === $id) return $s;
        }
        return null;
    }

    public function createSupplier(array $supplier): array
    {
        $res = $this->req('POST', 'suppliers/', ['json' => $supplier]);
        return $res['data'] ?? [];
    }

    public function updateSupplierAttribute(int $id, string $attr, $value): array
    {
        $opts = $this->auth();
        $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        $opts['body'] = http_build_query([
            'supplier_attribute' => $attr,
            'supplier_id'        => $id,
            'new_value'          => $value,
        ]);

        $r      = $this->httpClient->request('PUT', $this->baseUrl('suppliers/'), $opts);
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);

        $this->lastDebug = [
            'method' => 'PUT',
            'url'    => $this->baseUrl('suppliers/'),
            'status' => $status,
            'raw'    => $raw,
            'sent'   => ['attr' => $attr, 'id' => $id, 'value' => $value],
        ];

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'data'   => json_decode($raw, true),
            'debug'  => $this->lastDebug,
        ];
    }

    public function updateSupplierAttributes(int $id, array $fields): array
    {
        $out = [];
        foreach ($fields as $attr => $val) {
            if ($attr === 'supplier_ref_ext') continue; // protégé
            $out[$attr] = $this->updateSupplierAttribute($id, $attr, $val);
        }
        return $out;
    }

    public function deactivateSupplier(int $id): array { return $this->updateSupplierAttribute($id, 'supplier_enabled', 0); }
    public function activateSupplier(int $id): array   { return $this->updateSupplierAttribute($id, 'supplier_enabled', 1); }
    public function toggleSupplier(int $id, int $value): array { return $this->updateSupplierAttribute($id, 'supplier_enabled', $value); }

    public function findSupplierByRefExt(string $refExt): ?array
    {
        $res  = $this->listSuppliers();
        $list = $res['data'] ?? [];
        if (!is_array($list)) return null;

        foreach ($list as $s) {
            if (($s['supplier_ref_ext'] ?? '') === $refExt) return $s;
        }
        return null;
    }

    /* ===================== PRODUITS ===================== */

    public function listProductsBySupplier(int $supplierId): array
    {
        $url    = $this->baseUrl('products/search/?product_supplier=' . $supplierId);
        $r      = $this->httpClient->request('GET', $url, $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = ['method' => 'GET', 'url' => $url, 'status' => $status, 'raw' => $raw];

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'data'   => $data,
            'debug'  => $this->lastDebug,
        ];
    }

    public function createProduct(array $payload): array
    {
        $res = $this->req('POST', 'products/', ['json' => $payload]);
        return $res['data'] ?? [];
    }

    public function getProduct(int $productId): array
    {
        $url    = $this->baseUrl('products/' . $productId);
        $r      = $this->httpClient->request('GET', $url, $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = ['method' => 'GET', 'url' => $url, 'status' => $status, 'raw' => $raw];

        if (is_array($data) && isset($data[0]) && is_array($data[0])) return $data[0];
        return is_array($data) ? $data : [];
    }

    /* ===================== STORES & TILL ===================== */

    public function getStores(): array
    {
        $r      = $this->httpClient->request('GET', $this->baseUrl('stores'), $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = ['method' => 'GET', 'url' => $this->baseUrl('stores'), 'status' => $status, 'raw' => $raw];
        return is_array($data) ? $data : [];
    }

    /** Récup info store par défaut (id + devise) */
    public function getDefaultStoreMeta(): array
    {
        $stores = $this->getStores();
        $s      = $stores[0] ?? [];

        return [
            'store_id'       => (int)($s['store_id'] ?? 1),
            'currency_code'  => (string)($s['store_default_currency'] ?? 'EUR'),
        ];
    }

    public function listTillMovements(int $storeId, int $year, int $month): array
    {
        $path = sprintf('till/%d/%d/%d', $storeId, $year, $month);
        $res  = $this->req('GET', $path);

        if (!$res['ok'] || !is_array($res['data'])) {
            return ['ok' => false, 'status' => $res['status'] ?? 500, 'data' => []];
        }

        $data = $res['data'];
        if (isset($data['data']) && is_array($data['data'])) $data = $data['data'];
        if (!is_array($data)) $data = [];

        return ['ok' => true, 'status' => $res['status'] ?? 200, 'data' => $data];
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
            'sent'   => ['store_id' => $storeId, 'amount' => number_format($amount,2,'.',''), 'currency_code' => $currencyCode, 'comments' => $comments],
        ];

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'debug' => $this->lastDebug];
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
            'sent'   => ['store_id' => $storeId, 'amount' => number_format($amount,2,'.',''), 'currency_code' => $currencyCode, 'comments' => $comments],
        ];

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'debug' => $this->lastDebug];
    }

    /* ===================== INVENTORY (arrivages) ===================== */

    /** Label EXACT: "RACHAT dd-mm-YYYY Nom Prénom" */
    public function buildDailyRachatLabel(\DateTimeInterface $d, string $nom, string $prenom): string
    {
        $date = $d->format('d-m-Y');
        $nom = trim($nom);
        $prenom = trim($prenom);
        return trim(sprintf('RACHAT %s %s %s', $date, $nom, $prenom));
    }

    /** (optionnel) mensuel: "RACHAT MENSUEL-mm-YYYY" */
    public function buildMonthlyRachatLabel(\DateTimeInterface $d, string $prefix = 'RACHAT MENSUEL'): string
    {
        return sprintf('%s-%02d-%04d', $prefix, (int)$d->format('m'), (int)$d->format('Y'));
    }

    /** GET /inventory_inputs/?p=1 */
    public function listInventoryInputs(int $page = 1): array
    {
        return $this->req('GET', 'inventory_inputs/?p=' . max(1, $page));
    }

    /** Trouve un inventory_input par label (search si dispo + fallback liste paginée) */
    public function findInventoryInputByLabel(string $label): ?array
    {
        // 1) search (si ton compte l’a)
        $search = $this->req('GET', 'inventory_inputs/search/?inventory_input_label=' . urlencode($label));
        if (($search['ok'] ?? false) && is_array($search['data'])) {
            $list = (isset($search['data']['data']) && is_array($search['data']['data']))
                ? $search['data']['data']
                : $search['data'];

            if (is_array($list)) {
                foreach ($list as $row) {
                    if (($row['inventory_input_label'] ?? '') === $label) return $row;
                }
            }
        }

        // 2) fallback scan (10 pages max)
        for ($p = 1; $p <= 10; $p++) {
            $res = $this->listInventoryInputs($p);
            $arr = $res['data'] ?? [];
            if (!is_array($arr) || !$arr) break;

            foreach ($arr as $row) {
                if (($row['inventory_input_label'] ?? '') === $label) return $row;
            }
        }

        return null;
    }

    /** POST /inventory_inputs/ */
    public function createInventoryInput(int $stockId, int $supplierId, string $label, ?\DateTimeInterface $date = null): array
    {
        $date = $date ?: new \DateTimeImmutable('today');

        $opts = $this->auth();
        $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        $opts['body'] = http_build_query([
            'inventory_input_stock_id'    => $stockId,
            'inventory_input_supplier_id' => $supplierId,
            'inventory_input_date'        => $date->format('Y-m-d'),
            'inventory_input_quantity'    => 0,
            'inventory_input_amount'      => '0.00',
            'inventory_input_label'       => $label,
            'supplier_invoice_number'     => '',
            'delivery_amount'             => '0.00',
            'payment_date'                => '0000-00-00',
            'invoice_date'                => '0000-00-00',
        ]);

        $res = $this->req('POST', 'inventory_inputs/', $opts);

        if (($res['ok'] ?? false)) {
            $data = $res['data'];
            $row  = (is_array($data) && isset($data[0]) && is_array($data[0])) ? $data[0] : $data;
            $id   = (int)($row['inventory_input_id'] ?? 0);
            if ($id > 0) {
                return ['ok' => true, 'id' => $id, 'label' => $label, 'created' => true, 'row' => $row];
            }
        }

        return ['ok' => false, 'status' => $res['status'] ?? 500, 'error' => $res['raw'] ?? null, 'label' => $label];
    }

    /**
     * ✅ 1 arrivage / revendeur / jour :
     * - label auto "RACHAT dd-mm-YYYY Nom Prénom"
     * - si existe déjà => réutilise
     * - sinon => crée
     */
    public function getOrCreateDailyRachatInput(
        int $stockId,
        int $supplierId,
        string $nom,
        string $prenom,
        ?\DateTimeInterface $date = null
    ): array {
        $date  = $date ?: new \DateTimeImmutable('today');
        $label = $this->buildDailyRachatLabel($date, $nom, $prenom);

        $found = $this->findInventoryInputByLabel($label);
        if ($found && !empty($found['inventory_input_id'])) {
            return ['ok' => true, 'id' => (int)$found['inventory_input_id'], 'label' => $label, 'created' => false, 'row' => $found];
        }

        return $this->createInventoryInput($stockId, $supplierId, $label, $date);
    }

    /** (optionnel) mensuel */
    public function getOrCreateMonthlyRachatInput(int $stockId, int $supplierId, string $prefix = 'RACHAT MENSUEL'): array
    {
        $label = $this->buildMonthlyRachatLabel(new \DateTimeImmutable('today'), $prefix);

        $found = $this->findInventoryInputByLabel($label);
        if ($found && !empty($found['inventory_input_id'])) {
            return ['ok' => true, 'id' => (int)$found['inventory_input_id'], 'label' => $label, 'created' => false];
        }

        return $this->createInventoryInput($stockId, $supplierId, $label, new \DateTimeImmutable('today'));
    }

    // ===================== INVENTORY INPUT DETAILS =====================

    /**
     * Ajout d’un produit dans un arrivage (inventory_input_details/{inventoryInputId})
     * - received = qty (donc "reçu" direct)
     * - unit_price optionnel
     */
    public function addProductToInventoryInput(int $inventoryInputId, int $productId, int $qty, ?float $unitPrice = null): array
    {
        $payload = [
            'product_id' => $productId,
            'quantity'   => $qty,
            'received'   => $qty,
        ];
        if ($unitPrice !== null) {
            $payload['unit_price'] = number_format($unitPrice, 2, '.', '');
        }

        $opts = $this->auth();
        $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        $opts['body'] = http_build_query($payload);

        $url = $this->baseUrl('inventory_input_details/' . $inventoryInputId);
        $r   = $this->httpClient->request('POST', $url, $opts);

        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = [
            'method'  => 'POST',
            'url'     => $url,
            'status'  => $status,
            'headers' => $opts['headers'],
            'body'    => $opts['body'],
            'raw'     => $raw,
            'data'    => $data,
        ];
        if ($this->debug && $this->logger) {
            $this->logger->info('[HIB ADD INVENTORY DETAIL]', $this->lastDebug);
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'raw' => $raw];
    }

    /** Marquer une ligne comme reçue (= received_quantity) */
    public function receiveInventoryInputDetail(int $detailId, int $receivedQty): array
    {
        $opts = $this->auth();
        $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        $opts['body'] = http_build_query(['received_quantity' => $receivedQty]);

        $url = $this->baseUrl('inventory_input_details/' . $detailId);
        $r   = $this->httpClient->request('PUT', $url, $opts);

        return [
            'ok'     => $r->getStatusCode() >= 200 && $r->getStatusCode() < 300,
            'status' => $r->getStatusCode(),
            'raw'    => $r->getContent(false),
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

        return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'raw' => $res, 'error' => $err];
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
                'image_id'     => (string)$imageId,
            ],
        ]);

        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'raw' => $res, 'error' => $err];
    }

    public function listProductImages(int $productId): array
    {
        $url    = $this->baseUrl('products_images/?product_id=' . $productId);
        $r      = $this->httpClient->request('GET', $url, $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = ['method' => 'GET', 'url' => $url, 'status' => $status, 'raw' => $raw];

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'debug' => $this->lastDebug];
    }

    public function deleteProductImage(int $imageId): array
    {
        $url    = $this->baseUrl('products_images/' . $imageId);
        $r      = $this->httpClient->request('DELETE', $url, $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = ['method' => 'DELETE', 'url' => $url, 'status' => $status, 'raw' => $raw];

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'debug' => $this->lastDebug];
    }

    public static function inferImageIdFromName(?string $imageName): ?int
    {
        if (!$imageName) return null;
        if (preg_match('/^big_\d+-(\d+)\.(jpg|jpeg)$/i', $imageName, $m)) return (int)$m[1];
        return null;
    }

    // ===================== BARCODE / IMEI =====================

    public function trySetBarcodeFromImei(int $productId, ?string $imeiRaw): array
    {
        $imeiRaw = trim((string)$imeiRaw);

        if ($imeiRaw === '') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'empty_imei'];
        }

        $check = $this->sanitizeImei($imeiRaw);
        if (!$check['ok']) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'invalid_imei', 'error' => $check['error']];
        }

        $opts = $this->auth();
        $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        $opts['body'] = http_build_query([
            'product_attribute' => 'product_barcode',
            'product_id'        => $productId,
            'new_value'         => $check['imei'],
        ]);

        $r      = $this->httpClient->request('PUT', $this->baseUrl('products/'), $opts);
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = [
            'method' => 'PUT',
            'url'    => $this->baseUrl('products/'),
            'status' => $status,
            'raw'    => $raw,
            'sent'   => ['product_id' => $productId, 'product_attribute' => 'product_barcode', 'new_value' => $check['imei']],
            'data'   => $data,
        ];
        if ($this->debug && $this->logger) {
            $this->logger->info('[HIB UPDATE BARCODE]', $this->lastDebug);
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'raw' => $raw, 'imei' => $check['imei']];
    }

    private function sanitizeImei(string $imeiRaw): array
    {
        $imei = preg_replace('/\D+/', '', $imeiRaw ?? '');
        if ($imei === '') return ['ok' => false, 'error' => "IMEI vide"];

        $len = strlen($imei);
        if ($len !== 15) return ['ok' => false, 'error' => "IMEI invalide ($len chiffres) : attendu 15"];
        if (!$this->luhnCheck($imei)) return ['ok' => false, 'error' => "IMEI invalide : contrôle (Luhn) incorrect"];

        return ['ok' => true, 'imei' => $imei];
    }

    private function luhnCheck(string $number): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $n = (int)$number[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) $n -= 9;
            }
            $sum += $n;
            $alt = !$alt;
        }
        return ($sum % 10) === 0;
    }

  /* ===================== INVENTORY (arrivages) ===================== */

/** Normalise les réponses Hiboutik qui parfois sont ["data" => [...]] */
private function normalizeList($data): array
{
    if (!is_array($data)) return [];
    if (isset($data['data']) && is_array($data['data'])) return $data['data'];
    return $data;
}

/** Récupère toutes les pages (jusqu’à $maxPages) et filtre par prefix */
public function listInventoryInputsByPrefix(string $prefix, int $maxPages = 10): array
{
    $out = [];

    for ($p = 1; $p <= $maxPages; $p++) {
        $res = $this->listInventoryInputs($p);
        $list = $this->normalizeList($res['data'] ?? []);

        if (!$list) break;

        foreach ($list as $row) {
            $label = (string)($row['inventory_input_label'] ?? '');
            if ($label !== '' && stripos($label, $prefix) === 0) {
                // cast safe pour routes Twig
                $row['inventory_input_id'] = (int)($row['inventory_input_id'] ?? 0);
                $out[] = $row;
            }
        }
    }

    return $out;
}

/**
 * ✅ TON INDEX ARRIVAGES : renvoie DIRECTEMENT un tableau de lignes
 * (pas de ['ok'=>..., 'data'=>...] sinon Twig casse)
 */
public function listRachatInputs(int $maxPages = 10): array
{
    return $this->listInventoryInputsByPrefix('RACHAT', $maxPages);
}

/** Pour ton bouton “Créer l’arrivage du mois” */
public function listMonthlyRachatInputs(int $maxPages = 10): array
{
    return $this->listInventoryInputsByPrefix('RACHAT MENSUEL', $maxPages);
}

/** Alias compatible avec ton controller actuel */
public function ensureDailyRachatInput(
    int $stockId,
    int $supplierId,
    string $nom,
    string $prenom,
    bool $isMulti = false,
    ?\DateTimeInterface $date = null
): array {
    // tu veux un label EXACT -> on ignore $isMulti
    return $this->getOrCreateDailyRachatInput($stockId, $supplierId, $nom, $prenom, $date);
}

/**
 * ✅ Détails d’un arrivage
 * Selon les comptes Hiboutik, l’endpoint peut varier, donc on tente 2 formats.
 */
public function listInventoryInputDetails(int $inventoryInputId): array
{
    if ($inventoryInputId <= 0) {
        return ['ok' => false, 'status' => 400, 'data' => [], 'error' => 'Invalid inventoryInputId'];
    }

    // Format courant: /inventory_input_details/{id}
    $res = $this->req('GET', 'inventory_input_details/' . $inventoryInputId);
    if (($res['ok'] ?? false)) {
        $res['data'] = $this->normalizeList($res['data'] ?? []);
        return $res;
    }

    // Fallback: /inventory_input_details/?inventory_input_id={id}
    $res2 = $this->req('GET', 'inventory_input_details/?inventory_input_id=' . $inventoryInputId);
    $res2['data'] = $this->normalizeList($res2['data'] ?? []);
    return $res2;
}

/**
 * ✅ Validation arrivage
 * Même logique : selon compte Hiboutik, 2 routes possibles.
 */
public function validateInventoryInput(int $inventoryInputId): array
{
    if ($inventoryInputId <= 0) {
        return ['ok' => false, 'status' => 400, 'data' => [], 'error' => 'Invalid inventoryInputId'];
    }

    // Tentative 1
    $res = $this->req('POST', 'inventory_inputs/validate/' . $inventoryInputId);
    if (($res['ok'] ?? false)) return $res;

    // Tentative 2
    return $this->req('POST', 'inventory_inputs/' . $inventoryInputId . '/validate/');
}



}
