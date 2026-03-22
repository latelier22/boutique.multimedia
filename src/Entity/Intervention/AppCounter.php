<?php

namespace App\Entity\Intervention;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="app_counter")
 */
class AppCounter
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=50, unique=true)
     */
    private ?string $code = null;

    /**
     * @ORM\Column(type="integer")
     */
    private int $nextValue = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getNextValue(): int
    {
        return $this->nextValue;
    }

    public function setNextValue(int $nextValue): self
    {
        $this->nextValue = $nextValue;
        return $this;
    }
}