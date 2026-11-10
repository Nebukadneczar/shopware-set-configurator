<?php

declare(strict_types=1);

namespace SetConfigurator\Tests\Service;

use SetConfigurator\Service\SelectionHelper;
use PHPUnit\Framework\TestCase;

final class SelectionHelperTest extends TestCase
{
    private const P1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const P2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testNormalizeDropsUnknownAndCoercesQuantity(): void
    {
        $set = Builder::set('s', [
            Builder::slot('a', [
                Builder::option('o1', ['quantityMin' => 2, 'quantityMax' => 4], [Builder::field('f1', 'text', 'x')]),
            ]),
        ]);

        $n = SelectionHelper::normalize($set, ['slots' => [
            'unbekannt' => [['optionId' => 'o1']],
            'a' => [
                ['optionId' => 'o1', 'fields' => ['f1' => 'Hallo', 'fremd' => 'x', 'f2' => ['array']], 'evil' => 1],
                ['optionId' => 'gibtsnicht'],
                'kein array',
            ],
        ]]);

        self::assertSame(['slots' => ['a' => [['optionId' => 'o1', 'quantity' => 2, 'fields' => ['f1' => 'Hallo']]]]], $n);
    }

    public function testNormalizeMarksNonIntegerQuantity(): void
    {
        $set = Builder::set('s', [Builder::slot('a', [Builder::option('o1', ['quantityMax' => 5])])]);

        $n = SelectionHelper::normalize($set, ['slots' => ['a' => [['optionId' => 'o1', 'quantity' => '1.5']]]]);

        self::assertSame(-1, $n['slots']['a'][0]['quantity']);
    }

    public function testRequiredStockMultipliesNested(): void
    {
        $inner = Builder::set('inner', [Builder::slot('i', [
            Builder::option('io', ['type' => 'product', 'productId' => self::P2, 'quantityMax' => 9]),
        ])]);
        $outer = Builder::set('outer', [Builder::slot('a', [
            Builder::option('o1', ['type' => 'product', 'productId' => self::P1, 'quantityMax' => 9, 'nestedSetId' => 'inner']),
        ])]);
        $load = static fn (string $id) => $id === 'inner' ? $inner : null;

        $selection = SelectionHelper::normalize(
            $outer,
            ['slots' => ['a' => [['optionId' => 'o1', 'quantity' => 2, 'nested' => ['slots' => ['i' => [['optionId' => 'io', 'quantity' => 3]]]]]]]],
            $load
        );

        // o1: 2 x P1; verschachtelt: 3 x P2 je o1 → 6 x P2
        self::assertSame([self::P1 => 2, self::P2 => 6], SelectionHelper::requiredStock($outer, $selection, $load));
    }

    public function testFingerprintIsStableAndSensitive(): void
    {
        $a = ['slots' => ['a' => [['optionId' => 'o1', 'quantity' => 1, 'fields' => ['f2' => 'x', 'f1' => 'y']]]]];
        $b = ['slots' => ['a' => [['quantity' => 1, 'optionId' => 'o1', 'fields' => ['f1' => 'y', 'f2' => 'x']]]]];
        $c = ['slots' => ['a' => [['optionId' => 'o1', 'quantity' => 2, 'fields' => ['f2' => 'x', 'f1' => 'y']]]]];

        self::assertSame(SelectionHelper::fingerprint('p', $a), SelectionHelper::fingerprint('p', $b));
        self::assertNotSame(SelectionHelper::fingerprint('p', $a), SelectionHelper::fingerprint('p', $c));
        self::assertNotSame(SelectionHelper::fingerprint('p', $a), SelectionHelper::fingerprint('q', $a));
    }
}
