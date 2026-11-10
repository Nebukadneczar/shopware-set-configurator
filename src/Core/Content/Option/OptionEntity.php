<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Option;

use SetConfigurator\Core\Content\Field\FieldCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class OptionEntity extends Entity
{
    use EntityIdTrait;

    protected string $slotId = '';

    protected string $type = OptionDefinition::TYPE_PRODUCT;

    protected ?string $productId = null;

    protected ?string $productVersionId = null;

    protected ?string $label = null;

    protected int $quantityMin = 1;

    protected int $quantityMax = 1;

    protected ?string $priceFormula = null;

    protected bool $preselected = false;

    protected int $position = 0;

    protected ?string $nestedSetId = null;

    /** @var array<string, int|float|string>|null */
    protected ?array $customValues = null;

    protected ?ProductEntity $product = null;

    protected ?FieldCollection $fields = null;

    public function getSlotId(): string
    {
        return $this->slotId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isProduct(): bool
    {
        return $this->type === OptionDefinition::TYPE_PRODUCT;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function getProductVersionId(): ?string
    {
        return $this->productVersionId;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getQuantityMin(): int
    {
        return $this->quantityMin;
    }

    public function getQuantityMax(): int
    {
        return $this->quantityMax;
    }

    /** Min = Max bedeutet: fester Multiplikator, keine Auswahl durch den Kunden. */
    public function isFixedQuantity(): bool
    {
        return $this->quantityMin === $this->quantityMax;
    }

    public function getPriceFormula(): ?string
    {
        return $this->priceFormula;
    }

    public function isPreselected(): bool
    {
        return $this->preselected;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getNestedSetId(): ?string
    {
        return $this->nestedSetId;
    }

    /** @return array<string, int|float|string> */
    public function getCustomValues(): array
    {
        return $this->customValues ?? [];
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $product): void
    {
        $this->product = $product;
    }

    public function getFields(): ?FieldCollection
    {
        return $this->fields;
    }

    public function setFields(?FieldCollection $fields): void
    {
        $this->fields = $fields;
    }
}
