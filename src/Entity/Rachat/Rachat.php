<?php

namespace App\Entity\Rachat;

use Doctrine\ORM\Mapping as ORM;
use App\Entity\Rachat\Revendeur;

/**
 * @ORM\Entity()
 * @ORM\Table(name="rachats")
 * @ORM\HasLifecycleCallbacks()
 */
class Rachat
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /** @ORM\Column(name="imei", type="string", length=255, nullable=true) */
    private ?string $imei = null;

    /** @ORM\Column(name="numero_ci", type="string", length=255, nullable=true) */
    private ?string $numeroCi = null;

    /** @ORM\Column(name="prix_achat", type="string", length=255, nullable=true) */
    private ?string $prixAchat = null;

    /** @ORM\Column(name="nom", type="string", length=255, nullable=true) */
    private ?string $nom = null;

    /** @ORM\Column(name="prenom", type="string", length=255, nullable=true) */
    private ?string $prenom = null;

    /** @ORM\Column(name="telephone", type="string", length=255, nullable=true) */
    private ?string $telephone = null;

    /** @ORM\Column(name="marque_modele", type="string", length=255, nullable=true) */
    private ?string $marqueModele = null;

    /** @ORM\Column(name="piece_identite_url", type="text", nullable=true) */
    private ?string $pieceIdentiteUrl = null;

    /** @ORM\Column(name="date_cession", type="datetime_immutable", nullable=true) */
    private ?\DateTimeImmutable $dateCession = null;

    /** @ORM\Column(name="adresse", type="text", nullable=true) */
    private ?string $adresse = null;

    /** @ORM\Column(name="code_postal", type="string", length=64, nullable=true) */
    private ?string $codePostal = null;

    /** @ORM\Column(name="email", type="string", length=255, nullable=true) */
    private ?string $email = null;

    /** @ORM\Column(name="signature_url", type="text", nullable=true) */
    private ?string $signatureUrl = null;

    /** @ORM\Column(name="pdf_url", type="text", nullable=true) */
    private ?string $pdfUrl = null;

    /** @ORM\Column(name="photos_json", type="text", nullable=true) */
    private ?string $photosJson = null;

    /** @ORM\Column(name="photo1", type="text", nullable=true) */
    private ?string $photo1 = null;

    /** @ORM\Column(name="photo2", type="text", nullable=true) */
    private ?string $photo2 = null;

    /** @ORM\Column(name="photo3", type="text", nullable=true) */
    private ?string $photo3 = null;

    /** @ORM\Column(name="colonne1", type="text", nullable=true) */
    private ?string $colonne1 = null;

    /** @ORM\Column(name="colonne2", type="text", nullable=true) */
    private ?string $colonne2 = null;

    /** @ORM\Column(name="colonne3", type="text", nullable=true) */
    private ?string $colonne3 = null;

    /** @ORM\Column(name="colonne4", type="text", nullable=true) */
    private ?string $colonne4 = null;

    /** @ORM\Column(name="created_at", type="datetime_immutable", nullable=true) */
    private ?\DateTimeImmutable $createdAt = null;

    /** @ORM\Column(name="hib_supplier_id", type="integer", nullable=true) */
    private ?int $hibSupplierId = null;

    /** @ORM\Column(name="hib_product_id", type="integer", nullable=true) */
    private ?int $hibProductId = null;


    /** @ORM\Column(name="enabled", type="boolean", options={"default":1}) */
    private bool $enabled = true;

    /** @ORM\Column(name="paid_method", type="string", length=16, nullable=true) */
    private ?string $paidMethod = null;

    /** @ORM\Column(name="paid_at", type="datetime_immutable", nullable=true) */
    private ?\DateTimeImmutable $paidAt = null;


    /**
 * @ORM\ManyToOne(targetEntity=Revendeur::class)
 * @ORM\JoinColumn(name="revendeur_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
 */
private ?Revendeur $revendeur = null;


/** @ORM\Column(name="hib_inventory_input_id", type="integer", nullable=true) */
private ?int $hibInventoryInputId = null;

public function getHibInventoryInputId(): ?int
{
    return $this->hibInventoryInputId;
}

public function setHibInventoryInputId(?int $v): self
{
    $this->hibInventoryInputId = $v;
    return $this;
}

/** @ORM\Column(name="hib_arrivage_added_at", type="datetime_immutable", nullable=true) */
private ?\DateTimeImmutable $hibArrivageAddedAt = null;


public function getHibArrivageAddedAt(): ?\DateTimeImmutable { return $this->hibArrivageAddedAt; }
public function setHibArrivageAddedAt(?\DateTimeImmutable $v): self { $this->hibArrivageAddedAt = $v; return $this; }


// App\Entity\Rachat.php

/** @ORM\Column(name="hib_brand_id", type="integer", nullable=true) */
private ?int $hibBrandId = null;

/** @ORM\Column(name="hib_category_id", type="integer", nullable=true) */
private ?int $hibCategoryId = null;

public function getHibBrandId(): ?int { return $this->hibBrandId; }
public function setHibBrandId(?int $id): self { $this->hibBrandId = $id; return $this; }

public function getHibCategoryId(): ?int { return $this->hibCategoryId; }
public function setHibCategoryId(?int $id): self { $this->hibCategoryId = $id; return $this; }


/** @ORM\Column(name="attributes", type="json", nullable=true) */
private ?array $attributes = [];

public function getAttributes(): array
{
    return $this->attributes ?? [];
}

public function setAttributes(?array $data): self
{
    $this->attributes = $data ?? [];
    return $this;
}


public function getRevendeur(): ?Revendeur
{
    return $this->revendeur;
}
public function setRevendeur(?Revendeur $v): self
{
    $this->revendeur = $v;
    return $this;
}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    public function setEnabled(bool $v): self
    {
        $this->enabled = $v;
        return $this;
    }

    public function getPaidMethod(): ?string
    {
        return $this->paidMethod;
    }
    public function setPaidMethod(?string $v): self
    {
        $this->paidMethod = $v;
        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }
    public function setPaidAt(?\DateTimeImmutable $v): self
    {
        $this->paidAt = $v;
        return $this;
    }


    public function getHibSupplierId(): ?int
    {
        return $this->hibSupplierId;
    }
    public function setHibSupplierId(?int $v): self
    {
        $this->hibSupplierId = $v;
        return $this;
    }

    public function getHibProductId(): ?int
    {
        return $this->hibProductId;
    }
    public function setHibProductId(?int $v): self
    {
        $this->hibProductId = $v;
        return $this;
    }


    /** @ORM\PrePersist */
    public function prePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    // ===== Getters/Setters =====
    public function getId(): ?int
    {
        return $this->id;
    } // <-- pas de setId()

    public function getImei(): ?string
    {
        return $this->imei;
    }
    public function setImei(?string $v): self
    {
        $this->imei = $v;
        return $this;
    }

    public function getNumeroCi(): ?string
    {
        return $this->numeroCi;
    }
    public function setNumeroCi(?string $v): self
    {
        $this->numeroCi = $v;
        return $this;
    }

    public function getPrixAchat(): ?string
    {
        return $this->prixAchat;
    }
    public function setPrixAchat(?string $v): self
    {
        $this->prixAchat = $v;
        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }
    public function setNom(?string $v): self
    {
        $this->nom = $v;
        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }
    public function setPrenom(?string $v): self
    {
        $this->prenom = $v;
        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }
    public function setTelephone(?string $v): self
    {
        $this->telephone = $v;
        return $this;
    }

    public function getMarqueModele(): ?string
    {
        return $this->marqueModele;
    }
    public function setMarqueModele(?string $v): self
    {
        $this->marqueModele = $v;
        return $this;
    }

    public function getPieceIdentiteUrl(): ?string
    {
        return $this->pieceIdentiteUrl;
    }
    public function setPieceIdentiteUrl(?string $v): self
    {
        $this->pieceIdentiteUrl = $v;
        return $this;
    }

    public function getDateCession(): ?\DateTimeImmutable
    {
        return $this->dateCession;
    }
    public function setDateCession(?\DateTimeImmutable $v): self
    {
        $this->dateCession = $v;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function setCreatedAt(?\DateTimeImmutable $v): self
    {
        $this->createdAt = $v;
        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }
    public function setAdresse(?string $v): self
    {
        $this->adresse = $v;
        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }
    public function setCodePostal(?string $v): self
    {
        $this->codePostal = $v;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }
    public function setEmail(?string $v): self
    {
        $this->email = $v;
        return $this;
    }

    public function getSignatureUrl(): ?string
    {
        return $this->signatureUrl;
    }
    public function setSignatureUrl(?string $v): self
    {
        $this->signatureUrl = $v;
        return $this;
    }

    public function getPdfUrl(): ?string
    {
        return $this->pdfUrl;
    }
    public function setPdfUrl(?string $v): self
    {
        $this->pdfUrl = $v;
        return $this;
    }

    public function getPhotosJson(): ?string
    {
        return $this->photosJson;
    }
    public function setPhotosJson(?string $v): self
    {
        $this->photosJson = $v;
        return $this;
    }

    public function getPhoto1(): ?string
    {
        return $this->photo1;
    }
    public function setPhoto1(?string $v): self
    {
        $this->photo1 = $v;
        return $this;
    }

    public function getPhoto2(): ?string
    {
        return $this->photo2;
    }
    public function setPhoto2(?string $v): self
    {
        $this->photo2 = $v;
        return $this;
    }

    public function getPhoto3(): ?string
    {
        return $this->photo3;
    }
    public function setPhoto3(?string $v): self
    {
        $this->photo3 = $v;
        return $this;
    }

    public function getColonne1(): ?string
    {
        return $this->colonne1;
    }
    public function setColonne1(?string $v): self
    {
        $this->colonne1 = $v;
        return $this;
    }

    public function getColonne2(): ?string
    {
        return $this->colonne2;
    }
    public function setColonne2(?string $v): self
    {
        $this->colonne2 = $v;
        return $this;
    }

    public function getColonne3(): ?string
    {
        return $this->colonne3;
    }
    public function setColonne3(?string $v): self
    {
        $this->colonne3 = $v;
        return $this;
    }

    public function getColonne4(): ?string
    {
        return $this->colonne4;
    }
    public function setColonne4(?string $v): self
    {
        $this->colonne4 = $v;
        return $this;
    }

}