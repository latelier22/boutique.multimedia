<?php

namespace App\Entity\Rachat;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="rachat_items")
 * @ORM\HasLifecycleCallbacks()
 */
class RachatItem
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Dossier parent
     *
     * @ORM\ManyToOne(targetEntity=RachatDossier::class, inversedBy="items")
     * @ORM\JoinColumn(name="dossier_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?RachatDossier $dossier = null;

    /**
     * Ordre d'affichage dans le dossier
     *
     * @ORM\Column(name="ordre", type="integer", options={"default":0})
     */
    private int $ordre = 0;

    /**
     * Quantité (souvent 1, mais autant le prévoir)
     *
     * @ORM\Column(name="quantite", type="integer", options={"default":1})
     */
    private int $quantite = 1;

    /**
     * Libellé libre de la ligne
     *
     * @ORM\Column(name="designation", type="string", length=255, nullable=true)
     */
    private ?string $designation = null;

    /**
     * Catégorie libre métier
     *
     * @ORM\Column(name="categorie", type="string", length=255, nullable=true)
     */
    private ?string $categorie = null;

    /**
     * État libre (neuf, bon état, correct, etc.)
     *
     * @ORM\Column(name="etat", type="string", length=255, nullable=true)
     */
    private ?string $etat = null;

    /**
     * Marque
     *
     * @ORM\Column(name="marque", type="string", length=255, nullable=true)
     */
    private ?string $marque = null;

    /**
     * Modèle
     *
     * @ORM\Column(name="modele", type="string", length=255, nullable=true)
     */
    private ?string $modele = null;

    /**
     * Ancien champ pratique que tu avais déjà
     *
     * @ORM\Column(name="marque_modele", type="string", length=255, nullable=true)
     */
    private ?string $marqueModele = null;

    /**
     * IMEI
     *
     * @ORM\Column(name="imei", type="string", length=255, nullable=true)
     */
    private ?string $imei = null;

    /**
     * Numéro de série générique
     *
     * @ORM\Column(name="numero_serie", type="string", length=255, nullable=true)
     */
    private ?string $numeroSerie = null;

    /**
     * Capacité / couleur / variante éventuelle
     *
     * @ORM\Column(name="variante", type="string", length=255, nullable=true)
     */
    private ?string $variante = null;

    /**
     * Description libre
     *
     * @ORM\Column(name="description", type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * Observations internes
     *
     * @ORM\Column(name="observations", type="text", nullable=true)
     */
    private ?string $observations = null;

    /**
     * Prix d'achat de la ligne
     * On stocke en decimal pour éviter les problèmes de float.
     *
     * @ORM\Column(name="prix_achat", type="decimal", precision=10, scale=2, nullable=true)
     */
    private ?string $prixAchat = null;

    /**
     * Référence éventuelle côté Hiboutik
     *
     * @ORM\Column(name="hib_product_id", type="integer", nullable=true)
     */
    private ?int $hibProductId = null;

    /**
     * Marque Hiboutik
     *
     * @ORM\Column(name="hib_brand_id", type="integer", nullable=true)
     */
    private ?int $hibBrandId = null;

    /**
     * Catégorie Hiboutik
     *
     * @ORM\Column(name="hib_category_id", type="integer", nullable=true)
     */
    private ?int $hibCategoryId = null;

    /**
     * Attributs dynamiques
     *
     * @ORM\Column(name="attributes", type="json", nullable=true)
     */
    private ?array $attributes = [];

    /**
     * Photos regroupées en JSON
     *
     * @ORM\Column(name="photos_json", type="text", nullable=true)
     */
    private ?string $photosJson = null;

    /**
     * Photos unitaires, reprises de ton ancien modèle
     *
     * @ORM\Column(name="photo1", type="text", nullable=true)
     */
    private ?string $photo1 = null;

    /**
     * @ORM\Column(name="photo2", type="text", nullable=true)
     */
    private ?string $photo2 = null;

    /**
     * @ORM\Column(name="photo3", type="text", nullable=true)
     */
    private ?string $photo3 = null;

    /**
     * Colonnes libres héritées de ton ancien modèle
     *
     * @ORM\Column(name="colonne1", type="text", nullable=true)
     */
    private ?string $colonne1 = null;

    /**
     * @ORM\Column(name="colonne2", type="text", nullable=true)
     */
    private ?string $colonne2 = null;

    /**
     * @ORM\Column(name="colonne3", type="text", nullable=true)
     */
    private ?string $colonne3 = null;

    /**
     * @ORM\Column(name="colonne4", type="text", nullable=true)
     */
    private ?string $colonne4 = null;

    /**
     * Actif / désactivé
     *
     * @ORM\Column(name="enabled", type="boolean", options={"default":1})
     */
    private bool $enabled = true;


 

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @ORM\Column(name="updated_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $updatedAt = null;


       /**
     * 
     * @ORM\Column(name="convert_to_hib", type="boolean", options={"default":1})
     */
    private ?bool $convertToHib = true;

    public function isConvertToHib(): ?bool
    {
        return $this->convertToHib;
    }

    public function setConvertToHib(?bool $convertToHib): self
    {
        $this->convertToHib = $convertToHib;
        return $this;
    }

    


    /** @ORM\PrePersist */
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();

        if ($this->createdAt === null) {
            $this->createdAt = $now;
        }

        if ($this->updatedAt === null) {
            $this->updatedAt = $now;
        }
    }

    /** @ORM\PreUpdate */
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLabel(): string
    {
        if ($this->designation) {
            return $this->designation;
        }

        if ($this->marqueModele) {
            return $this->marqueModele;
        }

        $parts = array_filter([$this->marque, $this->modele]);
        if (!empty($parts)) {
            return implode(' ', $parts);
        }

        return 'Produit';
    }


    #[ORM\Column(type: 'integer', nullable: true, unique: true)]
private ?int $legacyRachatId = null;

public function getLegacyRachatId(): ?int
{
    return $this->legacyRachatId;
}

public function setLegacyRachatId(?int $legacyRachatId): self
{
    $this->legacyRachatId = $legacyRachatId;
    return $this;
}


    // ===== Getters / Setters =====

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): ?RachatDossier
    {
        return $this->dossier;
    }

    public function setDossier(?RachatDossier $dossier): self
    {
        $this->dossier = $dossier;
        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): self
    {
        $this->ordre = $ordre;
        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): self
    {
        $this->quantite = $quantite;
        return $this;
    }

    public function getDesignation(): ?string
    {
        return $this->designation;
    }

    public function setDesignation(?string $designation): self
    {
        $this->designation = $designation;
        return $this;
    }

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(?string $categorie): self
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getEtat(): ?string
    {
        return $this->etat;
    }

    public function setEtat(?string $etat): self
    {
        $this->etat = $etat;
        return $this;
    }

    public function getMarque(): ?string
    {
        return $this->marque;
    }

    public function setMarque(?string $marque): self
    {
        $this->marque = $marque;
        return $this;
    }

    public function getModele(): ?string
    {
        return $this->modele;
    }

    public function setModele(?string $modele): self
    {
        $this->modele = $modele;
        return $this;
    }

    public function getMarqueModele(): ?string
    {
        return $this->marqueModele;
    }

    public function setMarqueModele(?string $marqueModele): self
    {
        $this->marqueModele = $marqueModele;
        return $this;
    }

    public function getImei(): ?string
    {
        return $this->imei;
    }

    public function setImei(?string $imei): self
    {
        $this->imei = $imei;
        return $this;
    }

    public function getNumeroSerie(): ?string
    {
        return $this->numeroSerie;
    }

    public function setNumeroSerie(?string $numeroSerie): self
    {
        $this->numeroSerie = $numeroSerie;
        return $this;
    }

    public function getVariante(): ?string
    {
        return $this->variante;
    }

    public function setVariante(?string $variante): self
    {
        $this->variante = $variante;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function setObservations(?string $observations): self
    {
        $this->observations = $observations;
        return $this;
    }

    public function getPrixAchat(): ?string
    {
        return $this->prixAchat;
    }

    public function setPrixAchat(?string $prixAchat): self
    {
        $this->prixAchat = $prixAchat;
        return $this;
    }

    public function getHibProductId(): ?int
    {
        return $this->hibProductId;
    }

    public function setHibProductId(?int $hibProductId): self
    {
        $this->hibProductId = $hibProductId;
        return $this;
    }

    public function getHibBrandId(): ?int
    {
        return $this->hibBrandId;
    }

    public function setHibBrandId(?int $hibBrandId): self
    {
        $this->hibBrandId = $hibBrandId;
        return $this;
    }

    public function getHibCategoryId(): ?int
    {
        return $this->hibCategoryId;
    }

    public function setHibCategoryId(?int $hibCategoryId): self
    {
        $this->hibCategoryId = $hibCategoryId;
        return $this;
    }

    public function getAttributes(): array
    {
        return $this->attributes ?? [];
    }

    public function setAttributes(?array $attributes): self
    {
        $this->attributes = $attributes ?? [];
        return $this;
    }

    public function getPhotosJson(): ?string
    {
        return $this->photosJson;
    }

    public function setPhotosJson(?string $photosJson): self
    {
        $this->photosJson = $photosJson;
        return $this;
    }

    public function getPhoto1(): ?string
    {
        return $this->photo1;
    }

    public function setPhoto1(?string $photo1): self
    {
        $this->photo1 = $photo1;
        return $this;
    }

    public function getPhoto2(): ?string
    {
        return $this->photo2;
    }

    public function setPhoto2(?string $photo2): self
    {
        $this->photo2 = $photo2;
        return $this;
    }

    public function getPhoto3(): ?string
    {
        return $this->photo3;
    }

    public function setPhoto3(?string $photo3): self
    {
        $this->photo3 = $photo3;
        return $this;
    }

    public function getColonne1(): ?string
    {
        return $this->colonne1;
    }

    public function setColonne1(?string $colonne1): self
    {
        $this->colonne1 = $colonne1;
        return $this;
    }

    public function getColonne2(): ?string
    {
        return $this->colonne2;
    }

    public function setColonne2(?string $colonne2): self
    {
        $this->colonne2 = $colonne2;
        return $this;
    }

    public function getColonne3(): ?string
    {
        return $this->colonne3;
    }

    public function setColonne3(?string $colonne3): self
    {
        $this->colonne3 = $colonne3;
        return $this;
    }

    public function getColonne4(): ?string
    {
        return $this->colonne4;
    }

    public function setColonne4(?string $colonne4): self
    {
        $this->colonne4 = $colonne4;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}