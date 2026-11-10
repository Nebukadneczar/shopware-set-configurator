<?php

declare(strict_types=1);

namespace SetConfigurator\Service\Transfer;

use SetConfigurator\Core\Content\Field\FieldDefinition;
use SetConfigurator\Core\Content\Option\OptionDefinition;
use SetConfigurator\Core\Content\Set\SetEntity;
use SetConfigurator\Core\Content\Slot\SlotDefinition;
use SetConfigurator\Service\Formula\FormulaEvaluator;
use SetConfigurator\Service\Formula\FormulaException;
use Shopware\Core\Defaults;

/**
 * Reine Umwandlung zwischen Sets und dem Austauschformat (JSON). Ohne Datenbank, gut testbar.
 *
 * Das Format ist shop-unabhängig: Produkte werden über die Produktnummer, verschachtelte Sets über
 * einen Schlüssel ("key") innerhalb der Datei verknüpft, nicht über IDs.
 */
final class SetTransfer
{
    public const FORMAT = 'set-configurator-export';
    public const VERSION = 1;

    /**
     * @param list<string> $assignedProductNumbers
     *
     * @return array<string, mixed>
     */
    public static function exportSet(SetEntity $set, array $assignedProductNumbers): array
    {
        $slots = [];
        foreach ($set->getSlots() ?? [] as $slot) {
            $options = [];
            foreach ($slot->getOptions() ?? [] as $option) {
                $fields = [];
                foreach ($option->getFields() ?? [] as $field) {
                    $fields[] = [
                        'type' => $field->getType(),
                        'name' => $field->getName(),
                        'label' => $field->getLabel(),
                        'required' => $field->isRequired(),
                        'choices' => $field->getChoices() ?: null,
                        'priceFormula' => $field->getPriceFormula(),
                        'position' => $field->getPosition(),
                    ];
                }
                $options[] = [
                    'type' => $option->getType(),
                    'productNumber' => $option->getProduct()?->getProductNumber(),
                    'label' => $option->getLabel(),
                    'quantityMin' => $option->getQuantityMin(),
                    'quantityMax' => $option->getQuantityMax(),
                    'priceFormula' => $option->getPriceFormula(),
                    'preselected' => $option->isPreselected(),
                    'position' => $option->getPosition(),
                    'nestedSet' => $option->getNestedSetId(),
                    'customValues' => $option->getCustomValues() ?: null,
                    'fields' => $fields,
                ];
            }
            $slots[] = [
                'name' => $slot->getName(),
                'description' => $slot->getDescription(),
                'template' => $slot->getTemplate(),
                'minSelect' => $slot->getMinSelect(),
                'maxSelect' => $slot->getMaxSelect(),
                'position' => $slot->getPosition(),
                'options' => $options,
            ];
        }

        return [
            'key' => $set->getId(),
            'name' => $set->getName(),
            'description' => $set->getDescription(),
            'active' => $set->isActive(),
            'priceFormula' => $set->getPriceFormula(),
            'slots' => $slots,
            'assignments' => array_values($assignedProductNumbers),
        ];
    }

    /**
     * @param list<array<string, mixed>> $sets
     *
     * @return array<string, mixed>
     */
    public static function document(array $sets): array
    {
        return ['format' => self::FORMAT, 'version' => self::VERSION, 'sets' => $sets];
    }

    /**
     * Prüft ein Austauschdokument und baut daraus die Schreib-Daten.
     *
     * @param array<string, mixed> $document
     * @param array<string, string> $productIds Produktnummer => Produkt-ID
     * @param array<string, string> $existingSetIds Setname => vorhandene Set-ID (wird ersetzt)
     * @param callable(): string $newId
     *
     * @return array{
     *   sets: list<array{id: string, name: string, existing: bool, base: array<string, mixed>, slots: list<array<string, mixed>>, productIds: list<string>}>,
     *   errors: list<string>
     * }
     */
    public static function plan(
        array $document,
        array $productIds,
        array $existingSetIds,
        callable $newId,
        FormulaEvaluator $formula
    ): array {
        $errors = [];

        if (($document['format'] ?? null) !== self::FORMAT || ($document['version'] ?? null) !== self::VERSION) {
            return ['sets' => [], 'errors' => ['Das ist keine gültige Set-Exportdatei (Format oder Version unbekannt).']];
        }
        $sets = $document['sets'] ?? null;
        if (!\is_array($sets) || $sets === []) {
            return ['sets' => [], 'errors' => ['Die Datei enthält keine Sets.']];
        }

        // Schlüssel → neue/ersetzte Set-ID, damit verschachtelte Sets verknüpft werden können.
        $ids = [];
        $names = [];
        foreach ($sets as $i => $set) {
            $label = 'Set #' . ($i + 1);
            if (!\is_array($set) || !\is_string($set['key'] ?? null) || $set['key'] === '') {
                $errors[] = $label . ': "key" fehlt.';
                continue;
            }
            if (isset($ids[$set['key']])) {
                $errors[] = $label . ': "key" ist doppelt vergeben.';
                continue;
            }
            if (!\is_string($set['name'] ?? null) || trim($set['name']) === '') {
                $errors[] = $label . ': Name fehlt.';
                continue;
            }
            if (isset($names[$set['name']])) {
                $errors[] = $label . ': Der Name "' . $set['name'] . '" kommt doppelt in der Datei vor.';
                continue;
            }
            $names[$set['name']] = true;
            $ids[$set['key']] = $existingSetIds[$set['name']] ?? $newId();
        }

        $planned = [];
        foreach ($sets as $set) {
            if (!\is_array($set) || !isset($ids[$set['key'] ?? ''])) {
                continue;
            }
            $where = 'Set "' . $set['name'] . '"';

            self::checkFormula($set['priceFormula'] ?? null, $where, $formula, $errors);

            $slots = [];
            foreach (\is_array($set['slots'] ?? null) ? array_values($set['slots']) : [] as $si => $slot) {
                $slotWhere = $where . ' / Slot ' . ($si + 1);
                if (!\is_array($slot) || !\is_string($slot['name'] ?? null) || trim($slot['name']) === '') {
                    $errors[] = $slotWhere . ': Name fehlt.';
                    continue;
                }
                $slotWhere = $where . ' / Slot "' . $slot['name'] . '"';

                $template = $slot['template'] ?? 'select';
                if (!\in_array($template, SlotDefinition::TEMPLATES, true)) {
                    $errors[] = $slotWhere . ': Unbekannte Vorlage "' . (\is_string($template) ? $template : '?') . '".';
                }
                $min = (int) ($slot['minSelect'] ?? 0);
                $max = isset($slot['maxSelect']) ? (int) $slot['maxSelect'] : null;
                if ($max !== null && $max < $min) {
                    $errors[] = $slotWhere . ': Höchstens ist kleiner als Mindestens.';
                }

                $options = [];
                foreach (\is_array($slot['options'] ?? null) ? array_values($slot['options']) : [] as $oi => $option) {
                    $optWhere = $slotWhere . ' / Option ' . ($oi + 1);
                    if (!\is_array($option)) {
                        $errors[] = $optWhere . ': ungültig.';
                        continue;
                    }
                    $row = self::planOption($option, $optWhere, $productIds, $ids, $newId, $formula, $errors);
                    if ($row !== null) {
                        $options[] = $row;
                    }
                }

                $slots[] = [
                    'id' => $newId(),
                    'setId' => $ids[$set['key']],
                    'name' => $slot['name'],
                    'description' => \is_string($slot['description'] ?? null) ? $slot['description'] : null,
                    'template' => $template,
                    'minSelect' => $min,
                    'maxSelect' => $max,
                    'position' => (int) ($slot['position'] ?? $si),
                    'options' => $options,
                ];
            }

            $assigned = [];
            foreach (\is_array($set['assignments'] ?? null) ? $set['assignments'] : [] as $number) {
                if (!\is_string($number) || !isset($productIds[$number])) {
                    $errors[] = $where . ': Zuweisung zu unbekanntem Produkt "' . (\is_string($number) ? $number : '?') . '".';
                    continue;
                }
                $assigned[$productIds[$number]] = true;
            }

            $planned[] = [
                'id' => $ids[$set['key']],
                'name' => $set['name'],
                'existing' => isset($existingSetIds[$set['name']]),
                'base' => [
                    'id' => $ids[$set['key']],
                    'name' => $set['name'],
                    'description' => \is_string($set['description'] ?? null) ? $set['description'] : null,
                    'active' => (bool) ($set['active'] ?? true),
                    'priceFormula' => self::formulaOrNull($set['priceFormula'] ?? null),
                ],
                'slots' => $slots,
                'productIds' => array_keys($assigned),
            ];
        }

        return ['sets' => $planned, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $option
     * @param array<string, string> $productIds
     * @param array<string, string> $setIds
     * @param callable(): string $newId
     * @param list<string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planOption(array $option, string $where, array $productIds, array $setIds, callable $newId, FormulaEvaluator $formula, array &$errors): ?array
    {
        $type = $option['type'] ?? OptionDefinition::TYPE_PRODUCT;
        if (!\in_array($type, [OptionDefinition::TYPE_PRODUCT, OptionDefinition::TYPE_STATIC], true)) {
            $errors[] = $where . ': Unbekannter Typ.';

            return null;
        }

        $nested = $option['nestedSet'] ?? null;
        if ($nested !== null && (!\is_string($nested) || !isset($setIds[$nested]))) {
            $errors[] = $where . ': Verschachteltes Set "' . (\is_string($nested) ? $nested : '?') . '" ist nicht in der Datei enthalten.';
            $nested = null;
        }

        $productId = null;
        if ($type === OptionDefinition::TYPE_PRODUCT) {
            $number = $option['productNumber'] ?? null;
            if (!\is_string($number) || !isset($productIds[$number])) {
                $errors[] = $where . ': Produkt "' . (\is_string($number) ? $number : '?') . '" nicht gefunden.';

                return null;
            }
            $productId = $productIds[$number];
        }

        $min = (int) ($option['quantityMin'] ?? 1);
        $max = (int) ($option['quantityMax'] ?? 1);
        if ($min < 0 || $max < $min) {
            $errors[] = $where . ': Ungültige Mengen (Min/Max).';
        }

        self::checkFormula($option['priceFormula'] ?? null, $where, $formula, $errors);

        $custom = null;
        if (\is_array($option['customValues'] ?? null)) {
            $custom = [];
            foreach ($option['customValues'] as $k => $v) {
                if (\is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k) === 1 && is_numeric($v)) {
                    $custom[$k] = $v + 0;
                } else {
                    $errors[] = $where . ': Ungültiger eigener Wert "' . (\is_string($k) ? $k : '?') . '".';
                }
            }
            $custom = $custom ?: null;
        }

        $fields = [];
        $names = [];
        foreach (\is_array($option['fields'] ?? null) ? array_values($option['fields']) : [] as $fi => $field) {
            $fWhere = $where . ' / Feld ' . ($fi + 1);
            if (!\is_array($field)) {
                $errors[] = $fWhere . ': ungültig.';
                continue;
            }
            $fType = $field['type'] ?? FieldDefinition::TYPE_TEXT;
            $fName = $field['name'] ?? null;
            if (!\in_array($fType, FieldDefinition::TYPES, true)) {
                $errors[] = $fWhere . ': Unbekannter Feldtyp.';
                continue;
            }
            if (!\is_string($fName) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fName) !== 1) {
                $errors[] = $fWhere . ': Ungültiger Feldname.';
                continue;
            }
            if (isset($names[$fName])) {
                $errors[] = $fWhere . ': Feldname "' . $fName . '" doppelt in einer Option.';
                continue;
            }
            $names[$fName] = true;
            if (!\is_string($field['label'] ?? null) || $field['label'] === '') {
                $errors[] = $fWhere . ': Beschriftung fehlt.';
                continue;
            }
            self::checkFormula($field['priceFormula'] ?? null, $fWhere, $formula, $errors);

            $fields[] = [
                'id' => $newId(),
                'type' => $fType,
                'name' => $fName,
                'label' => $field['label'],
                'required' => (bool) ($field['required'] ?? false),
                'choices' => \is_array($field['choices'] ?? null) && $field['choices'] !== [] ? $field['choices'] : null,
                'priceFormula' => self::formulaOrNull($field['priceFormula'] ?? null),
                'position' => (int) ($field['position'] ?? $fi),
            ];
        }

        return [
            'id' => $newId(),
            'type' => $type,
            'productId' => $productId,
            'productVersionId' => $productId !== null ? Defaults::LIVE_VERSION : null,
            'label' => \is_string($option['label'] ?? null) ? $option['label'] : null,
            'quantityMin' => $min,
            'quantityMax' => $max,
            'priceFormula' => self::formulaOrNull($option['priceFormula'] ?? null),
            'preselected' => (bool) ($option['preselected'] ?? false),
            'position' => (int) ($option['position'] ?? 0),
            'nestedSetId' => $nested !== null ? $setIds[$nested] : null,
            'customValues' => $custom,
            'fields' => $fields,
        ];
    }

    private static function formulaOrNull(mixed $formula): ?string
    {
        return \is_string($formula) && trim($formula) !== '' ? $formula : null;
    }

    /**
     * @param list<string> $errors
     */
    private static function checkFormula(mixed $formula, string $where, FormulaEvaluator $evaluator, array &$errors): void
    {
        if (!\is_string($formula) || trim($formula) === '') {
            return;
        }
        try {
            $evaluator->validate($formula);
        } catch (FormulaException $e) {
            $errors[] = $where . ': Ungültige Preisformel – ' . $e->getMessage();
        }
    }
}
