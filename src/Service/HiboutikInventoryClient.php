<?php

namespace App\Service;

class HiboutikInventoryClient extends HiboutikClient
{
    /* ============================================================
     * === INVENTORY INPUTS / ARRIVAGES ===
     * ============================================================ */

    /** 🔹 Liste les arrivages d’une page */
    public function listInventoryInputs(int $page = 1): array
    {
        return $this->req('GET', 'inventory_inputs/?p=' . $page);
    }

    /** 🔹 Liste tous les arrivages sur plusieurs pages */
    public function listAllInventoryInputs(int $maxPages = 10): array
    {
        $all = [];
        for ($p = 1; $p <= $maxPages; $p++) {
            $res = $this->listInventoryInputs($p);
            $data = $res['data'] ?? [];
            if (empty($data)) break;
            $all = array_merge($all, $data);
            // dump($all);
        }
        return $all;
    }

    /** 🔹 Filtre uniquement les “RACHAT MENSUEL-” */
    public function listMonthlyRachatInputs(): array
    {
        $all = $this->listAllInventoryInputs();
        return array_values(array_filter(
            $all,
            fn($i) =>
            isset($i['inventory_input_label'])
                && str_starts_with($i['inventory_input_label'], 'RACHAT MENSUEL-')
        ));
    }
    /** 🔹 Crée un nouvel arrivage */
    public function createInventoryInput(int $stockId, ?int $supplierId, string $label): array
    {
        $body = [
            'stock_id' => $stockId,
            'label' => $label,
        ];
        if ($supplierId) $body['supplier_id'] = $supplierId;

        return $this->req('POST', 'inventory_inputs/', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query($body),
        ]);
    }

    /** 🔹 Valide un arrivage */
    public function validateInventoryInput(int $inventoryInputId): array
    {
        return $this->req('POST', 'inventory_input_validate/', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query(['inventory_input_id' => $inventoryInputId]),
        ]);
    }

    /** 🔹 Récupère les détails d’un arrivage */
    public function getInventoryInput(int $inventoryInputId): array
    {
        return $this->req('GET', "inventory_inputs/$inventoryInputId");
    }

    /** 🔹 Récupère les lignes produits */
    public function listInventoryInputDetails(int $inventoryInputId): array
    {
        return $this->req('GET', "inventory_input_details/$inventoryInputId");
    }

    /** 🔹 Crée automatiquement celui du mois si inexistant */
    public function ensureMonthlyRachatInputExists(int $stockId, int $supplierId): array
    {
        $label = 'RACHAT MENSUEL-' . (new \DateTime())->format('m-Y');
        $existants = $this->listMonthlyRachatInputs();

        foreach ($existants as $e) {
            if (($e['label'] ?? '') === $label) {
                return ['ok' => true, 'created' => false, 'data' => $e];
            }
        }

        $res = $this->createInventoryInput($stockId, $supplierId, $label);
        return ['ok' => true, 'created' => true, 'data' => $res];
    }

  



}
