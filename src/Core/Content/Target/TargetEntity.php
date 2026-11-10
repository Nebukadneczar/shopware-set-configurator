<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Target;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class TargetEntity extends Entity
{
    use EntityIdTrait;

    protected string $setId = '';

    protected string $type = TargetDefinition::TYPE_CATEGORY;

    protected ?string $categoryId = null;

    protected ?string $manufacturerId = null;

    protected bool $includeChildren = true;

    protected ?CategoryEntity $category = null;

    protected ?ProductManufacturerEntity $manufacturer = null;

    public function getSetId(): string
    {
        return $this->setId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCategoryId(): ?string
    {
        return $this->categoryId;
    }

    public function getManufacturerId(): ?string
    {
        return $this->manufacturerId;
    }

    public function isIncludeChildren(): bool
    {
        return $this->includeChildren;
    }

    public function getCategory(): ?CategoryEntity
    {
        return $this->category;
    }

    public function getManufacturer(): ?ProductManufacturerEntity
    {
        return $this->manufacturer;
    }
}
