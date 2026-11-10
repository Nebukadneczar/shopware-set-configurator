<?php

declare(strict_types=1);

namespace SetConfigurator\Service;

use SetConfigurator\Core\Content\Field\FieldDefinition;
use SetConfigurator\Core\Content\Field\FieldEntity;
use SetConfigurator\Core\Content\Option\OptionEntity;
use SetConfigurator\Core\Content\Set\SetEntity;

/**
 * Prüft die Auswahl eines Kunden gegen die Definition eines Sets.
 *
 * Aufbau der Auswahl ($selection):
 * [
 *   'slots' => [
 *     '<slotId>' => [
 *       [
 *         'optionId'  => '<id>',
 *         'quantity'  => 2,                  // optional, Standard = Mindestmenge
 *         'productId' => '<variantId>',      // nur, wenn die Option ein Elternprodukt ist
 *         'fields'    => ['<fieldId>' => 'wert'],
 *         'nested'    => [ 'slots' => [...] ],  // nur bei Optionen mit verschachteltem Set
 *       ],
 *     ],
 *   ],
 * ]
 *
 * Ergebnis: Liste von Verstößen. Leer = gültig.
 * Jeder Verstoß: ['code' => string, 'path' => string, 'slotId'?, 'optionId'?, 'fieldId'?, 'params'?].
 */
class SelectionValidator
{
    public const MAX_NESTING = 3;
    public const MAX_TEXT_LENGTH = 1000;

    /**
     * @param array<string, mixed> $selection
     * @param (callable(string): ?SetEntity)|null $loadSet lädt ein verschachteltes Set
     * @param (callable(string, string): bool)|null $isVariantOf (variantenId, elternId): bool
     *
     * @return list<array<string, mixed>>
     */
    public function validate(
        SetEntity $set,
        array $selection,
        ?callable $loadSet = null,
        ?callable $isVariantOf = null,
        string $path = '',
        int $depth = 0,
        array $setPath = []
    ): array {
        $violations = [];
        $setPath[] = $set->getId();
        $slotsInput = \is_array($selection['slots'] ?? null) ? $selection['slots'] : [];

        $slots = [];
        foreach ($set->getSlots() ?? [] as $slot) {
            $slots[$slot->getId()] = $slot;
        }

        foreach ($slotsInput as $slotId => $_) {
            if (!isset($slots[(string) $slotId])) {
                $violations[] = $this->v('unknown_slot', $path, ['slotId' => (string) $slotId]);
            }
        }

        foreach ($slots as $slotId => $slot) {
            $entries = $slotsInput[$slotId] ?? [];
            $entries = \is_array($entries) ? array_values($entries) : [];

            $options = [];
            foreach ($slot->getOptions() ?? [] as $option) {
                $options[$option->getId()] = $option;
            }

            $seen = [];
            $count = 0;
            foreach ($entries as $entry) {
                if (!\is_array($entry) || !isset($entry['optionId']) || !isset($options[(string) $entry['optionId']])) {
                    $violations[] = $this->v('unknown_option', $path, ['slotId' => $slotId]);
                    continue;
                }
                $option = $options[(string) $entry['optionId']];
                if (isset($seen[$option->getId()])) {
                    $violations[] = $this->v('duplicate_option', $path, ['slotId' => $slotId, 'optionId' => $option->getId()]);
                    continue;
                }
                $seen[$option->getId()] = true;
                ++$count;

                array_push(
                    $violations,
                    ...$this->validateEntry($option, $entry, $slotId, $loadSet, $isVariantOf, $path, $depth, $setPath)
                );
            }

            if ($count < $slot->getMinSelect()) {
                $violations[] = $this->v('too_few', $path, ['slotId' => $slotId, 'min' => $slot->getMinSelect(), 'count' => $count]);
            }
            if ($slot->getMaxSelect() !== null && $count > $slot->getMaxSelect()) {
                $violations[] = $this->v('too_many', $path, ['slotId' => $slotId, 'max' => $slot->getMaxSelect(), 'count' => $count]);
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string> $setPath
     *
     * @return list<array<string, mixed>>
     */
    private function validateEntry(
        OptionEntity $option,
        array $entry,
        string $slotId,
        ?callable $loadSet,
        ?callable $isVariantOf,
        string $path,
        int $depth,
        array $setPath
    ): array {
        $out = [];
        $ctx = ['slotId' => $slotId, 'optionId' => $option->getId()];

        // Menge
        $quantity = $entry['quantity'] ?? $option->getQuantityMin();
        if (!\is_int($quantity) && !(\is_string($quantity) && ctype_digit($quantity))) {
            $out[] = $this->v('quantity_out_of_range', $path, $ctx + ['min' => $option->getQuantityMin(), 'max' => $option->getQuantityMax()]);
        } else {
            $quantity = (int) $quantity;
            if ($quantity < $option->getQuantityMin() || $quantity > $option->getQuantityMax()) {
                $out[] = $this->v('quantity_out_of_range', $path, $ctx + ['min' => $option->getQuantityMin(), 'max' => $option->getQuantityMax()]);
            }
        }

        // Variantenwahl bei Elternprodukten
        if ($option->isProduct() && $option->getProduct() !== null && ($option->getProduct()->getChildCount() ?? 0) > 0) {
            $variantId = $entry['productId'] ?? null;
            if (!\is_string($variantId) || $variantId === '') {
                $out[] = $this->v('variant_required', $path, $ctx);
            } elseif ($variantId !== $option->getProductId()
                && $isVariantOf !== null
                && !$isVariantOf($variantId, (string) $option->getProductId())
            ) {
                $out[] = $this->v('variant_invalid', $path, $ctx);
            }
        }

        // Zusatzfelder
        $values = \is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
        foreach ($option->getFields() ?? [] as $field) {
            $code = $this->checkField($field, $values[$field->getId()] ?? null);
            if ($code !== null) {
                $out[] = $this->v($code, $path, $ctx + ['fieldId' => $field->getId()]);
            }
        }

        // Verschachteltes Set
        if ($option->getNestedSetId() !== null) {
            $nested = $entry['nested'] ?? null;
            if ($depth + 1 > self::MAX_NESTING || \in_array($option->getNestedSetId(), $setPath, true) || $loadSet === null) {
                $out[] = $this->v('nested_invalid', $path, $ctx);
            } else {
                $nestedSet = $loadSet($option->getNestedSetId());
                if ($nestedSet === null || !$nestedSet->isActive()) {
                    $out[] = $this->v('nested_invalid', $path, $ctx);
                } else {
                    array_push(
                        $out,
                        ...$this->validate(
                            $nestedSet,
                            \is_array($nested) ? $nested : [],
                            $loadSet,
                            $isVariantOf,
                            $path . $option->getId() . '/',
                            $depth + 1,
                            $setPath
                        )
                    );
                }
            }
        }

        return $out;
    }

    private function checkField(FieldEntity $field, mixed $value): ?string
    {
        $empty = $value === null || $value === '' || $value === false || $value === [];

        switch ($field->getType()) {
            case FieldDefinition::TYPE_CHECKBOX:
                if ($field->isRequired() && !$this->truthy($value)) {
                    return 'field_required';
                }

                return null;

            case FieldDefinition::TYPE_SELECT:
                if ($empty) {
                    return $field->isRequired() ? 'field_required' : null;
                }
                foreach ($field->getChoices() as $choice) {
                    if ((string) ($choice['value'] ?? '') === (string) $value) {
                        return null;
                    }
                }

                return 'field_invalid';

            case FieldDefinition::TYPE_NUMBER:
                if ($empty && $value !== 0 && $value !== '0') {
                    return $field->isRequired() ? 'field_required' : null;
                }

                return is_numeric($value) ? null : 'field_invalid';

            case FieldDefinition::TYPE_UPLOAD:
                if ($empty) {
                    return $field->isRequired() ? 'field_required' : null;
                }

                // Erwartet wird die ID eines hochgeladenen Mediums.
                return \is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1 ? null : 'field_invalid';

            case FieldDefinition::TYPE_TEXT:
            default:
                if ($empty) {
                    return $field->isRequired() ? 'field_required' : null;
                }

                return \is_string($value) && mb_strlen($value) <= self::MAX_TEXT_LENGTH ? null : 'field_invalid';
        }
    }

    public function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true';
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function v(string $code, string $path, array $extra = []): array
    {
        return ['code' => $code, 'path' => $path] + $extra;
    }
}
