<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CacheApiClient
{
            public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl,
        private string $secret
    ) {}

    public function getProducts(array $params = []): array
    {
        $url = $this->baseUrl . '/api/products?' . http_build_query($params);

        $r = $this->http->request('GET', $url);

        $raw = $r->getContent(false);

        if (!$raw) {
            dump("API VIDE", $url);
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            dump("JSON KO", $url, $raw);
            return [];
        }

        return $data['data'] ?? [];
    }

    public function getProductsByTag(int $tagId): array
    {
        $url = $this->baseUrl . '/api/productsByTag?tag_id=' . $tagId;

        $r = $this->http->request('GET', $url);
        $data = $r->toArray(false);

        return $data['data'] ?? [];
    }


    public function getProductById(int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }

    $url = rtrim($this->baseUrl, '/') . '/api/products/' . $productId;

    $r = $this->http->request('GET', $url, [
        'timeout' => 15,
    ]);

    $data = $r->toArray(false);

    if (!($data['ok'] ?? false)) {
        return null;
    }

    return is_array($data['data'] ?? null) ? $data['data'] : null;
}

    public function refreshProduct(int $productId): void
    {
        try {
            $this->http->request(
                'POST',
                $this->baseUrl . '/webhook/hiboutik?secret=' . $this->secret,
                [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'body' => http_build_query([
                        'product_id' => $productId,
                        'shop_id' => 1,
                    ]),
                ]
            );
        } catch (\Throwable $e) {
            error_log('Webhook error: ' . $e->getMessage());
        }
    }

    public function refreshDisplayMessages(?string $slot = null): void
    {
        $url = rtrim($this->baseUrl, '/') . '/webhook/display-messages?secret=' . urlencode($this->secret);
        $body = http_build_query([
            'slot' => $slot ?? '',
        ]);

        error_log('[CacheApiClient] refreshDisplayMessages START');
        error_log('[CacheApiClient] URL=' . $url);
        error_log('[CacheApiClient] BODY=' . $body);

        try {
            $response = $this->http->request(
                'POST',
                $url,
                [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'body' => $body,
                    'timeout' => 10,
                ]
            );

            $status = $response->getStatusCode();
            $content = $response->getContent(false);

            error_log('[CacheApiClient] STATUS=' . $status);
            error_log('[CacheApiClient] RESPONSE=' . $content);
        } catch (\Throwable $e) {
            error_log('[CacheApiClient] ERROR=' . $e->getMessage());
        }

        error_log('[CacheApiClient] refreshDisplayMessages END');
    }


public function searchAdminProducts(array $filters = []): array
{
    $url = rtrim($this->baseUrl, '/') . '/api/products/search-admin';

    $query = array_filter($filters, static function ($v) {
        return $v !== null && $v !== '';
    });

    $response = $this->http->request('GET', $url, [
        'query' => $query,
        'timeout' => 15,
    ]);

    $data = $response->toArray(false);

    return [
        'ok' => (bool) ($data['ok'] ?? false),
        'data' => is_array($data['data'] ?? null) ? $data['data'] : [],
        'total' => (int) ($data['total'] ?? 0),
        'count' => (int) ($data['count'] ?? 0),
        'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : [],
    ];
}


}