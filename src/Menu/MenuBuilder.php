<?php

declare(strict_types=1);

namespace App\Menu;

use Knp\Menu\ItemInterface;
use Sylius\AdminUi\Knp\Menu\MenuBuilderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator(decorates: 'sylius_admin_ui.knp.menu_builder')]
final readonly class MenuBuilder implements MenuBuilderInterface
{
    public function __construct(
        #[AutowireDecorated]
        private MenuBuilderInterface $inner,
    ) {
    }

    public function createMenu(array $options): ItemInterface
    {
        $menu = $this->inner->createMenu($options);

        $this->addAtelierSubMenu($menu);

        return $menu;
    }

    private function addAtelierSubMenu(ItemInterface $menu): void
    {
        $atelier = $menu->getChild('atelier');

        if (null === $atelier) {
            $atelier = $menu
                ->addChild('atelier')
                ->setLabel('Atelier')
                ->setLabelAttribute('icon', 'tabler:tool')
                ->setExtra('always_open', true)
            ;
        }

        if (null === $atelier->getChild('interventions')) {
            $atelier
                ->addChild('interventions', [
                    'route' => 'app_admin_intervention_index',
                ])
                ->setLabel('Interventions')
                ->setLabelAttribute('icon', 'tabler:tool')
            ;
        }

        if (null === $atelier->getChild('rachats')) {
            $atelier
                ->addChild('rachats', [
                    'route' => 'app_admin_rachat_index',
                ])
                ->setLabel('Rachats')
                ->setLabelAttribute('icon', 'tabler:repeat')
            ;
        }

        if (null === $atelier->getChild('boites')) {
            $atelier
                ->addChild('boites', [
                    'route' => 'app_admin_boite_index',
                ])
                ->setLabel('Boîtes')
                ->setLabelAttribute('icon', 'tabler:archive')
            ;
        }
    }
}