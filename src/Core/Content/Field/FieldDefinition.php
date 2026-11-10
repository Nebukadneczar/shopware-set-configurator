<?php

declare(strict_types=1);

namespace SetConfigurator\Core\Content\Field;

use SetConfigurator\Core\Content\Option\OptionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection as DalFieldCollection;

/**
 * Zusatzfeld einer Option (Checkbox, Auswahlfeld, Textfeld, Nummernfeld, Uploadfeld).
 */
class FieldDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'set_configurator_option_field';

    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_SELECT = 'select';
    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_UPLOAD = 'upload';

    public const TYPES = [
        self::TYPE_CHECKBOX,
        self::TYPE_SELECT,
        self::TYPE_TEXT,
        self::TYPE_NUMBER,
        self::TYPE_UPLOAD,
    ];

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return FieldEntity::class;
    }

    public function getCollectionClass(): string
    {
        return FieldCollection::class;
    }

    public function getDefaults(): array
    {
        return ['type' => self::TYPE_TEXT, 'required' => false, 'position' => 0];
    }

    protected function defineFields(): DalFieldCollection
    {
        return new DalFieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('option_id', 'optionId', OptionDefinition::class))->addFlags(new Required(), new ApiAware()),
            (new StringField('type', 'type', 16))->addFlags(new Required(), new ApiAware()),
            (new StringField('name', 'name', 64))->addFlags(new Required(), new ApiAware()),
            (new StringField('label', 'label'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('required', 'required'))->addFlags(new ApiAware()),
            (new JsonField('choices', 'choices'))->addFlags(new ApiAware()),
            new StringField('price_formula', 'priceFormula', 1000),
            (new IntField('position', 'position'))->addFlags(new ApiAware()),
            new ManyToOneAssociationField('option', 'option_id', OptionDefinition::class, 'id', false),
        ]);
    }
}
