<?php

namespace App\Entity\Hiboutik;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(
 *     name="hib_label_board_item",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="uniq_label_board_slot", columns={"board_id", "slot_index"})
 *     }
 * )
 */
class LabelBoardItem
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Hiboutik\LabelBoard", inversedBy="items")
     * @ORM\JoinColumn(name="board_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?LabelBoard $board = null;

    /**
     * 0, 1, 2, 3
     *
     * @ORM\Column(name="slot_index", type="integer")
     */
    private int $slotIndex = 0;

    /**
     * ID produit Hiboutik
     *
     * @ORM\Column(type="integer")
     */
    private int $productId = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBoard(): ?LabelBoard
    {
        return $this->board;
    }

    public function setBoard(?LabelBoard $board): self
    {
        $this->board = $board;
        return $this;
    }

    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    public function setSlotIndex(int $slotIndex): self
    {
        $this->slotIndex = $slotIndex;
        return $this;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function setProductId(int $productId): self
    {
        $this->productId = $productId;
        return $this;
    }
}