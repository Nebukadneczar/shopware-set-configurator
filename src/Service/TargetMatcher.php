<?php

declare(strict_types=1);

namespace SetConfigurator\Service;

/**
 * Wählt aus Zuweisungen nach Kategorie/Hersteller das passende Set (reine Logik, ohne Datenbank).
 *
 * Rangfolge: Kategorie vor Hersteller. Innerhalb der Kategorien gewinnt die genaueste
 * (Produkt direkt in dieser Kategorie vor einer Oberkategorie), bei Gleichstand das früher angelegte Set.
 * Die Zuweisung direkt am Produkt (und am Elternprodukt) wird vorher im SetResolver geprüft und hat Vorrang.
 */
final class TargetMatcher
{
    public const TYPE_CATEGORY = 'category';
    public const TYPE_MANUFACTURER = 'manufacturer';

    /**
     * @param list<array{setId: string, type: string, categoryId: ?string, manufacturerId: ?string, includeChildren: bool, createdAt: int}> $targets
     * @param list<string> $categoryIds direkt zugewiesene Kategorien des Produkts (inkl. geerbt)
     * @param list<string> $categoryTree zugewiesene Kategorien und alle Oberkategorien
     * @param list<string> $manufacturerIds Hersteller des Produkts (bzw. des Elternprodukts)
     */
    public static function pick(array $targets, array $categoryIds, array $categoryTree, array $manufacturerIds): ?string
    {
        $best = null; // [rang, score, createdAt, setId]

        foreach ($targets as $t) {
            if ($t['type'] === self::TYPE_CATEGORY && $t['categoryId'] !== null) {
                if (\in_array($t['categoryId'], $categoryIds, true)) {
                    $score = 0;
                } elseif ($t['includeChildren'] && \in_array($t['categoryId'], $categoryTree, true)) {
                    $score = 1;
                } else {
                    continue;
                }
                $rank = 0;
            } elseif ($t['type'] === self::TYPE_MANUFACTURER && $t['manufacturerId'] !== null) {
                if (!\in_array($t['manufacturerId'], $manufacturerIds, true)) {
                    continue;
                }
                $rank = 1;
                $score = 0;
            } else {
                continue;
            }

            $candidate = [$rank, $score, $t['createdAt'], $t['setId']];
            if ($best === null || $candidate < $best) {
                $best = $candidate;
            }
        }

        return $best[3] ?? null;
    }
}
