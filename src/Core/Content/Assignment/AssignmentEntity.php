<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Assignment;

use SetConfigurator\Core\Content\Set\SetEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class AssignmentEntity extends Entity
{
    use EntityIdTrait;

    protected string $setId = '';

    protected string $productId = '';

    protected string $productVersionId = '';

    protected ?SetEntity $set = null;

    protected ?\Shopware\Core\Content\Product\ProductEntity $product = null;

    public function getProduct(): ?\Shopware\Core\Content\Product\ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?\Shopware\Core\Content\Product\ProductEntity $product): void
    {
        $this->product = $product;
    }

    public function getSetId(): string
    {
        return $this->setId;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getSet(): ?SetEntity
    {
        return $this->set;
    }

    public function setSet(?SetEntity $set): void
    {
        $this->set = $set;
    }
}
