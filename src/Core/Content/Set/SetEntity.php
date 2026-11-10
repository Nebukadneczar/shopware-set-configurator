<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Set;

use SetConfigurator\Core\Content\Slot\SlotCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SetEntity extends Entity
{
    use EntityIdTrait;

    protected string $name = '';

    protected ?string $description = null;

    protected bool $active = true;

    protected ?string $priceFormula = null;

    protected ?SlotCollection $slots = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getPriceFormula(): ?string
    {
        return $this->priceFormula;
    }

    public function setPriceFormula(?string $priceFormula): void
    {
        $this->priceFormula = $priceFormula;
    }

    public function getSlots(): ?SlotCollection
    {
        return $this->slots;
    }

    public function setSlots(?SlotCollection $slots): void
    {
        $this->slots = $slots;
    }
}
