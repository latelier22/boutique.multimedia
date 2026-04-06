<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class HiboutikReferentialService
{
    public function __construct(
        private HiboutikClient $hib,
        private CacheInterface $cache,
    ) {}

    public function clearCache(): void
    {
        $this->cache->delete('hib_ref_brands_rows');
        $this->cache->delete('hib_ref_categories_rows');
        $this->cache->delete('hib_ref_category_choices');
    }

    public function getBrandsRows(): array
    {
        return $this->cache->get('hib_ref_brands_rows', function (ItemInterface $item) {
            $item->expiresAfter(3600);

            try {
                if (method_exists($this->hib, 'listBrands')) {
                    $res = $this->hib->listBrands();
                    if (($res['ok'] ?? false) && is_array($res['data'] ?? null)) {
                        return $res['data'];
                    }
                }

                if (method_exists($this->hib, 'getBrands')) {
                    $rows = $this->hib->getBrands();
                    if (is_array($rows)) {
                        return $rows;
                    }
                }
            } catch (\Throwable) {
            }

            return [];
        });
    }

    public function getCategoriesRows(): array
    {
        return $this->cache->get('hib_ref_categories_rows', function (ItemInterface $item) {
            $item->expiresAfter(3600);

            try {
                if (method_exists($this->hib, 'listCategories')) {
                    $res = $this->hib->listCategories();
                    if (($res['ok'] ?? false) && is_array($res['data'] ?? null)) {
                        return $res['data'];
                    }
                }

                if (method_exists($this->hib, 'getCategories')) {
                    $rows = $this->hib->getCategories();
                    if (is_array($rows)) {
                        return $rows;
                    }
                }
            } catch (\Throwable) {
            }

            return [];
        });
    }

    public function buildBrandChoices(): array
    {
        $choices = [];

        foreach ($this->getBrandsRows() as $row) {
            $id = (int)($row['brand_id'] ?? $row['id'] ?? 0);
            $label = trim((string)($row['brand_name'] ?? $row['name'] ?? ''));

            if ($id <= 0 || $label === '') {
                continue;
            }

            $choices[$label] = (string)$id;
        }

        ksort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        return $choices;
    }

    public function normalizeBrandName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = str_replace(['&', '+'], ' and ', $name);
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $name = preg_replace('/[^a-z0-9]+/', '', (string)$name);

        return (string)$name;
    }

    public function resolveOrCreateBrandId(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Nom de marque vide.');
        }

        $targetNorm = $this->normalizeBrandName($name);
        $brands = $this->getBrandsRows();

        foreach ($brands as $b) {
            $bid = (int)($b['brand_id'] ?? 0);
            $bname = trim((string)($b['brand_name'] ?? $b['name'] ?? ''));

            if ($bid > 0 && $this->normalizeBrandName($bname) === $targetNorm) {
                return $bid;
            }
        }

        $maxPosition = 0;
        foreach ($brands as $b) {
            $pos = (int)($b['brand_position'] ?? 0);
            if ($pos > $maxPosition) {
                $maxPosition = $pos;
            }
        }

        $res = $this->hib->createBrand([
            'brand_name' => $name,
            'brand_enabled' => 1,
            'brand_enabled_www' => 0,
            'brand_position' => $maxPosition + 1,
        ]);

        if (!($res['ok'] ?? false)) {
            throw new \RuntimeException('Impossible de créer la marque Hiboutik.');
        }

        $brandId = (int)($res['data']['brand_id'] ?? $res['brand_id'] ?? 0);

        $this->clearCache();

        if ($brandId > 0) {
            return $brandId;
        }

        foreach ($this->getBrandsRows() as $b) {
            $bid = (int)($b['brand_id'] ?? 0);
            $bname = trim((string)($b['brand_name'] ?? $b['name'] ?? ''));

            if ($bid > 0 && $this->normalizeBrandName($bname) === $targetNorm) {
                return $bid;
            }
        }

        throw new \RuntimeException('Marque créée mais identifiant introuvable.');
    }

    public function buildCategoryTree(): array
    {
        $categories = $this->getCategoriesRows();

        $idMap = [];
        foreach ($categories as $c) {
            $id = (int)($c['category_id'] ?? 0);
            if ($id > 0) {
                $idMap[$id] = true;
            }
        }

        $childrenByParent = [];
        foreach ($categories as $c) {
            $pid = (int)($c['category_id_parent'] ?? 0);
            $childrenByParent[$pid] ??= [];
            $childrenByParent[$pid][] = $c;
        }

        foreach ($childrenByParent as &$kids) {
            usort($kids, function ($a, $b) {
                $pa = (int)($a['category_position'] ?? 0);
                $pb = (int)($b['category_position'] ?? 0);

                if ($pa !== $pb) {
                    return $pa <=> $pb;
                }

                return strcmp(
                    (string)($a['category_name'] ?? ''),
                    (string)($b['category_name'] ?? '')
                );
            });
        }
        unset($kids);

        $roots = [];
        foreach ($categories as $c) {
            $pid = (int)($c['category_id_parent'] ?? 0);
            if ($pid === 0 || !isset($idMap[$pid])) {
                $roots[] = $c;
            }
        }

        return [
            'categories' => $categories,
            'roots' => $roots,
            'childrenByParent' => $childrenByParent,
        ];
    }

    public function buildCategoryChoicesAndDisabled(): array
    {
        return $this->cache->get('hib_ref_category_choices', function (ItemInterface $item) {
            $item->expiresAfter(3600);

            $categories = $this->getCategoriesRows();

            $byId = [];
            $childrenByParent = [];

            foreach ($categories as $c) {
                $id = (int)($c['category_id'] ?? 0);
                if ($id > 0) {
                    $byId[$id] = $c;
                }
            }

            foreach ($categories as $c) {
                $pid = (int)($c['category_id_parent'] ?? 0);
                $childrenByParent[$pid] ??= [];
                $childrenByParent[$pid][] = $c;
            }

            foreach ($childrenByParent as &$kids) {
                usort($kids, function ($a, $b) {
                    $pa = (int)($a['category_position'] ?? 0);
                    $pb = (int)($b['category_position'] ?? 0);

                    if ($pa !== $pb) {
                        return $pa <=> $pb;
                    }

                    return strcmp(
                        (string)($a['category_name'] ?? ''),
                        (string)($b['category_name'] ?? '')
                    );
                });
            }
            unset($kids);

            $roots = [];
            foreach ($categories as $c) {
                $pid = (int)($c['category_id_parent'] ?? 0);
                if ($pid === 0 || !isset($byId[$pid])) {
                    $roots[] = $c;
                }
            }

            $options = [];

            $walk = function (array $nodes, array $parents = []) use (&$walk, &$options, $childrenByParent) {
                foreach ($nodes as $c) {
                    $id = (int)($c['category_id'] ?? 0);
                    $name = trim((string)($c['category_name'] ?? ''));

                    if ($id <= 0 || $name === '') {
                        continue;
                    }

                    $children = $childrenByParent[$id] ?? [];
                    $hasChildren = count($children) > 0;

                    $path = implode(' › ', array_merge($parents, [$name]));

                    $options[] = [
                        'id' => $id,
                        'label' => $path,
                        'selectable' => !$hasChildren,
                        'is_parent' => $hasChildren,
                    ];

                    if ($hasChildren) {
                        $walk($children, array_merge($parents, [$name]));
                    }
                }
            };

            $walk($roots, []);

            $choices = [];
            $disabled = [];

            foreach ($options as $opt) {
                $label = $opt['is_parent']
                    ? '[Parent] ' . $opt['label']
                    : $opt['label'];

                $value = (string)$opt['id'];

                $choices[$label] = $value;
                $disabled[$value] = !$opt['selectable'];
            }

            return [
                'choices' => $choices,
                'disabled' => $disabled,
            ];
        });
    }

    public function createCategory(string $name, ?int $parentId = null): array
{
    $name = trim($name);
    if ($name === '') {
        throw new \RuntimeException('Nom de catégorie vide.');
    }

    $siblings = [];
    foreach ($this->getCategoriesRows() as $row) {
        $pid = (int)($row['category_id_parent'] ?? 0);
        if ($pid === (int)($parentId ?? 0)) {
            $siblings[] = $row;
        }
    }

    $maxPosition = 0;
    foreach ($siblings as $row) {
        $pos = (int)($row['category_position'] ?? 0);
        if ($pos > $maxPosition) {
            $maxPosition = $pos;
        }
    }

    $payload = [
        'category_name' => $name,
        'category_parent_id' => (int)($parentId ?? 0),
        'category_enabled' => 1,
        'category_bck_color' => '#FF8C00',
        'category_color' => '#FFFFFF',
        'category_ref_ext' => '',
    ];

    $res = $this->hib->createCategory($payload);

    if (!($res['ok'] ?? false)) {
        throw new \RuntimeException('Impossible de créer la catégorie Hiboutik.');
    }

    $categoryId = (int)($res['data']['category_id'] ?? $res['category_id'] ?? 0);

    if ($categoryId <= 0) {
        throw new \RuntimeException('Catégorie créée mais identifiant introuvable.');
    }

    $this->clearCache();

    foreach ($this->getCategoriesRows() as $row) {
        $id = (int)($row['category_id'] ?? 0);
        if ($id !== $categoryId) {
            continue;
        }

        return [
            'id' => $categoryId,
            'name' => trim((string)($row['category_name'] ?? $name)),
            'parent_id' => (int)($row['category_id_parent'] ?? 0),
        ];
    }

    return [
        'id' => $categoryId,
        'name' => $name,
        'parent_id' => (int)($parentId ?? 0),
    ];
}
}