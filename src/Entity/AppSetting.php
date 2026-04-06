<?php

namespace App\Entity;

use App\Repository\UploadRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * @ORM\Entity()
 * @ORM\Table(name="app_setting")
 */
class AppSetting
{
    
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=100)
     */
    private string $code;

    /** 
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $value = null;

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }

    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $value): self { $this->value = $value; return $this; }
}