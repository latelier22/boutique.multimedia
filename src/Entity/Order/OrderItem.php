<?php

declare(strict_types=1);

namespace App\Entity\Order;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\OrderItem as BaseOrderItem;
use Asdoria\SyliusConfiguratorPlugin\Model\Aware\ConfiguratorAwareInterface;
use Asdoria\SyliusConfiguratorPlugin\Model\Aware\AttributeValuesAwareInterface;
use Asdoria\SyliusConfiguratorPlugin\Traits\OrderItem\ConfiguratorTrait;
use Asdoria\SyliusConfiguratorPlugin\Traits\OrderItem\AttributeValuesTrait;

/**
 * @ORM\Entity
 * @ORM\Table(name="sylius_order_item")
 */
class OrderItem extends BaseOrderItem implements AttributeValuesAwareInterface, ConfiguratorAwareInterface
{
    use AttributeValuesTrait;
    use ConfiguratorTrait;

    public function __construct()
    {
        $this->initializeAttributeValues();
        parent::__construct();
    }
}
