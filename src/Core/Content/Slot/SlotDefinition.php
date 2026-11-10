<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Slot;

use SetConfigurator\Core\Content\Option\OptionDefinition;
use SetConfigurator\Core\Content\Set\SetDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class SlotDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'set_configurator_slot';

    /** Erlaubte Frontend-Vorlagen je Slot. */
    public const TEMPLATES = ['select', 'radio', 'checkbox', 'cards'];

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return SlotEntity::class;
    }

    public function getCollectionClass(): string
    {
        return SlotCollection::class;
    }

    public function getDefaults(): array
    {
        return ['template' => 'select', 'minSelect' => 0, 'position' => 0];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('set_id', 'setId', SetDefinition::class))->addFlags(new Required(), new ApiAware()),
            (new StringField('name', 'name'))->addFlags(new Required(), new ApiAware()),
            (new LongTextField('description', 'description'))->addFlags(new ApiAware()),
            (new StringField('template', 'template', 64))->addFlags(new ApiAware()),
            (new IntField('min_select', 'minSelect', 0))->addFlags(new ApiAware()),
            (new IntField('max_select', 'maxSelect', 0))->addFlags(new ApiAware()),
            (new IntField('position', 'position'))->addFlags(new ApiAware()),
            new ManyToOneAssociationField('set', 'set_id', SetDefinition::class, 'id', false),
            (new OneToManyAssociationField('options', OptionDefinition::class, 'slot_id'))->addFlags(new CascadeDelete(), new ApiAware()),
        ]);
    }
}
