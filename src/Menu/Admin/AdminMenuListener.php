<?php

declare(strict_types=1);

namespace App\Menu\Admin;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function __construct(
        private readonly array $hiboutikAdminNavigation,
    ) {
    }

    public function addAdminMenuItems(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $atelier = $menu->getChild('atelier');

        if (null === $atelier) {
            $atelier = $menu
                ->addChild('atelier')
                ->setLabel('HIBOUTIK')
                ->setLabelAttribute('icon', 'settings');
        }

        $items = $this->hiboutikAdminNavigation;
        usort($items, static fn (array $a, array $b): int => ($a['position'] ?? 9999) <=> ($b['position'] ?? 9999));

        foreach ($items as $item) {
            $this->addIfMissing(
                $atelier,
                $item['key'],
                $item['menu_label'] ?? $item['title'],
                $item['route'],
                $item['sidebar_icon'] ?? 'circle'
            );
        }

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
            ->setLabelAttribute('icon', $icon);
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