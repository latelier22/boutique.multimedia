<?php

declare(strict_types=1);

namespace App\Menu\Admin;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Knp\Menu\ItemInterface;

final class HiboutikMenuListener
{
    public function addAdminMenuItems(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        // On veut le mettre tout en haut
        /** @var ItemInterface $root */
        $root = $menu;

        // Création du parent HIBOUTIK
        $hiboutik = $root
            ->addChild('hiboutik')
            ->setLabel('HIBOUTIK')
            ->setLabelAttribute('icon', 'linkify'); // icône Semantic UI

        // Enfant : Rachats
        $hiboutik
            ->addChild('rachats', [
                'route' => 'admin_rachats_index',
            ])
            ->setLabel('Rachats')
            ->setLabelAttribute('icon', 'clipboard list');

        // Enfant : Créer
        $hiboutik
            ->addChild('rachats_create', [
                'route' => 'admin_rachats_create',
            ])
            ->setLabel('Créer')
            ->setLabelAttribute('icon', 'plus circle');
    }
}
