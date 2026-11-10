<?php

declare(strict_types=1);

namespace SetConfigurator\Tests\Service;

use SetConfigurator\Service\SelectionValidator;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductEntity;

final class SelectionValidatorTest extends TestCase
{
    private SelectionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SelectionValidator();
    }

    /**
     * @param list<array<string, mixed>> $violations
     *
     * @return list<string>
     */
    private function codes(array $violations): array
    {
        return array_column($violations, 'code');
    }

    public function testValidSelection(): void
    {
        $set = Builder::set('s1', [
            Builder::slot('a', [Builder::option('o1'), Builder::option('o2')], 1, 1),
        ]);

        self::assertSame([], $this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1']]]]));
    }

    public function testMinAndMaxPerSlot(): void
    {
        $set = Builder::set('s1', [
            Builder::slot('a', [Builder::option('o1'), Builder::option('o2')], 1, 1),
        ]);

        self::assertSame(['too_few'], $this->codes($this->validator->validate($set, [])));
        self::assertSame(
            ['too_many'],
            $this->codes($this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1'], ['optionId' => 'o2']]]]))
        );
    }

    public function testUnknownSlotAndOptionAndDuplicate(): void
    {
        $set = Builder::set('s1', [Builder::slot('a', [Builder::option('o1')])]);

        $codes = $this->codes($this->validator->validate($set, ['slots' => [
            'zzz' => [],
            'a' => [['optionId' => 'x'], ['optionId' => 'o1'], ['optionId' => 'o1']],
        ]]));

        self::assertContains('unknown_slot', $codes);
        self::assertContains('unknown_option', $codes);
        self::assertContains('duplicate_option', $codes);
    }

    public function testQuantityBounds(): void
    {
        $set = Builder::set('s1', [
            Builder::slot('a', [Builder::option('o1', ['quantityMin' => 1, 'quantityMax' => 3])]),
        ]);

        self::assertSame([], $this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1', 'quantity' => 3]]]]));
        self::assertSame(
            ['quantity_out_of_range'],
            $this->codes($this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1', 'quantity' => 4]]]]))
        );
        self::assertSame(
            ['quantity_out_of_range'],
            $this->codes($this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1', 'quantity' => 'abc']]]]))
        );
    }

    public function testFixedQuantityMustMatch(): void
    {
        $set = Builder::set('s1', [
            Builder::slot('a', [Builder::option('o1', ['quantityMin' => 2, 'quantityMax' => 2])]),
        ]);

        // Ohne Angabe gilt der feste Wert.
        self::assertSame([], $this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1']]]]));
        self::assertSame(
            ['quantity_out_of_range'],
            $this->codes($this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1', 'quantity' => 5]]]]))
        );
    }

    public function testFieldRules(): void
    {
        $fields = [
            Builder::field('f1', 'text', 'gravur', ['required' => true]),
            Builder::field('f2', 'select', 'farbe', ['choices' => [['value' => 'rot'], ['value' => 'blau']]]),
            Builder::field('f3', 'number', 'breite'),
            Builder::field('f4', 'checkbox', 'agb', ['required' => true]),
            Builder::field('f5', 'upload', 'logo'),
        ];
        $set = Builder::set('s1', [Builder::slot('a', [Builder::option('o1', [], $fields)])]);

        $codes = $this->codes($this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1', 'fields' => [
            'f2' => 'gruen',
            'f3' => 'abc',
            'f5' => 'kein-medium',
        ]]]]]));
        sort($codes);
        // f1 fehlt, f2 ungültig, f3 keine Zahl, f4 Pflicht-Checkbox nicht gesetzt, f5 keine Medien-ID
        self::assertSame(['field_invalid', 'field_invalid', 'field_invalid', 'field_required', 'field_required'], $codes);

        self::assertSame([], $this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1', 'fields' => [
            'f1' => 'Max',
            'f2' => 'rot',
            'f3' => '12.5',
            'f4' => true,
            'f5' => str_repeat('a', 32),
        ]]]]]));
    }

    public function testVariantRequiredForParentProduct(): void
    {
        $product = new ProductEntity();
        $product->setId('p-parent');
        $product->setChildCount(3);

        $set = Builder::set('s1', [
            Builder::slot('a', [Builder::option('o1', ['type' => 'product', 'productId' => 'p-parent', 'product' => $product])]),
        ]);

        self::assertSame(
            ['variant_required'],
            $this->codes($this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1']]]]))
        );

        $isVariantOf = static fn (string $variant, string $parent): bool => $variant === 'v1' && $parent === 'p-parent';

        self::assertSame(
            [],
            $this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1', 'productId' => 'v1']]]], null, $isVariantOf)
        );
        self::assertSame(
            ['variant_invalid'],
            $this->codes($this->validator->validate($set, ['slots' => ['a' => [['optionId' => 'o1', 'productId' => 'fremd']]]], null, $isVariantOf))
        );
    }

    public function testNestedSetIsValidated(): void
    {
        $inner = Builder::set('inner', [Builder::slot('i', [Builder::option('io')], 1, 1)]);
        $outer = Builder::set('outer', [
            Builder::slot('a', [Builder::option('o1', ['nestedSetId' => 'inner'])]),
        ]);
        $load = static fn (string $id) => $id === 'inner' ? $inner : null;

        $violations = $this->validator->validate($outer, ['slots' => ['a' => [['optionId' => 'o1']]]], $load);
        self::assertSame(['too_few'], $this->codes($violations));
        self::assertSame('o1/', $violations[0]['path']);

        self::assertSame(
            [],
            $this->validator->validate(
                $outer,
                ['slots' => ['a' => [['optionId' => 'o1', 'nested' => ['slots' => ['i' => [['optionId' => 'io']]]]]]]],
                $load
            )
        );
    }

    public function testNestedCycleIsRejected(): void
    {
        $self = Builder::set('loop', [Builder::slot('a', [Builder::option('o1', ['nestedSetId' => 'loop'])])]);
        $load = static fn (string $id) => $id === 'loop' ? $self : null;

        self::assertSame(
            ['nested_invalid'],
            $this->codes($this->validator->validate($self, ['slots' => ['a' => [['optionId' => 'o1']]]], $load))
        );
    }
}
