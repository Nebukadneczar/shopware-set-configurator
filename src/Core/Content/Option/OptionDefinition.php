<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Option;

use SetConfigurator\Core\Content\Field\FieldDefinition;
use SetConfigurator\Core\Content\Set\SetDefinition;
use SetConfigurator\Core\Content\Slot\SlotDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class OptionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'set_configurator_option';

    public const TYPE_PRODUCT = 'product';
    public const TYPE_STATIC = 'static';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return OptionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return OptionCollection::class;
    }

    public function getDefaults(): array
    {
        return [
            'type' => self::TYPE_PRODUCT,
            'quantityMin' => 1,
            'quantityMax' => 1,
            'preselected' => false,
            'position' => 0,
        ];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('slot_id', 'slotId', SlotDefinition::class))->addFlags(new Required(), new ApiAware()),
            (new StringField('type', 'type', 16))->addFlags(new Required(), new ApiAware()),
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new ApiAware()),
            (new ReferenceVersionField(ProductDefinition::class, 'product_version_id'))->addFlags(new ApiAware()),
            (new StringField('label', 'label'))->addFlags(new ApiAware()),
            (new IntField('quantity_min', 'quantityMin', 0))->addFlags(new ApiAware()),
            (new IntField('quantity_max', 'quantityMax', 0))->addFlags(new ApiAware()),
            new StringField('price_formula', 'priceFormula', 1000),
            (new BoolField('preselected', 'preselected'))->addFlags(new ApiAware()),
            (new IntField('position', 'position'))->addFlags(new ApiAware()),
            (new FkField('nested_set_id', 'nestedSetId', SetDefinition::class))->addFlags(new ApiAware()),
            new JsonField('custom_values', 'customValues'),
            new ManyToOneAssociationField('slot', 'slot_id', SlotDefinition::class, 'id', false),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class, 'id', false),
            new ManyToOneAssociationField('nestedSet', 'nested_set_id', SetDefinition::class, 'id', false),
            (new OneToManyAssociationField('fields', FieldDefinition::class, 'option_id'))->addFlags(new CascadeDelete(), new ApiAware()),
        ]);
    }
}
