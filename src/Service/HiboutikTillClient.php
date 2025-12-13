<?php

namespace App\Service;

class HiboutikTillClient extends HiboutikClient
{
    /**
     * 🔹 Liste les mouvements de caisse d’un magasin pour un mois donné
     */
    public function listTillMovements(int $storeId, int $year, int $month, bool $onlyRachat = false): array
    {
        $res = $this->req('GET', sprintf('till/%d/%d/%d', $storeId, $year, $month));
        $data = $res['data'] ?? [];

        // Si on veut filtrer seulement les retraits “RACHAT …”
        if ($onlyRachat) {
            $data = array_values(array_filter($data, function ($m) {
                $comment = strtoupper(trim($m['comments'] ?? ''));
                return str_starts_with($comment, 'RACHAT') || str_starts_with($comment, 'ACHAT');
            }));
        }

        return [
            'ok' => $res['ok'] ?? false,
            'status' => $res['status'] ?? 0,
            'data' => $data,
        ];
    }
}
