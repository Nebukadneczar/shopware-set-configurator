<?php

declare(strict_types=1);

namespace SetConfigurator\Tests\Service;

use SetConfigurator\Service\TargetMatcher;
use PHPUnit\Framework\TestCase;

final class TargetMatcherTest extends TestCase
{
    /**
     * @return array{setId: string, type: string, categoryId: ?string, manufacturerId: ?string, includeChildren: bool, createdAt: int}
     */
    private function cat(string $set, string $cat, bool $children = true, int $created = 1): array
    {
        return ['setId' => $set, 'type' => 'category', 'categoryId' => $cat, 'manufacturerId' => null, 'includeChildren' => $children, 'createdAt' => $created];
    }

    /**
     * @return array{setId: string, type: string, categoryId: ?string, manufacturerId: ?string, includeChildren: bool, createdAt: int}
     */
    private function man(string $set, string $man, int $created = 1): array
    {
        return ['setId' => $set, 'type' => 'manufacturer', 'categoryId' => null, 'manufacturerId' => $man, 'includeChildren' => false, 'createdAt' => $created];
    }

    public function testNoMatchReturnsNull(): void
    {
        self::assertNull(TargetMatcher::pick([$this->cat('A', 'c9'), $this->man('B', 'm9')], ['c1'], ['c1', 'c0'], ['m1']));
        self::assertNull(TargetMatcher::pick([], ['c1'], ['c1'], ['m1']));
    }

    public function testDirectCategoryMatch(): void
    {
        self::assertSame('A', TargetMatcher::pick([$this->cat('A', 'c1', false)], ['c1'], ['c1'], []));
    }

    public function testParentCategoryOnlyWithIncludeChildren(): void
    {
        self::assertSame('A', TargetMatcher::pick([$this->cat('A', 'c0', true)], ['c1'], ['c1', 'c0'], []));
        self::assertNull(TargetMatcher::pick([$this->cat('A', 'c0', false)], ['c1'], ['c1', 'c0'], []));
    }

    public function testMostSpecificCategoryWins(): void
    {
        $targets = [$this->cat('Oberkategorie', 'c0', true, 1), $this->cat('Direkt', 'c1', true, 5)];

        self::assertSame('Direkt', TargetMatcher::pick($targets, ['c1'], ['c1', 'c0'], []));
    }

    public function testCategoryBeatsManufacturer(): void
    {
        $targets = [$this->man('Hersteller', 'm1', 1), $this->cat('Kategorie', 'c0', true, 9)];

        self::assertSame('Kategorie', TargetMatcher::pick($targets, ['c1'], ['c1', 'c0'], ['m1']));
    }

    public function testManufacturerUsedWhenNoCategoryMatches(): void
    {
        self::assertSame('Hersteller', TargetMatcher::pick([$this->man('Hersteller', 'm1'), $this->cat('X', 'c9')], ['c1'], ['c1'], ['m1']));
    }

    public function testTieGoesToEarlierCreatedSet(): void
    {
        $targets = [$this->cat('Spaeter', 'c1', true, 20), $this->cat('Frueher', 'c1', true, 10)];

        self::assertSame('Frueher', TargetMatcher::pick($targets, ['c1'], ['c1'], []));
    }
}
