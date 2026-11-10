<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Target;

use SetConfigurator\Core\Content\Set\SetDefinition;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Zuweisung eines Sets an eine Kategorie oder einen Hersteller.
 */
class TargetDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'set_configurator_target';

    public const TYPE_CATEGORY = 'category';
    public const TYPE_MANUFACTURER = 'manufacturer';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return TargetEntity::class;
    }

    public function getCollectionClass(): string
    {
        return TargetCollection::class;
    }

    public function getDefaults(): array
    {
        return ['includeChildren' => true];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('set_id', 'setId', SetDefinition::class))->addFlags(new Required()),
            (new StringField('type', 'type', 16))->addFlags(new Required()),
            new FkField('category_id', 'categoryId', CategoryDefinition::class),
            new ReferenceVersionField(CategoryDefinition::class, 'category_version_id'),
            new FkField('manufacturer_id', 'manufacturerId', ProductManufacturerDefinition::class),
            new ReferenceVersionField(ProductManufacturerDefinition::class, 'manufacturer_version_id'),
            new BoolField('include_children', 'includeChildren'),
            new ManyToOneAssociationField('set', 'set_id', SetDefinition::class, 'id', false),
            new ManyToOneAssociationField('category', 'category_id', CategoryDefinition::class, 'id', false),
            new ManyToOneAssociationField('manufacturer', 'manufacturer_id', ProductManufacturerDefinition::class, 'id', false),
        ]);
    }
}
