<?php

declare(strict_types=1);

namespace SetConfigurator\Tests\Service;

use SetConfigurator\Core\Content\Field\FieldCollection;
use SetConfigurator\Core\Content\Field\FieldEntity;
use SetConfigurator\Core\Content\Option\OptionCollection;
use SetConfigurator\Core\Content\Option\OptionEntity;
use SetConfigurator\Core\Content\Set\SetEntity;
use SetConfigurator\Core\Content\Slot\SlotCollection;
use SetConfigurator\Core\Content\Slot\SlotEntity;

/**
 * Hilfsfunktionen zum Bauen von Test-Sets ohne Datenbank.
 */
final class Builder
{
    /** @param list<SlotEntity> $slots */
    public static function set(string $id, array $slots, ?string $formula = null): SetEntity
    {
        $set = new SetEntity();
        $set->setId($id);
        $set->assign(['name' => 'Set ' . $id, 'active' => true, 'priceFormula' => $formula, 'slots' => new SlotCollection($slots)]);

        return $set;
    }

    /** @param list<OptionEntity> $options */
    public static function slot(string $id, array $options, int $min = 0, ?int $max = null): SlotEntity
    {
        $slot = new SlotEntity();
        $slot->setId($id);
        $slot->assign(['name' => 'Slot ' . $id, 'minSelect' => $min, 'maxSelect' => $max, 'options' => new OptionCollection($options)]);

        return $slot;
    }

    /**
     * @param array<string, mixed> $props
     * @param list<FieldEntity> $fields
     */
    public static function option(string $id, array $props = [], array $fields = []): OptionEntity
    {
        $option = new OptionEntity();
        $option->setId($id);
        $option->assign($props + ['type' => 'static', 'label' => 'Option ' . $id, 'fields' => new FieldCollection($fields)]);

        return $option;
    }

    /** @param array<string, mixed> $props */
    public static function field(string $id, string $type, string $name, array $props = []): FieldEntity
    {
        $field = new FieldEntity();
        $field->setId($id);
        $field->assign($props + ['type' => $type, 'name' => $name, 'label' => $name]);

        return $field;
    }
}
