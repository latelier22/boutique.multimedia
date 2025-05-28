<?php
// src/Entity/Channel/Channel.php

namespace App\Entity\Channel;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\Channel as BaseChannel;

/**
 * @ORM\Entity
 * @ORM\Table(name="sylius_channel")
 */
class Channel extends BaseChannel
{
    // Vous héritez de tout de BaseChannel,
    // et n’avez plus de code lié au VendorPlugin ici.
}
