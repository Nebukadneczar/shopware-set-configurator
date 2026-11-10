<?php

declare(strict_types=1);

namespace SetConfigurator\Tests\Service;

use SetConfigurator\Service\Formula\FormulaException;
use SetConfigurator\Service\PriceCalculator;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    /** @var callable(string): array{price: float, custom?: array<string, int|float>}|null */
    private $products;

    protected function setUp(): void
    {
        $prices = [
            'p10' => ['price' => 10.0, 'custom' => ['gewicht' => 2]],
            'p25' => ['price' => 25.0],
        ];
        $this->products = static fn (string $id): ?array => $prices[$id] ?? null;
    }

    public function testBasePlusProductAndStaticOptions(): void
    {
        $set = Builder::set('s', [
            Builder::slot('a', [
                Builder::option('o1', ['type' => 'product', 'productId' => 'p10', 'quantityMin' => 1, 'quantityMax' => 5]),
                Builder::option('o2', ['priceFormula' => '7.5']),
            ]),
        ]);

        $r = (new PriceCalculator())->calculate($set, ['slots' => ['a' => [
            ['optionId' => 'o1', 'quantity' => 3],
            ['optionId' => 'o2'],
        ]]], 100.0, $this->products);

        // 100 + 3*10 + 1*7.5
        self::assertSame(137.5, $r['total']);
        self::assertSame(37.5, $r['options']);
        self::assertCount(2, $r['lines']);
    }

    public function testFormulaReferencesPriceAndCustomValues(): void
    {
        $set = Builder::set('s', [
            Builder::slot('a', [
                Builder::option('o1', [
                    'type' => 'product',
                    'productId' => 'p10',
                    'priceFormula' => 'price * 0.9 + option.aufschlag + product.gewicht',
                    'customValues' => ['aufschlag' => 1.5],
                ]),
            ]),
        ]);

        $r = (new PriceCalculator())->calculate($set, ['slots' => ['a' => [['optionId' => 'o1']]]], 0.0, $this->products);

        // 10*0.9 + 1.5 + 2
        self::assertSame(12.5, $r['total']);
    }

    public function testVariantPriceIsUsed(): void
    {
        $set = Builder::set('s', [
            Builder::slot('a', [Builder::option('o1', ['type' => 'product', 'productId' => 'p10'])]),
        ]);

        $r = (new PriceCalculator())->calculate($set, ['slots' => ['a' => [['optionId' => 'o1', 'productId' => 'p25']]]], 0.0, $this->products);

        self::assertSame(25.0, $r['total']);
    }

    public function testFieldPriceFormulas(): void
    {
        $fields = [
            Builder::field('f1', 'checkbox', 'gravur', ['priceFormula' => 'value * 4.9']),
            Builder::field('f2', 'number', 'meter', ['priceFormula' => 'value * 2']),
            Builder::field('f3', 'select', 'groesse', [
                'choices' => [['value' => 's', 'number' => 0], ['value' => 'xl', 'number' => 5]],
                'priceFormula' => 'value',
            ]),
        ];
        $set = Builder::set('s', [Builder::slot('a', [Builder::option('o1', ['priceFormula' => '10'], $fields)])]);

        $r = (new PriceCalculator())->calculate($set, ['slots' => ['a' => [['optionId' => 'o1', 'fields' => [
            'f1' => true, 'f2' => '3', 'f3' => 'xl',
        ]]]]], 0.0, $this->products);

        // 10 + 4.9 + 6 + 5
        self::assertSame(25.9, $r['total']);
    }

    public function testSetFormulaAppliesDiscountOnTotal(): void
    {
        $set = Builder::set('s', [
            Builder::slot('a', [Builder::option('o1', ['priceFormula' => '20'])]),
        ], 'round((base + options) * 0.9, 2)');

        $r = (new PriceCalculator())->calculate($set, ['slots' => ['a' => [['optionId' => 'o1']]]], 80.0, $this->products);

        self::assertSame(90.0, $r['total']);
    }

    public function testNestedSetAddsToOptionPrice(): void
    {
        $inner = Builder::set('inner', [Builder::slot('i', [Builder::option('io', ['priceFormula' => '4'])])]);
        $outer = Builder::set('outer', [
            Builder::slot('a', [Builder::option('o1', ['priceFormula' => '10', 'nestedSetId' => 'inner'])]),
        ]);
        $load = static fn (string $id) => $id === 'inner' ? $inner : null;

        $r = (new PriceCalculator())->calculate(
            $outer,
            ['slots' => ['a' => [['optionId' => 'o1', 'nested' => ['slots' => ['i' => [['optionId' => 'io']]]]]]]],
            0.0,
            $this->products,
            $load
        );

        self::assertSame(14.0, $r['total']);
    }

    public function testNestedVariableOverridesDefaultAddition(): void
    {
        $inner = Builder::set('inner', [Builder::slot('i', [Builder::option('io', ['priceFormula' => '4'])])]);
        $outer = Builder::set('outer', [
            Builder::slot('a', [Builder::option('o1', ['priceFormula' => '10 + nested * 2', 'nestedSetId' => 'inner'])]),
        ]);
        $load = static fn (string $id) => $id === 'inner' ? $inner : null;

        $r = (new PriceCalculator())->calculate(
            $outer,
            ['slots' => ['a' => [['optionId' => 'o1', 'nested' => ['slots' => ['i' => [['optionId' => 'io']]]]]]]],
            0.0,
            $this->products,
            $load
        );

        self::assertSame(18.0, $r['total']);
    }

    public function testTotalNeverNegative(): void
    {
        $set = Builder::set('s', [Builder::slot('a', [Builder::option('o1', ['priceFormula' => '-50'])])]);

        $r = (new PriceCalculator())->calculate($set, ['slots' => ['a' => [['optionId' => 'o1']]]], 10.0, $this->products);

        self::assertSame(0.0, $r['total']);
    }

    public function testUnknownProductThrows(): void
    {
        $set = Builder::set('s', [Builder::slot('a', [Builder::option('o1', ['type' => 'product', 'productId' => 'weg'])])]);

        $this->expectException(FormulaException::class);
        (new PriceCalculator())->calculate($set, ['slots' => ['a' => [['optionId' => 'o1']]]], 0.0, $this->products);
    }
}
