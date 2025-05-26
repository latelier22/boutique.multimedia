<?php
declare(strict_types=1);
namespace App\Repository;

use Asdoria\SyliusConfiguratorPlugin\Repository\Model\Aware\ProductAttributeRepositoryAwareInterface;
use Asdoria\SyliusConfiguratorPlugin\Repository\Traits\ProductAttributeRepositoryTrait;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository as BaseProductAttributeRepository;

/**
 * Class ProductAttributeRepository
 * @package App\Repository
 *
 * @author Philippe Vesin <pve.asdoria@gmail.com>
 */
class ProductAttributeRepository extends BaseProductAttributeRepository implements ProductAttributeRepositoryAwareInterface
{
    use ProductAttributeRepositoryTrait;
}
