<?php

declare(strict_types=1);

namespace SetConfigurator\Service;

use SetConfigurator\Core\Content\Set\SetEntity;

/**
 * Reine Hilfsfunktionen für Auswahlen (ohne Datenbank, gut testbar).
 */
final class SelectionHelper
{
    /**
     * Reduziert eine Kundeneingabe auf das bekannte Format: nur existierende Slots/Optionen/Felder,
     * Menge als Zahl (Standard = Mindestmenge), Feldwerte nur als Skalare. Unbekanntes wird verworfen.
     * Die Prüfung auf Gültigkeit macht danach der SelectionValidator.
     *
     * @param array<string, mixed> $selection
     * @param (callable(string): ?SetEntity)|null $loadSet
     *
     * @return array<string, mixed>
     */
    public static function normalize(SetEntity $set, array $selection, ?callable $loadSet = null, int $depth = 0): array
    {
        $input = \is_array($selection['slots'] ?? null) ? $selection['slots'] : [];
        $slots = [];

        foreach ($set->getSlots() ?? [] as $slot) {
            $entries = $input[$slot->getId()] ?? null;
            if (!\is_array($entries)) {
                continue;
            }
            $options = [];
            foreach ($slot->getOptions() ?? [] as $option) {
                $options[$option->getId()] = $option;
            }

            $clean = [];
            foreach (array_values($entries) as $entry) {
                if (!\is_array($entry) || !\is_string($entry['optionId'] ?? null) || !isset($options[$entry['optionId']])) {
                    continue;
                }
                $option = $options[$entry['optionId']];

                $quantity = $entry['quantity'] ?? $option->getQuantityMin();
                $row = [
                    'optionId' => $option->getId(),
                    'quantity' => is_numeric($quantity) && (float) $quantity == (int) $quantity ? (int) $quantity : -1,
                ];

                if (\is_string($entry['productId'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $entry['productId']) === 1) {
                    $row['productId'] = $entry['productId'];
                }

                $fields = [];
                $values = \is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
                foreach ($option->getFields() ?? [] as $field) {
                    $value = $values[$field->getId()] ?? null;
                    if (\is_scalar($value)) {
                        $fields[$field->getId()] = $value;
                    }
                }
                $row['fields'] = $fields;

                if ($option->getNestedSetId() !== null && $loadSet !== null && $depth < SelectionValidator::MAX_NESTING) {
                    $nestedSet = $loadSet($option->getNestedSetId());
                    if ($nestedSet !== null) {
                        $row['nested'] = self::normalize(
                            $nestedSet,
                            \is_array($entry['nested'] ?? null) ? $entry['nested'] : [],
                            $loadSet,
                            $depth + 1
                        );
                    }
                }
                $clean[] = $row;
            }
            $slots[$slot->getId()] = $clean;
        }

        return ['slots' => $slots];
    }

    /**
     * Benötigte Lagermenge je Produkt für EINE Einheit des Haupt-Produkts.
     * Verschachtelte Sets werden mit der Menge ihrer Eltern-Option multipliziert.
     *
     * @param array<string, mixed> $selection normalisierte Auswahl
     * @param (callable(string): ?SetEntity)|null $loadSet
     *
     * @return array<string, int> productId => Menge
     */
    public static function requiredStock(SetEntity $set, array $selection, ?callable $loadSet = null, int $multiplier = 1, int $depth = 0): array
    {
        $input = \is_array($selection['slots'] ?? null) ? $selection['slots'] : [];
        $need = [];

        foreach ($set->getSlots() ?? [] as $slot) {
            $options = [];
            foreach ($slot->getOptions() ?? [] as $option) {
                $options[$option->getId()] = $option;
            }
            foreach ($input[$slot->getId()] ?? [] as $entry) {
                $option = $options[$entry['optionId'] ?? ''] ?? null;
                if ($option === null) {
                    continue;
                }
                $quantity = max(0, (int) ($entry['quantity'] ?? $option->getQuantityMin())) * $multiplier;

                if ($option->isProduct() && $option->getProductId() !== null) {
                    $id = $entry['productId'] ?? $option->getProductId();
                    $need[$id] = ($need[$id] ?? 0) + $quantity;
                }

                if ($option->getNestedSetId() !== null && $loadSet !== null && $depth < SelectionValidator::MAX_NESTING) {
                    $nestedSet = $loadSet($option->getNestedSetId());
                    if ($nestedSet !== null) {
                        foreach (self::requiredStock($nestedSet, $entry['nested'] ?? [], $loadSet, $quantity, $depth + 1) as $id => $q) {
                            $need[$id] = ($need[$id] ?? 0) + $q;
                        }
                    }
                }
            }
        }

        return $need;
    }

    /**
     * Stabile ID einer Konfiguration. Gleiche Auswahl ergibt gleiche ID, damit der Warenkorb Mengen zusammenführt.
     *
     * @param array<string, mixed> $selection normalisierte Auswahl
     */
    public static function fingerprint(string $productId, array $selection): string
    {
        return md5($productId . '|' . json_encode(self::sortRecursive($selection), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    private static function sortRecursive(array $data): array
    {
        foreach ($data as $k => $v) {
            if (\is_array($v)) {
                $data[$k] = self::sortRecursive($v);
            }
        }
        if (!array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }
}
