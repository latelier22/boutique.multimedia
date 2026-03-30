<?php

declare(strict_types=1);

namespace App\Menu\Admin;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function addAdminMenuItems(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $atelier = $menu->getChild('atelier');

        if (null === $atelier) {
            $atelier = $menu
                ->addChild('atelier')
                ->setLabel('HIBOUTIK')
                ->setLabelAttribute('icon', 'settings')
            ;
        }

        $this->addIfMissing($atelier, 'rachats', 'Rachats', 'admin_rachats_index', 'mobile alternate');
        $this->addIfMissing($atelier, 'caisse', 'Caisse', 'admin_till_index', 'money bill alternate');
        $this->addIfMissing($atelier, 'export_comptable', 'Export comptable', 'admin_dashboard_rachats_index', 'file excel');
        $this->addIfMissing($atelier, 'arrivages', 'Arrivages', 'admin_arrivages_index', 'boxes');
        $this->addIfMissing($atelier, 'vendeurs', 'Vendeurs', 'admin_vendors_index', 'user');
        $this->addIfMissing($atelier, 'produits_hiboutik', 'Produits Hiboutik', 'admin_hiboutik_product_index', 'mobile');
        $this->addIfMissing($atelier, 'composeur_etiquettes', 'Composeur étiquettes', 'admin_hiboutik_product_labels_builder', 'tags');
        $this->addIfMissing($atelier, 'clients_hiboutik', 'Clients Hiboutik', 'admin_hiboutik_customer_index', 'users');
        $this->addIfMissing($atelier, 'interventions', 'Interventions', 'app_admin_intervention_index', 'wrench');
        $this->addIfMissing($atelier, 'boites', 'Boîtes', 'admin_boite_index', 'archive');
        $this->addIfMissing($atelier, 'ventes_interventions', 'Ventes (interventions)', 'app_admin_intervention_sales_index', 'euro sign');

        $this->moveChildFirst($menu, 'atelier');
    }

    private function addIfMissing(
        $menu,
        string $key,
        string $label,
        string $route,
        string $icon
    ): void {
        if (null !== $menu->getChild($key)) {
            return;
        }

        $menu
            ->addChild($key, ['route' => $route])
            ->setLabel($label)
            ->setLabelAttribute('icon', $icon)
        ;
    }

    private function moveChildFirst($menu, string $childName): void
    {
        $children = array_keys($menu->getChildren());

        if (!in_array($childName, $children, true)) {
            return;
        }

        $children = array_values(array_filter(
            $children,
            static fn (string $name): bool => $name !== $childName
        ));

        array_unshift($children, $childName);

        $menu->reorderChildren($children);
    }
}