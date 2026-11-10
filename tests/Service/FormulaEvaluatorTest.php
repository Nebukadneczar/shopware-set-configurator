<?php

declare(strict_types=1);

namespace SetConfigurator\Tests\Service;

use SetConfigurator\Service\Formula\FormulaEvaluator;
use SetConfigurator\Service\Formula\FormulaException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormulaEvaluatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, float>, float}>
     */
    public static function valid(): iterable
    {
        yield 'zahl' => ['12.5', [], 12.5];
        yield 'punkt vor strich' => ['2 + 3 * 4', [], 14.0];
        yield 'klammern' => ['(2 + 3) * 4', [], 20.0];
        yield 'rabatt 10 prozent' => ['price * 0.9', ['price' => 100.0], 90.0];
        yield 'unaeres minus' => ['-price + 5', ['price' => 3.0], 2.0];
        yield 'minus minus' => ['5 - -2', [], 7.0];
        yield 'division' => ['10 / 4', [], 2.5];
        yield 'min max' => ['max(price * 0.9, 5) + min(1, 2, 3)', ['price' => 2.0], 6.0];
        yield 'round' => ['round(2.345, 2)', [], 2.35];
        yield 'floor ceil abs' => ['floor(2.9) + ceil(2.1) + abs(-1)', [], 6.0];
        yield 'if wahr' => ['if(field.gravur, 4.9, 0)', ['field.gravur' => 1.0], 4.9];
        yield 'if falsch' => ['if(field.gravur, 4.9, 0)', ['field.gravur' => 0.0], 0.0];
        yield 'vergleich' => ['if(quantity >= 3, price * 0.8, price)', ['quantity' => 3.0, 'price' => 10.0], 8.0];
        yield 'und oder' => ['(1 && 0) + (1 || 0) + !0', [], 2.0];
        yield 'punktvariable' => ['option.zuschlag * 2', ['option.zuschlag' => 1.5], 3.0];
        yield 'leerzeichen' => ["  1 +\n 2  ", [], 3.0];
    }

    /**
     * @param array<string, float> $vars
     */
    #[DataProvider('valid')]
    public function testEvaluates(string $formula, array $vars, float $expected): void
    {
        self::assertEqualsWithDelta($expected, (new FormulaEvaluator())->evaluate($formula, $vars), 1e-9);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'leer' => [''];
        yield 'nur leerzeichen' => ['   '];
        yield 'offene klammer' => ['(1 + 2'];
        yield 'ueberzaehlige klammer' => ['1 + 2)'];
        yield 'zwei zahlen' => ['1 2'];
        yield 'unbekannte funktion' => ['system(1)'];
        yield 'zeichen' => ['1 + $x'];
        yield 'semikolon' => ['1; 2'];
        yield 'anführungszeichen' => ['"a"'];
        yield 'falsche argumentzahl' => ['if(1, 2)'];
        yield 'ende' => ['1 +'];
        yield 'zu lang' => [str_repeat('1+', 600) . '1'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidSyntax(string $formula): void
    {
        $this->expectException(FormulaException::class);
        (new FormulaEvaluator())->validate($formula);
    }

    public function testUnknownVariableThrows(): void
    {
        $this->expectException(FormulaException::class);
        (new FormulaEvaluator())->evaluate('price + foo', ['price' => 1.0]);
    }

    public function testDivisionByZeroThrows(): void
    {
        $this->expectException(FormulaException::class);
        (new FormulaEvaluator())->evaluate('1 / (2 - 2)');
    }

    public function testIfEvaluatesOnlyChosenBranch(): void
    {
        // Der nicht gewählte Zweig würde durch null teilen.
        self::assertSame(1.0, (new FormulaEvaluator())->evaluate('if(1, 1, 1 / 0)'));
    }

    public function testVariablesAreListed(): void
    {
        self::assertSame(['price', 'field.x'], (new FormulaEvaluator())->variables('price * 2 + if(field.x, price, 0)'));
    }

    public function testDeepNestingIsRejected(): void
    {
        $this->expectException(FormulaException::class);
        (new FormulaEvaluator())->validate(str_repeat('(', 100) . '1' . str_repeat(')', 100));
    }
}
