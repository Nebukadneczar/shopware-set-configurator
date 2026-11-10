<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Assignment;

use SetConfigurator\Core\Content\Set\SetDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Zuweisung eines Sets zu einem Produkt (ein Set je Produkt).
 * Bei Variantenprodukten kann das Elternprodukt ein Set erhalten, das die Varianten erben;
 * eine Variante mit eigener Zuweisung überschreibt das geerbte Set.
 */
class AssignmentDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'set_configurator_product';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AssignmentEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AssignmentCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('set_id', 'setId', SetDefinition::class))->addFlags(new Required()),
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new Required()),
            (new ReferenceVersionField(ProductDefinition::class, 'product_version_id'))->addFlags(new Required()),
            new ManyToOneAssociationField('set', 'set_id', SetDefinition::class, 'id', false),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class, 'id', false),
        ]);
    }
}
