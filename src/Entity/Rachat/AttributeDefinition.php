<?php

namespace App\Entity\Rachat;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="attributes_definitions")
 */
class AttributeDefinition
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    private string $code;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $label;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private string $type = 'text'; // text | select

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private ?string $category = null;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $options = [];

    // ===== GETTERS / SETTERS =====

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $v): self { $this->code = $v; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $v): self { $this->label = $v; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $v): self { $this->type = $v; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $v): self { $this->category = $v; return $this; }

    public function getOptions(): ?array { return $this->options ?? []; }
    public function setOptions(?array $v): self { $this->options = $v ?? []; return $this; }
}