<?php

namespace App\Entity\Rachat;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="revendeurs", indexes={
 *   @ORM\Index(name="idx_revendeur_hib_supplier", columns={"hib_supplier_id"}),
 *   @ORM\Index(name="idx_revendeur_ref_ext", columns={"ref_ext"})
 * })
 * @ORM\HasLifecycleCallbacks()
 */
class Revendeur
{
    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    private ?int $id = null;

    /** @ORM\Column(type="string", length=255, nullable=true) */
    private ?string $nom = null;

    /** @ORM\Column(type="string", length=255, nullable=true) */
    private ?string $prenom = null;

    /** @ORM\Column(type="string", length=255, nullable=true) */
    private ?string $email = null;

    /** @ORM\Column(type="string", length=64, nullable=true) */
    private ?string $telephone = null;

    /** @ORM\Column(type="string", length=255, nullable=true) */
    private ?string $adresse1 = null;

    /** @ORM\Column(type="string", length=255, nullable=true) */
    private ?string $adresse2 = null;

    /** @ORM\Column(type="string", length=64, nullable=true) */
    private ?string $code_postal = null;

    /** @ORM\Column(type="string", length=255, nullable=true) */
    private ?string $ville = null;

    /** @ORM\Column(type="string", length=255, nullable=true) */
    private ?string $pays = 'France';

    /** @ORM\Column(name="hib_supplier_id", type="integer", nullable=true) */
    private ?int $hibSupplierId = null;

    /**
     * Ref externe stable pour Hiboutik (ex: RACHAT-SELLER-123)
     * @ORM\Column(name="ref_ext", type="string", length=255, nullable=true)
     */
    private ?string $refExt = null;

    /** @ORM\Column(name="created_at", type="datetime_immutable", nullable=true) */
    private ?\DateTimeImmutable $createdAt = null;

    /** @ORM\PrePersist */
    public function prePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

/**
 * @ORM\Column(name="ci_recto_url", type="string", length=255, nullable=true)
 */
private ?string $ciRectoUrl = null;

/**
 * @ORM\Column(name="ci_verso_url", type="string", length=255, nullable=true)
 */
private ?string $ciVersoUrl = null;

public function getCiRectoUrl(): ?string { return $this->ciRectoUrl; }
public function setCiRectoUrl(?string $v): self { $this->ciRectoUrl = $v; return $this; }

public function getCiVersoUrl(): ?string { return $this->ciVersoUrl; }
public function setCiVersoUrl(?string $v): self { $this->ciVersoUrl = $v; return $this; }



    public function getId(): ?int { return $this->id; }

    public function getNom(): ?string { return $this->nom; }
    public function setNom(?string $v): self { $this->nom = $v; return $this; }

    public function getPrenom(): ?string { return $this->prenom; }
    public function setPrenom(?string $v): self { $this->prenom = $v; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): self { $this->email = $v; return $this; }

    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $v): self { $this->telephone = $v; return $this; }

    public function getAdresse1(): ?string { return $this->adresse1; }
    public function setAdresse1(?string $v): self { $this->adresse1 = $v; return $this; }

    public function getAdresse2(): ?string { return $this->adresse2; }
    public function setAdresse2(?string $v): self { $this->adresse2 = $v; return $this; }

    public function getCodePostal(): ?string { return $this->code_postal; }
    public function setCodePostal(?string $v): self { $this->code_postal = $v; return $this; }

    public function getVille(): ?string { return $this->ville; }
    public function setVille(?string $v): self { $this->ville = $v; return $this; }

    public function getPays(): ?string { return $this->pays; }
    public function setPays(?string $v): self { $this->pays = $v; return $this; }

    public function getHibSupplierId(): ?int { return $this->hibSupplierId; }
    public function setHibSupplierId(?int $v): self { $this->hibSupplierId = $v; return $this; }

    public function getRefExt(): ?string { return $this->refExt; }
    public function setRefExt(?string $v): self { $this->refExt = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getDisplayName(): string
    {
        $n = trim(($this->prenom ?? '').' '.($this->nom ?? ''));
        return $n !== '' ? $n : ('Revendeur #'.$this->id);
    }
}
