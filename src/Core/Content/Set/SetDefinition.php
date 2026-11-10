<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Set;

use SetConfigurator\Core\Content\Assignment\AssignmentDefinition;
use SetConfigurator\Core\Content\Slot\SlotDefinition;
use SetConfigurator\Core\Content\Target\TargetDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class SetDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'set_configurator';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return SetEntity::class;
    }

    public function getCollectionClass(): string
    {
        return SetCollection::class;
    }

    public function getDefaults(): array
    {
        return ['active' => true];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('name', 'name'))->addFlags(new Required(), new ApiAware()),
            (new LongTextField('description', 'description'))->addFlags(new ApiAware()),
            (new BoolField('active', 'active'))->addFlags(new ApiAware()),
            new StringField('price_formula', 'priceFormula', 1000),
            (new OneToManyAssociationField('slots', SlotDefinition::class, 'set_id'))->addFlags(new CascadeDelete(), new ApiAware()),
            (new OneToManyAssociationField('assignments', AssignmentDefinition::class, 'set_id'))->addFlags(new CascadeDelete()),
            (new OneToManyAssociationField('targets', TargetDefinition::class, 'set_id'))->addFlags(new CascadeDelete()),
        ]);
    }
}
