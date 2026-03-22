<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CacheApiClient
{
    public function __construct(
    private HttpClientInterface $http,
    private string $baseUrl
) {}

    public function getProducts(array $params = []): array
{
    $url = $this->baseUrl . '/api/products?' . http_build_query($params);

    $r = $this->http->request('GET', $url);

    $raw = $r->getContent(false);

    // 🔥 DEBUG TEMPORAIRE
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


public function refreshProduct(int $productId): void
{
    try {
        $this->httpClient->request(
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

}