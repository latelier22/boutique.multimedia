<?php
namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

final class HiboutikClient
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
    private function req(string $method, string $path, array $options = []): array
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
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data];
    }

    public function getLastDebug(): ?array { return $this->lastDebug; }

    /** === SUPPLIERS === */

    /** Retourne directement la LISTE (tableau de fournisseurs) */
   public function listSuppliers(): array
{
    $r = $this->httpClient->request('GET', $this->baseUrl('suppliers/'), $this->auth());
    $status = $r->getStatusCode();
    $raw = $r->getContent(false);
    $data = json_decode($raw, true);
    $this->lastDebug = ['method'=>'GET','url'=>$this->baseUrl('suppliers/'),'status'=>$status,'raw'=>$raw];

    return ['ok' => $status >= 200 && $status < 300, 'status'=>$status, 'data'=>$data, 'debug'=>$this->lastDebug];
}

    public function findSupplierById(int $id): ?array
    {
        foreach ($this->listSuppliers() as $s) {
            if ((int)($s['supplier_id'] ?? 0) === $id) return $s;
        }
        return null;
    }

    public function createSupplier(array $supplier): array
    {
        // POST JSON possible côté Hiboutik pour création
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

    $r = $this->httpClient->request('PUT', $this->baseUrl('suppliers/'), $opts);
    $status = $r->getStatusCode();
    $raw = $r->getContent(false);

    $this->lastDebug = [
        'method'=>'PUT',
        'url'=>$this->baseUrl('suppliers/'),
        'status'=>$status,
        'raw'=>$raw,
        'sent'=>['attr'=>$attr,'id'=>$id,'value'=>$value],
    ];

    return ['ok'=>$status>=200 && $status<300, 'status'=>$status, 'data'=>json_decode($raw, true), 'debug'=>$this->lastDebug];
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



    /** === PRODUITS === */

    /** ⚠️ La bonne route qui marche: products/search/?product_supplier={id} */
    public function listProductsBySupplier(int $supplierId): array
{
    $url = $this->baseUrl('products/search/?product_supplier='.$supplierId);
    $r = $this->httpClient->request('GET', $url, $this->auth());
    $status = $r->getStatusCode();
    $raw = $r->getContent(false);
    $data = json_decode($raw, true);
    $this->lastDebug = ['method'=>'GET','url'=>$url,'status'=>$status,'raw'=>$raw];

    return ['ok'=>$status>=200 && $status<300, 'status'=>$status, 'data'=>$data, 'debug'=>$this->lastDebug];
}


// src/Service/HiboutikClient.php

/** Retourne le supplier dont supplier_ref_ext == $refExt, sinon null */
public function findSupplierByRefExt(string $refExt): ?array
{
    $res = $this->listSuppliers();
    $list = $res['data'] ?? [];
    foreach ($list as $s) {
        if (($s['supplier_ref_ext'] ?? '') === $refExt) return $s;
    }
    return null;
}

/** Crée un produit rachat minimaliste (non publié web) */
public function createProduct(array $payload): array
{
    // Champs utiles : product_model, product_supplier, product_supply_price, product_price, product_stock_management, product_display_www(0), product_arch(0), products_ref_ext
    $res = $this->req('POST', 'products/', ['json' => $payload]);
    return $res['data'] ?? [];
}

/** Création d’un arrivage (qty=1) — endpoint exact à valider côté Hiboutik */
public function createArrival(int $productId, float $unitPrice, int $qty = 1): array
{
    // Selon les comptes Hiboutik, c’est souvent un "purchases"/"arrivals" ou "stock" dédié.
    // On garde un wrapper ici pour brancher la route exacte.
    // Exemple plausible (à adapter si besoin) :
    $opts = $this->auth();
    $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
    $opts['body'] = http_build_query([
        'product_id'  => $productId,
        'quantity'    => $qty,
        'unit_price'  => $unitPrice,
        // 'store_id'  => 1, // si multi-magasins
    ]);
    $r = $this->httpClient->request('POST', $this->baseUrl('arrivals/'), $opts);
    return [
        'ok'    => $r->getStatusCode() >= 200 && $r->getStatusCode() < 300,
        'status'=> $r->getStatusCode(),
        'data'  => json_decode($r->getContent(false), true),
    ];
}
public function getStores(): array
{
    $r = $this->httpClient->request('GET', $this->baseUrl('stores'), $this->auth());
    $status = $r->getStatusCode();
    $raw = $r->getContent(false);
    $data = json_decode($raw, true);
    // dd($data);
    $this->lastDebug = ['method'=>'GET','url'=>$this->baseUrl('stores'),'status'=>$status,'raw'=>$raw];
    return is_array($data) ? $data : [];
}

/** Récup info store par défaut (id + devise) */
public function getDefaultStoreMeta(): array
{
    $stores = $this->getStores();
    $s = $stores[0] ?? [];
    return [
        'store_id'      => (int)($s['store_id'] ?? 1),
        'currency_code' => (string)($s['store_default_currency'] ?? 'EUR'),
    ];
}

public function tillCashOut(int $storeId, float $amount, string $currencyCode, string $comments): array
{
    $opts = $this->auth();
    $opts['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
    $opts['body'] = http_build_query([
        'store_id'      => $storeId,
        'amount'        => number_format($amount, 2, '.', ''), // ex: 120.00
        'currency_code' => $currencyCode,                      // ex: EUR
        'comments'      => $comments,                          // ex: RACHAT 218 / ...
    ]);

    $r = $this->httpClient->request('POST', $this->baseUrl('till/cash_out'), $opts);
    $status = $r->getStatusCode();
    $raw = $r->getContent(false);
    $data = json_decode($raw, true);

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

    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'debug' => $this->lastDebug];
}

// src/Service/HiboutikClient.php (ajoute ces 2 méthodes)

public function uploadProductThumb100(int $productId, string $path, string $framing = 'default', ?string $filename = null): array
{
    $filename = $filename ?: basename($path);
    $mime = function_exists('mime_content_type') ? mime_content_type($path) : 'image/jpeg';
    $url = sprintf('https://%s.hiboutik.com/api/products_images/%d', $this->hibAccount, $productId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $this->hibLogin.':'.$this->hibApiKey,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Accept: */*'],
        CURLOPT_POSTFIELDS     => [
            'image'        => new \CURLFile($path, $mime, $filename),
            'framing_type' => $framing, // default|center_zoom|frame
        ],
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['ok'=>$code>=200 && $code<300, 'status'=>$code, 'raw'=>$res, 'error'=>$err];
}

public function uploadProductWeb1000(int $productId, string $path, int $imageId = 1, string $framing = 'default', ?string $filename = null): array
{
    $filename = $filename ?: basename($path);
    $mime = function_exists('mime_content_type') ? mime_content_type($path) : 'image/jpeg';
    $url = sprintf('https://%s.hiboutik.com/api/products_images_1000x1000/%d', $this->hibAccount, $productId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $this->hibLogin.':'.$this->hibApiKey,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Accept: */*'],
        CURLOPT_POSTFIELDS     => [
            'image'        => new \CURLFile($path, $mime, $filename),
            'framing_type' => $framing,         // default|center_zoom|frame
            'image_id'     => (string)$imageId, // 1..4
        ],
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['ok'=>$code>=200 && $code<300, 'status'=>$code, 'raw'=>$res, 'error'=>$err];
}

    /** GET /api/products_images/?product_id=ID  (liste les images d’un produit) */
    public function listProductImages(int $productId): array
    {
        $url = $this->baseUrl('products_images/?product_id='.$productId);
        $r   = $this->httpClient->request('GET', $url, $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = ['method'=>'GET','url'=>$url,'status'=>$status,'raw'=>$raw];
        return ['ok'=>$status>=200 && $status<300, 'status'=>$status, 'data'=>$data, 'debug'=>$this->lastDebug];
    }

    /** DELETE /api/products_images/{image_id}  (suppression) */
    public function deleteProductImage(int $imageId): array
    {
        $url = $this->baseUrl('products_images/'.$imageId);
        $r   = $this->httpClient->request('DELETE', $url, $this->auth());
        $status = $r->getStatusCode();
        $raw    = $r->getContent(false);
        $data   = json_decode($raw, true);

        $this->lastDebug = ['method'=>'DELETE','url'=>$url,'status'=>$status,'raw'=>$raw];
        return ['ok'=>$status>=200 && $status<300, 'status'=>$status, 'data'=>$data, 'debug'=>$this->lastDebug];
    }



    public function getProduct(int $productId): array
{
    $url = $this->baseUrl('products/'.$productId);
    $r   = $this->httpClient->request('GET', $url, $this->auth());
    $status = $r->getStatusCode();
    $raw    = $r->getContent(false);
    $data   = json_decode($raw, true);

    $this->lastDebug = ['method'=>'GET','url'=>$url,'status'=>$status,'raw'=>$raw];

    // L’API renvoie un tableau d’un seul produit → on normalize
    if (is_array($data) && isset($data[0]) && is_array($data[0])) {
        return $data[0];
    }
    return is_array($data) ? $data : [];
}

/** Optionnel : tenter d’inférer image_id (1..4) depuis image_name big_{id}-{n}.jpg */
public static function inferImageIdFromName(?string $imageName): ?int
{
    if (!$imageName) return null;
    if (preg_match('/^big_\d+-(\d+)\.(jpg|jpeg)$/i', $imageName, $m)) {
        return (int)$m[1];
    }
    return null;
}

}
