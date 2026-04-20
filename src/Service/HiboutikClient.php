<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\File\File;

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
        private CacheApiClient $cacheApi,
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

protected function reqWithHeaders(string $method, string $path, array $options = []): array
{
    $url = $this->baseUrl($path);
    $r   = $this->httpClient->request($method, $url, $this->auth($options));
    $status  = $r->getStatusCode();
    $raw     = $r->getContent(false);
    $data    = json_decode($raw, true);
    $headers = $r->getHeaders(false); // ✅ important

    $this->lastDebug = [
        'url' => $url, 'method' => $method, 'opts' => $options,
        'status' => $status, 'raw' => $raw, 'data' => $data,
        'headers' => $headers,
    ];

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $data,
        'raw' => $raw,
        'headers' => $headers,
    ];
}

private function parseTotalFromContentRange(array $headers): ?int
{
    // Symfony renvoie souvent ['content-range' => ['items 0-249/1327']]
    $cr = $headers['content-range'][0] ?? $headers['Content-Range'][0] ?? null;
    if (!$cr) return null;

    if (preg_match('~\/(\d+)\s*$~', $cr, $m)) {
        return (int)$m[1];
    }
    return null;
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


public function getProductsAll(int $maxPages = 500): array
{
    $all = [];
    $total = null;

    for ($p = 1; $p <= $maxPages; $p++) {
        $res = $this->reqWithHeaders('GET', 'products/?p=' . $p);
        if (!($res['ok'] ?? false)) break;

        $page = $res['data'] ?? [];

        // wrapper éventuel
        if (is_array($page) && !array_is_list($page)) {
            foreach (['products','data','items','result'] as $k) {
                if (isset($page[$k]) && is_array($page[$k])) { $page = $page[$k]; break; }
            }
        }

        if (!is_array($page) || !$page) break;

        $all = array_merge($all, $page);

        // total via Content-Range si dispo
        if ($total === null) {
            $t = $this->parseTotalFromContentRange($res['headers'] ?? []);
            if ($t) $total = $t;
        }

        // stop conditions
        if ($total !== null && count($all) >= $total) break;
        if (count($page) < 250) break; // fallback
    }

    return $all;
}

public function listCategories(): array
{
    $r = $this->httpClient->request('GET', $this->baseUrl('categories/'), $this->auth());
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = [
        'method' => 'GET',
        'url'    => $this->baseUrl('categories/'),
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

public function listBrands(): array
{
    // ⚠️ endpoint à valider chez toi : souvent "brands/" ou parfois "manufacturers/"
    $r = $this->httpClient->request('GET', $this->baseUrl('brands/'), $this->auth());
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

   

    $this->lastDebug = [
        'method' => 'GET',
        'url'    => $this->baseUrl('brands/'),
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

  public function getProducts(): array
{
    $url    = $this->baseUrl('products/');
    $r      = $this->httpClient->request('GET', $url, $this->auth());
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = ['method' => 'GET', 'url' => $url, 'status' => $status, 'raw' => $raw];

    // Cas 1: l’API renvoie directement une liste: [ {...}, {...} ]
    if (is_array($data) && array_is_list($data)) {
        return $data;
    }

    // Cas 2: l’API renvoie un wrapper: { "products": [ ... ] } (ou autre clé)
    if (is_array($data)) {
        foreach (['products', 'data', 'items', 'result'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                return $data[$k];
            }
        }
    }

    return [];
}

 public function getCategories(): array
{
    $url    = $this->baseUrl('categories/');
    $r      = $this->httpClient->request('GET', $url, $this->auth());
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = ['method' => 'GET', 'url' => $url, 'status' => $status, 'raw' => $raw];

    // Cas 1: l’API renvoie directement une liste: [ {...}, {...} ]
    if (is_array($data) && array_is_list($data)) {
        return $data;
    }

    // Cas 2: l’API renvoie un wrapper: { "products": [ ... ] } (ou autre clé)
    if (is_array($data)) {
        foreach (['categories', 'data', 'items', 'result'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                return $data[$k];
            }
        }
    }

    return [];
}

public function getCategory(int $id): array
{
    $url = $this->baseUrl('categories/' . $id);
    $r   = $this->httpClient->request('GET', $url, $this->auth());
    $raw = $r->getContent(false);
    $d   = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

public function setCategoryAttribute(int $categoryId, string $attribute, int|string $newValue): array
{
    $url = $this->baseUrl('categories'); // PAS de slash final

    // on passe tout dans auth($extra) comme tu faisais avant (simple, stable)
    $r = $this->httpClient->request('PUT', $url, $this->auth([
        'headers' => [
            'accept' => '*/*',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'body' => [
            'category_id' => (string)$categoryId,
            'category_attribute' => $attribute,
            'new_value' => (string)$newValue,
        ],
    ]));

    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);

    $this->lastDebug = ['method' => 'PUT', 'url' => $url, 'status' => $status, 'raw' => $raw];

    return [
        'status' => $status,
        'raw' => $raw,
    ];
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
        'store_id'      => (int)($s['store_id'] ?? 1),
        'stock_id'      => (int)($s['stock_id'] ?? $s['store_id'] ?? 1),
        'currency_code' => (string)($s['store_default_currency'] ?? 'EUR'),
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
   public function buildDailyRachatLabel(\DateTimeInterface $d, int $supplierId, string $nom, string $prenom, bool $isMulti=false): string
{
    $date = $d->format('d-m-Y');
    $nom = trim($nom);
    $prenom = trim($prenom);

    $suffix = $isMulti ? ' (MULTI)' : '';
    return trim(sprintf('RACHAT %s SUP#%d %s %s%s', $date, $supplierId, $nom, $prenom, $suffix));
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
   public function createInventoryInput(int $stockId, int $supplierId, string $label): array
{
    $opts = $this->auth();
    $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';

    // ✅ Hiboutik attend stock_id / supplier_id / label
    $payload = [
        'stock_id'    => (string)$stockId,
        'supplier_id' => (string)$supplierId,
        'label'       => (string)$label,
    ];

    $opts['body'] = http_build_query($payload);

    // ✅ endpoint sans slash final = souvent plus stable
    $res = $this->req('POST', 'inventory_inputs', $opts);

    // 🔥 DEBUG si fail
    if (!($res['ok'] ?? false)) {
        $res['debug_payload'] = $payload;
        $res['hib_last'] = $this->getLastDebug();
    }

    // si OK, Hiboutik renvoie parfois [ { inventory_input_id: ... } ]
    if (($res['ok'] ?? false)) {
        $data = $res['data'] ?? null;
        $row  = (is_array($data) && isset($data[0]) && is_array($data[0])) ? $data[0] : (is_array($data) ? $data : []);
        $id   = (int)($row['inventory_input_id'] ?? 0);

        return ['ok' => true, 'id' => $id, 'label' => $label, 'row' => $row];
    }

    return ['ok' => false, 'status' => $res['status'] ?? 500, 'raw' => $res['raw'] ?? null, 'label' => $label];
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
    bool $isMulti = false,
    ?\DateTimeInterface $date = null
): array {
    $date  = $date ?: new \DateTimeImmutable('today');
    $label = $this->buildDailyRachatLabel($date, $supplierId, $nom, $prenom, $isMulti);

    $found = $this->findInventoryInputByLabel($label);
    if ($found && !empty($found['inventory_input_id'])) {
        return ['ok' => true, 'id' => (int)$found['inventory_input_id'], 'label' => $label, 'created' => false, 'row' => $found];
    }

    return $this->createInventoryInput($stockId, $supplierId, $label, $date);
}

    // /** (optionnel) mensuel */
    // public function getOrCreateMonthlyRachatInput(int $stockId, int $supplierId, string $prefix = 'RACHAT MENSUEL'): array
    // {
    //     $label = $this->buildMonthlyRachatLabel(new \DateTimeImmutable('today'), $prefix);

    //     $found = $this->findInventoryInputByLabel($label);
    //     if ($found && !empty($found['inventory_input_id'])) {
    //         return ['ok' => true, 'id' => (int)$found['inventory_input_id'], 'label' => $label, 'created' => false];
    //     }

    //     return $this->createInventoryInput($stockId, $supplierId, $label, new \DateTimeImmutable('today'));
    // }

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
public function uploadProductImage(
    int $productId,
    string $path,
    int $imageId = 1,
    string $originalName = 'image.jpg'
): array
{
    $url = sprintf(
        'https://%s.hiboutik.com/api/products_images_1000x1000/%d',
        $this->hibAccount,
        $productId
    );

    // 🔥 MIME correct
    $mime = mime_content_type($path) ?: 'image/jpeg';

    // 🔥 NOM AVEC EXTENSION (CRITIQUE)
    if (!str_contains($originalName, '.')) {
        $originalName .= '.jpg';
    }

    $file = new \CURLFile(
        $path,
        $mime,
        $originalName
    );

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $this->hibLogin . ':' . $this->hibApiKey,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'accept: */*'
        ],
        CURLOPT_POSTFIELDS => [
            'image' => $file,
            'framing_type' => 'default',
            'image_id' => (string)$imageId,
        ],
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'raw' => $response,
        'error' => $error,
        'filename' => $originalName,
        'mime' => $mime,
    ];
}


public function deleteProductImageByName(string $imageName): array
{
    $url = sprintf(
        'https://%s.hiboutik.com/api/products_images/%s',
        $this->hibAccount,
        $imageName
    );

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_USERPWD => $this->hibLogin . ':' . $this->hibApiKey,
        CURLOPT_HTTPHEADER => [
            'accept: */*'
        ],
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'raw' => $response,
        'error' => $error,
    ];
}




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

public function uploadProductWeb1000(int $productId, string $path, int $imageId = 1): array
{
    $url = sprintf(
        'https://%s.hiboutik.com/api/products_images_1000x1000/%d',
        $this->hibAccount,
        $productId
    );

    $mime = mime_content_type($path) ?: 'image/jpeg';
    $filename = basename($path);

    $file = new \CURLFile($path, $mime, $filename);

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $this->hibLogin . ':' . $this->hibApiKey,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'accept: */*'
        ],
        CURLOPT_POSTFIELDS => [
            'image' => $file,
            'framing_type' => 'default',
            'image_id' => (string)$imageId,
        ],
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'raw' => $response,
        'error' => $error,
        'file' => $path,
        'mime' => $mime,
    ];
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

// ===================== BARCODE / IDENTIFIANT =====================

public function trySetBarcodeSmart(int $productId, ?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['ok' => true, 'skipped' => true, 'reason' => 'empty'];
    }

    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return ['ok' => true, 'skipped' => true, 'reason' => 'no_digits'];
    }

    $kind = $this->classifyIdentifier($digits);

    // ✅ règle simple : on ne pousse dans product_barcode que si c'est EAN/UPC (scannable)
    if ($kind !== 'ean') {
        // -> IMEI ou serial : on ne met pas dans barcode
        return ['ok' => true, 'skipped' => true, 'reason' => 'not_barcode', 'kind' => $kind, 'value' => $digits];
    }

    // Ici on a un EAN/UPC (8/12/13/14) validé
    return $this->putProductAttribute($productId, 'product_barcode', $digits) + ['kind' => $kind, 'barcode' => $digits];
}

/**
 * Retourne:
 * - 'ean'  : EAN/UPC valide (8/12/13/14 + checksum)
 * - 'imei' : IMEI valide (15 + Luhn) (on choisit de ne PAS l'envoyer en barcode)
 * - 'serial' : autre (on skip)
 */
private function classifyIdentifier(string $digits): string
{
    $len = strlen($digits);

    // IMEI = 15 + Luhn
    if ($len === 15 && $this->luhnCheck($digits)) {
        return 'imei';
    }

    // EAN/UPC = 8/12/13/14 + checksum GS1
    if (in_array($len, [8, 12, 13, 14], true) && $this->gs1CheckDigitOk($digits)) {
        return 'ean';
    }

    return 'serial';
}

/** checksum EAN/UPC (GS1) */
private function gs1CheckDigitOk(string $digits): bool
{
    $len = strlen($digits);
    if ($len < 2) return false;

    $check = (int)substr($digits, -1);
    $body  = substr($digits, 0, -1);

    $sum = 0;
    // en partant de la droite, poids 3/1 alternés
    $rev = strrev($body);
    for ($i = 0; $i < strlen($rev); $i++) {
        $n = (int)$rev[$i];
        $sum += ($i % 2 === 0) ? $n * 3 : $n;
    }

    $calc = (10 - ($sum % 10)) % 10;
    return $calc === $check;
}


public function putProductAttributeSingle(int $productId, string $attr, string $value): array
{
    $opts = $this->auth();
    $opts['headers']['Content-Type'] = 'application/json';
    $opts['headers']['Accept'] = '*/*';

    $opts['json'] = [
        'product_attribute' => $attr,
        'new_value'         => $value,
    ];

    $endpoint = 'product/' . $productId; // ✅ singulier

    $r      = $this->httpClient->request('PUT', $this->baseUrl($endpoint), $opts);
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = [
        'method' => 'PUT',
        'url'    => $this->baseUrl($endpoint),
        'status' => $status,
        'raw'    => $raw,
        'sent'   => ['product_attribute' => $attr, 'new_value' => $value],
        'data'   => $data,
    ];
    if ($this->debug && $this->logger) {
        $this->logger->info('[HIB UPDATE PRODUCT ATTR SINGLE]', $this->lastDebug);
    }

    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'raw' => $raw];
}

/** facteur commun: PUT product_attribute */
private function putProductAttribute(int $productId, string $attr, string $value): array
{
    $opts = $this->auth();
    $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
    $opts['body'] = http_build_query([
        'product_attribute' => $attr,
        'product_id'        => $productId,
        'new_value'         => $value,
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
        'sent'   => ['product_id' => $productId, 'product_attribute' => $attr, 'new_value' => $value],
        'data'   => $data,
    ];
    if ($this->debug && $this->logger) {
        $this->logger->info('[HIB UPDATE PRODUCT ATTR]', $this->lastDebug);
    }

    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'raw' => $raw];
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

/** Récupère toutes les pages (jusqu’à $maxPages) et filtre par prefix */
public function listAllInventoryInputs(int $maxPages = 10): array
{
    $out = [];

    for ($p = 1; $p <= $maxPages; $p++) {
        $res = $this->listInventoryInputs($p);
        $list = $this->normalizeList($res['data'] ?? []);

        if (!$list) break;

        foreach ($list as $row) {
            $label = (string)($row['inventory_input_label'] ?? '');
           
                // cast safe pour routes Twig
                $row['inventory_input_id'] = (int)($row['inventory_input_id'] ?? 0);
                $out[] = $row;
           
        }
    }

    return $out;
}






// Dans App\Service\HiboutikClient

public function updateProductAttributes(int $productId, array $fields): array
{
    $last = null;
    $allOk = true;

    foreach ($fields as $attr => $val) {
        // skip null (mais garde "0")
        if ($val === null) continue;

        $val = is_bool($val) ? ($val ? '1' : '0') : (string)$val;

        $r = $this->putProductAttributeSingle($productId, (string)$attr, $val);
        $last = $r;

        if (!($r['ok'] ?? false)) {
            $allOk = false;
            break;
        }
    }

    return [
        'ok' => $allOk,
        'status' => $last['status'] ?? null,
        'raw' => $last['raw'] ?? null,
        'last' => $last,
    ];
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
    return $this->getOrCreateDailyRachatInput($stockId, $supplierId, $nom, $prenom, $isMulti, $date);
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
        return ['ok' => false, 'status' => 400, 'raw' => 'invalid_inventory_input_id'];
    }

    // ✅ EXACTEMENT comme ton curl (JSON)
    $res = $this->req('POST', 'inventory_input_validate', [
        'json' => ['inventory_input_id' => $inventoryInputId],
        'headers' => ['Accept' => '*/*'], // optionnel mais ok
    ]);

    return $res;
}

/* ===================== TAGS PRODUITS ===================== */

/**
 * GET /tags/products
 * Retourne les catégories de tags + leurs tags (tag_details)
 */
public function listProductTagCatalog(): array
{
    // d’après ta doc : /tags/products
    return $this->req('GET', 'tags/products');
}

/**
 * Construit un choix [ "CAT — TAG" => tag_id ] pour un <select>
 * + map [tag_id => "CAT — TAG"]
 */
public function buildProductTagChoices(): array
{
    $res = $this->listProductTagCatalog();
    $choices = [];
    $map = [];

    $rows = $res['data'] ?? [];
    if (!is_array($rows)) {
        $rows = [];
    }

    foreach ($rows as $cat) {
        $catName = trim((string)($cat['tag_cat'] ?? ''));
        $details = $cat['tag_details'] ?? [];
        if (!is_array($details)) {
            $details = [];
        }

        foreach ($details as $t) {
            $id = (int)($t['tag_id'] ?? 0);
            $label = trim((string)($t['tag'] ?? $t['tag_label'] ?? ''));
            if ($id <= 0 || $label === '') {
                continue;
            }

            $full = $catName !== '' ? ($catName . ' — ' . $label) : $label;
            $choices[$full] = $id;
            $map[$id] = $full;
        }
    }

    return [
        'ok' => ($res['ok'] ?? false),
        'choices' => $choices,
        'map' => $map,
        'raw' => $res,
    ];
}
/**
 * GET /products_tags/{product_id}
 * Retourne les tags d’un produit
 */
public function listTagsForProduct(int $productId): array
{
    return $this->req('GET', 'products_tags/' . $productId);
}

/**
 * POST /products_tags/{product_id}
 * Ajoute un tag à un produit
 */
public function addTagToProduct(int $productId, int $tagId): array
{
    return $this->req('POST', 'products_tags/' . $productId, [
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'body' => [
            'tag_id' => (string)$tagId
        ]
    ]);
}

/**
 * DELETE /products_tags/{product_id}/{tag_id}
 * Supprime un tag d’un produit
 */
public function deleteTagForProduct(int $productId, int $tagId): array
{
    return $this->req('DELETE', 'products_tags/' . $productId . '/' . $tagId);
}


public function setProductWWW(int $productId, int $val): array
{
    return $this->putProductAttributeSingle($productId, 'product_display_www', (string)$val);
}

public function buildGroupedProductTagCatalog(): array
{
    $res = $this->listProductTagCatalog();

    $rows = $res['data'] ?? [];
    if (!is_array($rows)) {
        $rows = [];
    }

    $groups = [];
    $map = [];

    foreach ($rows as $cat) {
        $catId = (int)($cat['tag_cat_id'] ?? 0);
        $catName = trim((string)($cat['tag_cat'] ?? ''));
        $catDesc = trim((string)($cat['tag_cat_desc'] ?? ''));
        $details = $cat['tag_details'] ?? [];

        if (!is_array($details)) {
            $details = [];
        }

        $group = [
            'id' => $catId,
            'name' => $catName,
            'desc' => $catDesc,
            'tags' => [],
        ];

        foreach ($details as $t) {
            $id = (int)($t['tag_id'] ?? 0);
            $label = trim((string)($t['tag'] ?? $t['tag_label'] ?? ''));
            $desc = trim((string)($t['tag_desc'] ?? ''));

            if ($id <= 0 || $label === '') {
                continue;
            }

            $group['tags'][] = [
                'id' => $id,
                'label' => $label,
                'desc' => $desc,
                'enabled' => (int)($t['tag_enabled'] ?? 0),
                'enabled_www' => (int)($t['tag_enabled_www'] ?? 0),
            ];

            $map[$id] = $label;
        }

        $groups[] = $group;
    }

    return [
        'ok' => ($res['ok'] ?? false),
        'groups' => $groups,
        'map' => $map,
        'raw' => $res,
    ];
}

public function getOrCreateMonthlyRachatInput(
    int $stockId,
    int $supplierId,
    ?\DateTimeInterface $date = null,
    string $prefix = 'RACHAT MENSUEL'
): array {
    $date = $date ?: new \DateTimeImmutable('today');
    $label = sprintf('%s-%02d-%04d', $prefix, (int)$date->format('m'), (int)$date->format('Y'));

    $found = $this->findInventoryInputByLabel($label);
    if ($found && !empty($found['inventory_input_id'])) {
        return [
            'ok' => true,
            'id' => (int)$found['inventory_input_id'],
            'label' => $label,
            'created' => false,
            'row' => $found,
        ];
    }

    return $this->createInventoryInput($stockId, $supplierId, $label);
}

public function createBrand(array $fields): array
{
    $res = $this->req('POST', 'brands/', [
        'json' => $fields,
    ]);

    if ($this->logger) {
        $this->logger->info('[HIB CREATE BRAND]', [
            'fields' => $fields,
            'result' => $res,
        ]);
    }

    return $res;
}

/**
 * MAJ champ par champ, comme pour suppliers/categories/products
 */
public function updateBrandAttribute(int $brandId, string $attr, string|int $value): array
{
    $opts = $this->auth();
    $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
    $opts['body'] = http_build_query([
        'brand_id'        => (string)$brandId,
        'brand_attribute' => $attr,
        'new_value'       => (string)$value,
    ]);

    $r      = $this->httpClient->request('PUT', $this->baseUrl('brands/'), $opts);
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = [
        'method' => 'PUT',
        'url'    => $this->baseUrl('brands/'),
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
        'sent'   => [
            'brand_id' => $brandId,
            'brand_attribute' => $attr,
            'new_value' => $value,
        ],
    ];

    if ($this->logger) {
        $this->logger->info('[HIB UPDATE BRAND ATTRIBUTE]', $this->lastDebug);
    }

    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
    ];
}

public function updateBrandAttributes(int $brandId, array $fields): array
{
    $results = [];
    $ok = true;
    $last = null;

    foreach ($fields as $attr => $value) {
        $r = $this->updateBrandAttribute($brandId, (string)$attr, is_bool($value) ? ($value ? '1' : '0') : (string)$value);
        $results[$attr] = $r;
        $last = $r;

        if (!($r['ok'] ?? false)) {
            $ok = false;
            break;
        }
    }

    return [
        'ok' => $ok,
        'status' => $last['status'] ?? 0,
        'raw' => $last['raw'] ?? null,
        'data' => $results,
    ];
}

/* ===================== CUSTOMERS ===================== */

public function getCustomers(): array
{
    $url    = $this->baseUrl('customers');
    $r      = $this->httpClient->request('GET', $url, $this->auth());
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = [
        'method' => 'GET',
        'url'    => $url,
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
    ];

    if (is_array($data) && array_is_list($data)) {
        return $data;
    }

    if (is_array($data)) {
        foreach (['customers', 'data', 'items', 'result'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                return $data[$k];
            }
        }
    }

    return [];
}
public function getCustomer(int $id): array
{
    $url    = $this->baseUrl('customer/' . $id);
    $r      = $this->httpClient->request('GET', $url, $this->auth());
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = [
        'method' => 'GET',
        'url'    => $url,
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
    ];


    if (is_array($data) && isset($data[0]) && is_array($data[0])) {
        return $data[0];
    }

    return is_array($data) ? $data : [];
}
public function updateCustomerAttribute(int $customerId, string $attr, string|int $value): array
{
    $url = $this->baseUrl('customer/' . $customerId);

    $opts = $this->auth([
        'headers' => [
            'Accept' => '*/*',
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'customers_attribute' => (string)$attr,
            'new_value' => (string)$value,
        ],
    ]);

    $r      = $this->httpClient->request('PUT', $url, $opts);
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = [
        'method' => 'PUT',
        'url'    => $url,
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
        'sent'   => [
            'customers_id' => $customerId,
            'customers_attribute' => $attr,
            'new_value' => $value,
        ],
    ];

    if ($this->logger) {
        $this->logger->info('[HIB UPDATE CUSTOMER ATTRIBUTE]', $this->lastDebug);
    }

    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
    ];
}

public function updateCustomerAttributes(int $customerId, array $fields): array
{
    $results = [];
    $ok = true;
    $last = null;

    foreach ($fields as $attr => $value) {
        if ($value === null) continue;

        $r = $this->updateCustomerAttribute(
            $customerId,
            (string)$attr,
            is_bool($value) ? ($value ? '1' : '0') : (string)$value
        );

        $results[$attr] = $r;
        $last = $r;

        if (!($r['ok'] ?? false)) {
            $ok = false;
            break;
        }
    }

    return [
        'ok'     => $ok,
        'status' => $last['status'] ?? 0,
        'raw'    => $last['raw'] ?? null,
        'data'   => $results,
    ];
}

/* ===================== CUSTOMER ADDRESSES ===================== */

public function getCustomerAddress(int $addressId): array
{
    $url    = $this->baseUrl('customers_addresses/' . $addressId);
    $r      = $this->httpClient->request('GET', $url, $this->auth());
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = [
        'method' => 'GET',
        'url'    => $url,
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
    ];

    if (is_array($data) && isset($data[0]) && is_array($data[0])) {
        return $data[0];
    }

    return is_array($data) ? $data : [];
}

public function updateCustomerAddressAttribute(int $addressId, string $attr, string|int $value): array
{
    $url = $this->baseUrl('customers_addresses/' . $addressId);

    $opts = $this->auth([
        'headers' => [
            'Accept' => '*/*',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'body' => http_build_query([
            'address_attribute' => (string)$attr,
            'new_value'         => (string)$value,
        ]),
    ]);

    $r      = $this->httpClient->request('PUT', $url, $opts);
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = [
        'method' => 'PUT',
        'url'    => $url,
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
        'sent'   => [
            'address_id' => $addressId,
            'address_attribute' => $attr,
            'new_value' => $value,
        ],
    ];

    if ($this->logger) {
        $this->logger->info('[HIB UPDATE CUSTOMER ADDRESS ATTRIBUTE]', $this->lastDebug);
    }

    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
    ];
}

public function updateCustomerAddressAttributes(int $addressId, array $fields): array
{
    $results = [];
    $ok = true;
    $last = null;

    foreach ($fields as $attr => $value) {
        if ($value === null) continue;

        $r = $this->updateCustomerAddressAttribute(
            $addressId,
            (string)$attr,
            is_bool($value) ? ($value ? '1' : '0') : (string)$value
        );

        $results[$attr] = $r;
        $last = $r;

        if (!($r['ok'] ?? false)) {
            $ok = false;
            break;
        }
    }

    return [
        'ok'     => $ok,
        'status' => $last['status'] ?? 0,
        'raw'    => $last['raw'] ?? null,
        'data'   => $results,
    ];
}


public function createCustomerAddress(array $fields): array
{
    $url = $this->baseUrl('customers_addresses');

    $payload = [
        'customers_id'    => (int)($fields['customers_id'] ?? 0),
        'gender'          => (string)($fields['gender'] ?? '0'),
        'first_name'      => (string)($fields['first_name'] ?? ''),
        'last_name'       => (string)($fields['last_name'] ?? ''),
        'email'           => (string)($fields['email'] ?? ''),
        'phone'           => (string)($fields['phone'] ?? ''),
        'company'         => (string)($fields['company'] ?? ''),
        'address'         => (string)($fields['address'] ?? ''),
        'zip_code'        => (string)($fields['zip_code'] ?? ''),
        'city'            => (string)($fields['city'] ?? ''),
        'state'           => (string)($fields['state'] ?? ''),
        'country'         => (string)($fields['country'] ?? ''),
        'other'           => (string)($fields['other'] ?? ''),
        'default'         => (string)($fields['default'] ?? '0'),
        'tax_number'      => (string)($fields['tax_number'] ?? ''),
        'company_number'  => (string)($fields['company_number'] ?? ''),
        'legal_status'    => (string)($fields['legal_status'] ?? ''),
    ];

    $r = $this->httpClient->request('POST', $url, $this->auth([
        'headers' => [
            'Accept' => '*/*',
            'Content-Type' => 'application/json',
        ],
        'json' => $payload,
    ]));

    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = [
        'method' => 'POST',
        'url'    => $url,
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
        'sent'   => $payload,
    ];

    if ($this->logger) {
        $this->logger->info('[HIB CREATE CUSTOMER ADDRESS]', $this->lastDebug);
    }

    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
    ];
}

public function createCustomer(array $fields): array
{
    $url = $this->baseUrl('customers');

    $payload = [
        'customers_first_name'   => (string)($fields['first_name'] ?? ''),
        'customers_last_name'    => (string)($fields['last_name'] ?? ''),
        'customers_phone_number' => (string)($fields['phone'] ?? ''),
        'customers_tax_number'   => (string)($fields['tax_number'] ?? ''),
        'customers_ref_ext'      => (string)($fields['customers_ref_ext'] ?? ''),
        'customers_country'      => (string)($fields['country'] ?? ''),
        'customers_email'        => (string)($fields['email'] ?? ''),
        'customers_company'      => (string)($fields['company'] ?? ''),
        'customers_misc'         => (string)($fields['customers_misc'] ?? ''),
        'customers_birth_date'   => (string)($fields['birth_date'] ?? ''),
    ];

    $opts = $this->auth();
    $opts['headers']['Accept'] = '*/*';
    $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
    $opts['body'] = http_build_query($payload);

    $r      = $this->httpClient->request('POST', $url, $opts);
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $customerId = (int)($data['customers_id'] ?? 0);

    $this->lastDebug = [
        'method' => 'POST',
        'url'    => $url,
        'status' => $status,
        'raw'    => $raw,
        'data'   => $data,
        'sent'   => $payload,
    ];

    if ($this->logger) {
        $this->logger->info('[HIB CREATE CUSTOMER]', $this->lastDebug);
    }

    return [
        'ok'          => $status >= 200 && $status < 300,
        'status'      => $status,
        'raw'         => $raw,
        'data'        => $data,
        'customer_id' => $customerId,
    ];
}


public function getSale(int $saleId): array
{
    $res = $this->req('GET', 'sale/' . $saleId);

    $data = $res['data'] ?? null;

    $this->lastDebug = [
        'method' => 'GET',
        'url'    => $this->baseUrl('sale/' . $saleId),
        'status' => $res['status'] ?? null,
        'raw'    => $res['raw'] ?? null,
        'data'   => $data,
    ];

    if (is_array($data) && isset($data[0]) && is_array($data[0])) {
        return $data[0];
    }

    return is_array($data) ? $data : [];
}

public function createSale(array $fields): array
{
    $payload = [
        'store_id'             => (int)($fields['store_id'] ?? 1),
        'customer_id'          => (int)($fields['customer_id'] ?? 0),
        'duty_free_sale'       => (int)($fields['duty_free_sale'] ?? 0),
        'prices_without_taxes' => (int)($fields['prices_without_taxes'] ?? 0),
        'quotation'            => (int)($fields['quotation'] ?? 0),
        'currency_code'        => (string)($fields['currency_code'] ?? 'EUR'),
    ];

    if (!empty($fields['vendor_id'])) {
        $payload['vendor_id'] = (string)$fields['vendor_id'];
    }

    $res = $this->req('POST', 'sales/', [
        'json' => $payload,
        'headers' => [
            'Accept' => '*/*',
        ],
    ]);

    $data = $res['data'] ?? null;
    $saleId = 0;

    if (is_array($data)) {
        if (isset($data['sale_id'])) {
            $saleId = (int)$data['sale_id'];
        } elseif (isset($data['id'])) {
            $saleId = (int)$data['id'];
        } elseif (isset($data[0]) && is_array($data[0])) {
            $saleId = (int)($data[0]['sale_id'] ?? $data[0]['id'] ?? 0);
        }
    }

    $this->lastDebug = [
        'method' => 'POST',
        'url'    => $this->baseUrl('sales/'),
        'sent'   => $payload,
        'status' => $res['status'] ?? null,
        'raw'    => $res['raw'] ?? null,
        'data'   => $data,
        'sale_id_detected' => $saleId,
    ];

    if ($this->logger) {
        $this->logger->info('[HIB CREATE SALE]', $this->lastDebug);
    }

    return [
        'ok'      => (bool)($res['ok'] ?? false),
        'status'  => $res['status'] ?? 0,
        'raw'     => $res['raw'] ?? null,
        'data'    => $data,
        'sale_id' => $saleId,
    ];
}
public function normalizePhone(string $phone): string
{
    $v = preg_replace('/\D+/', '', $phone) ?? '';

    if (str_starts_with($v, '0033')) {
        $v = '0' . substr($v, 4);
    } elseif (str_starts_with($v, '33')) {
        $v = '0' . substr($v, 2);
    }

    return $v;
}

private function normalizeSearchText(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');

    $replace = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ÿ' => 'y',
        '-' => '',
        '_' => '',
        ' ' => '',
        '\'' => '',
    ];

    return strtr($text, $replace);
}

public function normalizeCustomerRow(array $row): array
{
    return [
        'id' => (int)(
            $row['customers_id']
            ?? $row['customer_id']
            ?? $row['id']
            ?? 0
        ),
        'first_name' => trim((string)(
            $row['customers_first_name']
            ?? $row['first_name']
            ?? ''
        )),
        'last_name' => trim((string)(
            $row['customers_last_name']
            ?? $row['last_name']
            ?? ''
        )),
        'phone' => trim((string)(
            $row['customers_phone_number']
            ?? $row['phone']
            ?? ''
        )),
        'email' => trim((string)(
            $row['customers_email']
            ?? $row['email']
            ?? ''
        )),
        'company' => trim((string)(
            $row['customers_company']
            ?? $row['company']
            ?? ''
        )),
        'ref_ext' => trim((string)(
            $row['customers_ref_ext']
            ?? $row['ref_ext']
            ?? ''
        )),
        'raw' => $row,
    ];
}

public function searchCustomersLocal(
    ?string $phone = null,
    ?string $email = null,
    ?string $nom = null,
    ?string $prenom = null
): array {
    $customers = $this->getCustomers();

    $phoneNorm = $this->normalizePhone((string) $phone);
    $emailNorm = mb_strtolower(trim((string) $email), 'UTF-8');
    $nomNorm = $this->normalizeSearchText((string) $nom);
    $prenomNorm = $this->normalizeSearchText((string) $prenom);
    $fullNorm = trim($nomNorm . $prenomNorm);

    $results = [];

    foreach ($customers as $row) {
        if (!is_array($row)) {
            continue;
        }

        $c = $this->normalizeCustomerRow($row);

        if (($c['id'] ?? 0) <= 0) {
            continue;
        }

        $customerPhoneNorm = $this->normalizePhone((string) $c['phone']);
        $customerEmailNorm = mb_strtolower(trim((string) $c['email']), 'UTF-8');
        $customerNomNorm = $this->normalizeSearchText((string) $c['last_name']);
        $customerPrenomNorm = $this->normalizeSearchText((string) $c['first_name']);
        $customerFullNorm = trim($customerNomNorm . $customerPrenomNorm);

        $score = 0;

        if ($phoneNorm !== '' && $customerPhoneNorm !== '') {
            if ($phoneNorm === $customerPhoneNorm) {
                $score += 100;
            } elseif (
                str_contains($customerPhoneNorm, $phoneNorm) ||
                str_contains($phoneNorm, $customerPhoneNorm)
            ) {
                $score += 60;
            }
        }

        if ($emailNorm !== '' && $customerEmailNorm !== '') {
            if ($emailNorm === $customerEmailNorm) {
                $score += 100;
            } elseif (str_contains($customerEmailNorm, $emailNorm)) {
                $score += 40;
            }
        }

        if ($nomNorm !== '' && $customerNomNorm !== '') {
            if ($nomNorm === $customerNomNorm) {
                $score += 35;
            } elseif (
                str_contains($customerNomNorm, $nomNorm) ||
                str_contains($nomNorm, $customerNomNorm)
            ) {
                $score += 20;
            }
        }

        if ($prenomNorm !== '' && $customerPrenomNorm !== '') {
            if ($prenomNorm === $customerPrenomNorm) {
                $score += 25;
            } elseif (
                str_contains($customerPrenomNorm, $prenomNorm) ||
                str_contains($prenomNorm, $customerPrenomNorm)
            ) {
                $score += 15;
            }
        }

        if ($fullNorm !== '' && $customerFullNorm !== '' && str_contains($customerFullNorm, $fullNorm)) {
            $score += 20;
        }

        if ($score > 0) {
            $c['score'] = $score;
            $results[] = $c;
        }
    }

    usort($results, static function (array $a, array $b) {
        $scoreCmp = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }

        return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
    });

    $unique = [];
    foreach ($results as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) {
            $unique[$id] = $r;
        }
    }

    return array_values($unique);
}
public function findCustomerByRefExt(string $refExt): ?array
{
    $refExt = trim($refExt);
    if ($refExt === '') {
        return null;
    }

    foreach ($this->getCustomers() as $row) {
        if (!is_array($row)) {
            continue;
        }

        if (trim((string)($row['customers_ref_ext'] ?? '')) === $refExt) {
            return $this->normalizeCustomerRow($row);
        }
    }

    return null;
}

public function ensureVirtualCustomer(string $refExt = 'RACHAT_VIRTUEL'): array
{
    $existing = $this->findVirtualCustomer($refExt);

    if ($existing && !empty($existing['id'])) {
        return [
            'ok' => true,
            'created' => false,
            'customer_id' => (int)$existing['id'],
            'customer' => $existing,
        ];
    }

    $created = $this->createCustomer([
        'first_name' => 'RACHATS',
        'last_name' => 'INCOMPLETS',
        'phone' => '',
        'email' => '',
        'company' => '',
        'country' => 'FRA',
        'customers_ref_ext' => $refExt,
        'customers_misc' => 'Client virtuel pour vieux rachats incomplets',
    ]);

    $customerId = (int)($created['customer_id'] ?? 0);

    return [
        'ok' => ($created['ok'] ?? false) && $customerId > 0,
        'created' => true,
        'customer_id' => $customerId,
        'raw' => $created,
    ];
}

public function findVirtualCustomer(string $refExt = 'RACHAT_VIRTUEL'): ?array
{
    $refExt = trim($refExt);

    foreach ($this->getCustomers() as $row) {
        if (!is_array($row)) {
            continue;
        }

        $storedRef = trim((string)($row['customers_ref_ext'] ?? ''));
        $firstName = trim((string)($row['customers_first_name'] ?? ''));
        $lastName = trim((string)($row['customers_last_name'] ?? ''));

        // 1) match exact sur la nouvelle ref
        if ($storedRef === $refExt) {
            return $this->normalizeCustomerRow($row);
        }

        // 2) compatibilité avec les anciennes refs tronquées
        if ($storedRef !== '' && str_starts_with($storedRef, 'VIRTUAL_RACHAT_INCOM')) {
            return $this->normalizeCustomerRow($row);
        }

        // 3) sécurité supplémentaire sur le nom
        if (
            mb_strtolower($firstName, 'UTF-8') === 'rachats' &&
            mb_strtolower($lastName, 'UTF-8') === 'incomplets'
        ) {
            return $this->normalizeCustomerRow($row);
        }
    }

    return null;
}
public function createCategory(array $fields): array
{
    $res = $this->req('POST', 'categories', [
        'json' => $fields,
        'headers' => [
            'Accept' => '*/*',
            'Content-Type' => 'application/json',
        ],
    ]);

    if ($this->logger) {
        $this->logger->info('[HIB CREATE CATEGORY]', [
            'fields' => $fields,
            'result' => $res,
        ]);
    }

    return $res;
}


public function duplicateProductImages(int $sourceProductId, int $targetProductId): array
{
    if ($sourceProductId <= 0 || $targetProductId <= 0) {
        return [
            'ok' => false,
            'error' => 'invalid_product_id',
            'copied' => 0,
            'errors' => [],
        ];
    }

    $listRes = $this->listProductImages($sourceProductId);

    if (!($listRes['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'list_source_images_failed',
            'status' => $listRes['status'] ?? 0,
            'raw' => $listRes['raw'] ?? null,
            'copied' => 0,
            'errors' => [],
        ];
    }

    $rows = $listRes['data'] ?? [];
    if (!is_array($rows)) {
        $rows = [];
    }

    $copied = 0;
    $errors = [];

    foreach ($rows as $index => $img) {
        if (!is_array($img)) {
            continue;
        }

        $imageName = trim((string)($img['image_name'] ?? ''));
        $imageUrl  = trim((string)($img['url'] ?? ''));

        if ($imageUrl === '') {
            $errors[] = sprintf('Image #%d sans URL', $index + 1);
            continue;
        }

        $imageId = (int)($img['image_id'] ?? 0);
        if ($imageId <= 0) {
            $imageId = self::inferImageIdFromName($imageName) ?? ($index + 1);
        }

        if ($imageId <= 0) {
            $imageId = $index + 1;
        }

        $tmpFile = null;

        try {
            $response = $this->httpClient->request('GET', $imageUrl, [
                'timeout' => 20,
            ]);

            $content = $response->getContent();

            $ext = pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
            if (!$ext) {
                $ext = pathinfo($imageName, PATHINFO_EXTENSION);
            }
            if (!$ext) {
                $ext = 'jpg';
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'hib_dup_');
            if ($tmpFile === false) {
                throw new \RuntimeException('Impossible de créer un fichier temporaire');
            }

            $finalTmpFile = $tmpFile . '.' . $ext;
            if (!@rename($tmpFile, $finalTmpFile)) {
                throw new \RuntimeException('Impossible de renommer le fichier temporaire');
            }
            $tmpFile = $finalTmpFile;

            if (@file_put_contents($tmpFile, $content) === false) {
                throw new \RuntimeException('Impossible d’écrire le fichier temporaire');
            }

            $originalName = $imageName !== '' ? $imageName : ('image-' . $imageId . '.' . $ext);

            $uploadRes = $this->uploadProductImage(
                $targetProductId,
                $tmpFile,
                $imageId,
                $originalName
            );

            if (!($uploadRes['ok'] ?? false)) {
                $errors[] = sprintf(
                    'Upload image_id=%d échoué (status %s) : %s',
                    $imageId,
                    (string)($uploadRes['status'] ?? '?'),
                    (string)($uploadRes['raw'] ?? $uploadRes['error'] ?? 'erreur inconnue')
                );
                continue;
            }

            $copied++;
        } catch (\Throwable $e) {
            $errors[] = sprintf(
                'Copie image_id=%d impossible : %s',
                $imageId,
                $e->getMessage()
            );
        } finally {
            if ($tmpFile && is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    return [
        'ok' => count($errors) === 0,
        'copied' => $copied,
        'errors' => $errors,
    ];
}

public function duplicateProductTagsFromSource(array $sourceProduct, int $targetProductId): array
{
    $tagIds = [];

    foreach (($sourceProduct['tags'] ?? []) as $tag) {
        if (!is_array($tag)) {
            continue;
        }

        $tagId = (int)($tag['tag_id'] ?? 0);
        if ($tagId > 0) {
            $tagIds[$tagId] = $tagId;
        }
    }

    $added = 0;
    $errors = [];

    foreach ($tagIds as $tagId) {
        $res = $this->addTagToProduct($targetProductId, $tagId);

        if (!($res['ok'] ?? false)) {
            $errors[] = sprintf(
                'tag #%d (status %s)',
                $tagId,
                (string)($res['status'] ?? '?')
            );
            continue;
        }

        $added++;
    }

    return [
        'ok' => count($errors) === 0,
        'added' => $added,
        'errors' => $errors,
    ];
}

private function downloadRemoteFileToTemp(string $url, string $fallbackName = 'image.jpg'): array
{
    $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');

    $options = [
        'timeout' => 30,
        'headers' => [
            'Accept' => '*/*',
        ],
    ];

    // si l'image vient du domaine Hiboutik du compte, on ajoute l'auth
    if ($host === $this->hibAccount . '.hiboutik.com') {
        $options['auth_basic'] = [$this->hibLogin, $this->hibApiKey];
    }

    $response = $this->httpClient->request('GET', $url, $options);
    $status = $response->getStatusCode();

    if ($status < 200 || $status >= 300) {
        throw new \RuntimeException('Téléchargement image HTTP ' . $status);
    }

    $content = $response->getContent();

    $nameFromUrl = basename((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    $originalName = $nameFromUrl !== '' ? $nameFromUrl : $fallbackName;

    if (!str_contains($originalName, '.')) {
        $originalName .= '.jpg';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'hibdup_');
    if ($tmp === false) {
        throw new \RuntimeException('tempnam failed');
    }

    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $tmpWithExt = $tmp . ($ext ? '.' . $ext : '.jpg');

    if (!@rename($tmp, $tmpWithExt)) {
        @unlink($tmp);
        throw new \RuntimeException('rename temp file failed');
    }

    if (@file_put_contents($tmpWithExt, $content) === false) {
        @unlink($tmpWithExt);
        throw new \RuntimeException('write temp file failed');
    }

    return [
        'path' => $tmpWithExt,
        'original_name' => $originalName,
    ];
}

public function duplicateProductImagesFromSource(array $sourceProduct, int $targetProductId): array
{
    $images = $sourceProduct['images'] ?? [];

    if (!is_array($images) || !$images) {
        return [
            'ok' => true,
            'copied' => 0,
            'errors' => [],
        ];
    }

    $copied = 0;
    $errors = [];

    foreach (array_values($images) as $index => $img) {
        if (!is_array($img)) {
            continue;
        }

        $imageUrl = trim((string)(
            $img['url']
            ?? $img['image_url']
            ?? $img['src']
            ?? ''
        ));

        $imageName = trim((string)(
            $img['image_name']
            ?? basename((string)(parse_url($imageUrl, PHP_URL_PATH) ?? ''))
            ?? ''
        ));

        if ($imageUrl === '') {
            $errors[] = sprintf('image #%d sans url', $index + 1);
            continue;
        }

        $imageId = (int)($img['image_id'] ?? 0);

        if ($imageId <= 0) {
            $imageId = self::inferImageIdFromName($imageName) ?? ($index + 1);
        }

        if ($imageId <= 0) {
            $imageId = $index + 1;
        }

        $tmpPath = null;

        try {
            $tmp = $this->downloadRemoteFileToTemp(
                $imageUrl,
                $imageName !== '' ? $imageName : ('image-' . $imageId . '.jpg')
            );

            $tmpPath = $tmp['path'];
            $originalName = $tmp['original_name'];

            $upload = $this->uploadProductImage(
                $targetProductId,
                $tmpPath,
                $imageId,
                $originalName
            );

            if (!($upload['ok'] ?? false)) {
                $errors[] = sprintf(
                    'upload image_id=%d status=%s raw=%s error=%s',
                    $imageId,
                    (string)($upload['status'] ?? '?'),
                    (string)($upload['raw'] ?? ''),
                    (string)($upload['error'] ?? '')
                );
                continue;
            }

            $copied++;
        } catch (\Throwable $e) {
            $errors[] = sprintf(
                'copy image_id=%d failed: %s',
                $imageId,
                $e->getMessage()
            );
        } finally {
            if ($tmpPath && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    return [
        'ok' => count($errors) === 0,
        'copied' => $copied,
        'errors' => $errors,
    ];
}

public function clearProductBarcode(int $productId): array
{
    return $this->putProductAttribute($productId, 'product_barcode', '');
}


public function downloadProductImageBySlot(int $productId, int $slot): ?array
{
    $slot = max(1, min(4, $slot));

    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $url = sprintf(
            'https://%s.hiboutik.com/api/products_images/big_%d-%d.%s',
            $this->hibAccount,
            $productId,
            $slot,
            $ext
        );

        $r = $this->httpClient->request('GET', $url, $this->auth([
            'headers' => [
                'Accept' => '*/*',
            ],
        ]));

        $status = $r->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $headers = $r->getHeaders(false);

            return [
                'content' => $r->getContent(),
                'content_type' => $headers['content-type'][0] ?? 'image/jpeg',
                'url' => $url,
            ];
        }
    }

    return null;
}


}
