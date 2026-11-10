<?php

declare(strict_types=1);

namespace SetConfigurator\Service;

use SetConfigurator\Core\Content\Field\FieldDefinition;
use SetConfigurator\Core\Content\Field\FieldEntity;
use SetConfigurator\Core\Content\Option\OptionEntity;
use SetConfigurator\Core\Content\Set\SetEntity;
use SetConfigurator\Service\Formula\FormulaEvaluator;
use SetConfigurator\Service\Formula\FormulaException;

/**
 * Berechnet den Preis einer (bereits validierten) Set-Auswahl.
 *
 * Regeln
 * - Einzelpreis einer Option = Formel der Option, sonst Produktpreis (Produktoption) bzw. 0 (statische Option).
 *   Ein verschachteltes Set addiert seine Summe zum Einzelpreis, außer die Formel nutzt die Variable "nested" selbst.
 * - Jedes Zusatzfeld mit Formel addiert seinen Betrag zum Einzelpreis.
 * - Zeilensumme = Einzelpreis × Menge. Optionssumme = Summe aller Zeilen.
 * - Gesamtpreis = Set-Formel, sonst Grundpreis + Optionssumme. Nie kleiner als 0, auf 2 Stellen gerundet.
 *
 * Variablen in Formeln
 *   Option:  price, quantity, base, nested, option.<schlüssel>, product.<schlüssel>, field.<feldname>
 *   Feld:    wie Option, zusätzlich value (Zahlenwert des Feldes)
 *   Set:     base, options, price (= base)
 */
class PriceCalculator
{
    public function __construct(private readonly FormulaEvaluator $formula = new FormulaEvaluator())
    {
    }

    /**
     * @param array<string, mixed> $selection
     * @param callable(string): (array{price: float, custom?: array<string, int|float>}|null) $productInfo
     * @param (callable(string): ?SetEntity)|null $loadSet
     *
     * @return array{total: float, options: float, lines: list<array<string, mixed>>}
     *
     * @throws FormulaException
     */
    public function calculate(
        SetEntity $set,
        array $selection,
        float $basePrice,
        callable $productInfo,
        ?callable $loadSet = null,
        int $depth = 0
    ): array {
        $slotsInput = \is_array($selection['slots'] ?? null) ? $selection['slots'] : [];
        $lines = [];
        $optionsSum = 0.0;

        foreach ($set->getSlots() ?? [] as $slot) {
            $entries = $slotsInput[$slot->getId()] ?? [];
            if (!\is_array($entries)) {
                continue;
            }
            $byId = [];
            foreach ($slot->getOptions() ?? [] as $option) {
                $byId[$option->getId()] = $option;
            }

            foreach ($entries as $entry) {
                if (!\is_array($entry) || !isset($byId[(string) ($entry['optionId'] ?? '')])) {
                    continue;
                }
                $line = $this->line($byId[(string) $entry['optionId']], $entry, $slot->getId(), $basePrice, $productInfo, $loadSet, $depth);
                $lines[] = $line;
                $optionsSum += $line['total'];
            }
        }

        $total = $set->getPriceFormula() !== null && trim($set->getPriceFormula()) !== ''
            ? $this->formula->evaluate($set->getPriceFormula(), ['base' => $basePrice, 'price' => $basePrice, 'options' => $optionsSum])
            : $basePrice + $optionsSum;

        return [
            'total' => round(max(0.0, $total), 2),
            'options' => round($optionsSum, 2),
            'lines' => $lines,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param callable(string): (array{price: float, custom?: array<string, int|float>}|null) $productInfo
     * @param (callable(string): ?SetEntity)|null $loadSet
     *
     * @return array<string, mixed>
     */
    private function line(
        OptionEntity $option,
        array $entry,
        string $slotId,
        float $basePrice,
        callable $productInfo,
        ?callable $loadSet,
        int $depth
    ): array {
        $quantity = isset($entry['quantity']) && is_numeric($entry['quantity']) ? max(0, (int) $entry['quantity']) : $option->getQuantityMin();

        $productId = null;
        $price = 0.0;
        $vars = ['base' => $basePrice, 'quantity' => (float) $quantity];

        if ($option->isProduct() && $option->getProductId() !== null) {
            $productId = \is_string($entry['productId'] ?? null) && $entry['productId'] !== '' ? $entry['productId'] : $option->getProductId();
            $info = $productInfo($productId);
            if ($info === null) {
                throw new FormulaException('Produkt der Option nicht gefunden.');
            }
            $price = (float) $info['price'];
            foreach ($info['custom'] ?? [] as $k => $v) {
                if (is_numeric($v)) {
                    $vars['product.' . $k] = (float) $v;
                }
            }
        }
        $vars['price'] = $price;

        foreach ($option->getCustomValues() as $k => $v) {
            if (is_numeric($v)) {
                $vars['option.' . $k] = (float) $v;
            }
        }

        // Zusatzfelder → Zahlenwerte
        $fieldValues = \is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
        $fieldNumbers = [];
        foreach ($option->getFields() ?? [] as $field) {
            $n = $this->fieldNumber($field, $fieldValues[$field->getId()] ?? null);
            $fieldNumbers[$field->getId()] = $n;
            $vars['field.' . $field->getName()] = $n;
        }

        // Verschachteltes Set
        $nestedTotal = 0.0;
        $nestedLines = [];
        if ($option->getNestedSetId() !== null && $loadSet !== null && $depth < SelectionValidator::MAX_NESTING) {
            $nestedSet = $loadSet($option->getNestedSetId());
            if ($nestedSet !== null) {
                $nested = $this->calculate(
                    $nestedSet,
                    \is_array($entry['nested'] ?? null) ? $entry['nested'] : [],
                    0.0,
                    $productInfo,
                    $loadSet,
                    $depth + 1
                );
                $nestedTotal = $nested['total'];
                $nestedLines = $nested['lines'];
            }
        }
        $vars['nested'] = $nestedTotal;

        $formula = $option->getPriceFormula();
        if ($formula !== null && trim($formula) !== '') {
            $unit = $this->formula->evaluate($formula, $vars);
            if (!\in_array('nested', $this->formula->variables($formula), true)) {
                $unit += $nestedTotal;
            }
        } else {
            $unit = $price + $nestedTotal;
        }

        foreach ($option->getFields() ?? [] as $field) {
            $ff = $field->getPriceFormula();
            if ($ff !== null && trim($ff) !== '') {
                $unit += $this->formula->evaluate($ff, $vars + ['value' => $fieldNumbers[$field->getId()]]);
            }
        }

        $unit = round($unit, 4);

        return [
            'slotId' => $slotId,
            'optionId' => $option->getId(),
            'productId' => $productId,
            'label' => $option->getLabel(),
            'quantity' => $quantity,
            'unitPrice' => $unit,
            'total' => round($unit * $quantity, 4),
            'nested' => $nestedLines,
        ];
    }

    private function fieldNumber(FieldEntity $field, mixed $value): float
    {
        switch ($field->getType()) {
            case FieldDefinition::TYPE_CHECKBOX:
                return ($value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true') ? 1.0 : 0.0;
            case FieldDefinition::TYPE_NUMBER:
                return is_numeric($value) ? (float) $value : 0.0;
            case FieldDefinition::TYPE_SELECT:
                foreach ($field->getChoices() as $choice) {
                    if ((string) ($choice['value'] ?? '') === (string) $value) {
                        return (float) ($choice['number'] ?? 0);
                    }
                }

                return 0.0;
            default: // text, upload: 1 = ausgefüllt
                return ($value === null || $value === '' || $value === false) ? 0.0 : 1.0;
        }
    }
}
