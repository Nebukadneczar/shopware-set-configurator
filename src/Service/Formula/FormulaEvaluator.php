<?php

declare(strict_types=1);

namespace SetConfigurator\Service\Formula;

/**
 * Sicherer Rechner für Preisformeln. Bewusst kein eval() und keine Fremdbibliothek:
 * nur Zahlen, Variablen, + - * / Klammern, Vergleiche, && || ! und eine feste Funktionsliste.
 *
 * Beispiele:  price * 0.9        price + 12.5        max(price * 0.9, 5)
 *             if(field.gravur, 4.9, 0) + option.aufschlag
 *
 * Variablennamen bestehen aus Buchstaben, Ziffern, "_" und "." (z. B. field.farbe, option.zuschlag).
 * Wahrheitswerte sind 1 und 0.
 */
final class FormulaEvaluator
{
    public const MAX_LENGTH = 1000;
    private const MAX_DEPTH = 40;
    private const FUNCTIONS = ['min', 'max', 'round', 'floor', 'ceil', 'abs', 'if'];

    /**
     * @param array<string, int|float|bool> $variables
     */
    public function evaluate(string $formula, array $variables = []): float
    {
        $ast = $this->parse($formula);

        return $this->run($ast, $variables);
    }

    /**
     * Prüft nur die Syntax (für die Verwaltung beim Speichern). Wirft FormulaException.
     */
    public function validate(string $formula): void
    {
        $this->parse($formula);
    }

    /**
     * Liefert die in der Formel verwendeten Variablennamen.
     *
     * @return list<string>
     */
    public function variables(string $formula): array
    {
        $found = [];
        $this->collect($this->parse($formula), $found);

        return array_values(array_unique($found));
    }

    // ---------------------------------------------------------------- Tokenizer

    /**
     * @return list<array{0: string, 1: string}> [Typ, Text]; Typ: num|id|op|end
     */
    private function tokenize(string $formula): array
    {
        if (trim($formula) === '') {
            throw new FormulaException('Die Formel ist leer.');
        }
        if (\strlen($formula) > self::MAX_LENGTH) {
            throw new FormulaException('Die Formel ist zu lang.');
        }

        $tokens = [];
        $pos = 0;
        $len = \strlen($formula);
        $re = '/\G\s*(?:(\d+(?:\.\d+)?)|([A-Za-z_][A-Za-z0-9_.]*)|(>=|<=|==|!=|&&|\|\||[-+*\/(),<>!]))/';

        while (true) {
            if (preg_match('/\G\s*$/', $formula, $m, 0, $pos) === 1) {
                break;
            }
            if (preg_match($re, $formula, $m, PREG_UNMATCHED_AS_NULL, $pos) !== 1) {
                throw new FormulaException(sprintf('Unerwartetes Zeichen an Position %d.', $pos + 1));
            }
            if ($m[1] !== null) {
                $tokens[] = ['num', $m[1]];
            } elseif ($m[2] !== null) {
                $tokens[] = ['id', $m[2]];
            } else {
                $tokens[] = ['op', $m[3]];
            }
            $pos += \strlen($m[0]);
            if ($pos >= $len) {
                break;
            }
        }
        $tokens[] = ['end', ''];

        return $tokens;
    }

    // ------------------------------------------------------------------- Parser

    /** @var list<array{0: string, 1: string}> */
    private array $tokens = [];

    private int $index = 0;

    private int $depth = 0;

    /**
     * @return array<int|string, mixed>
     */
    private function parse(string $formula): array
    {
        $this->tokens = $this->tokenize($formula);
        $this->index = 0;
        $this->depth = 0;

        $ast = $this->parseOr();
        if ($this->peek()[0] !== 'end') {
            throw new FormulaException(sprintf('Unerwartetes "%s" in der Formel.', $this->peek()[1]));
        }

        return $ast;
    }

    /** @return array{0: string, 1: string} */
    private function peek(): array
    {
        return $this->tokens[$this->index];
    }

    private function isOp(string ...$ops): bool
    {
        $t = $this->peek();

        return $t[0] === 'op' && \in_array($t[1], $ops, true);
    }

    private function expectOp(string $op): void
    {
        if (!$this->isOp($op)) {
            throw new FormulaException(sprintf('"%s" erwartet.', $op));
        }
        ++$this->index;
    }

    /** @return array<int|string, mixed> */
    private function parseOr(): array
    {
        $left = $this->parseAnd();
        while ($this->isOp('||')) {
            ++$this->index;
            $left = ['bin', '||', $left, $this->parseAnd()];
        }

        return $left;
    }

    /** @return array<int|string, mixed> */
    private function parseAnd(): array
    {
        $left = $this->parseCompare();
        while ($this->isOp('&&')) {
            ++$this->index;
            $left = ['bin', '&&', $left, $this->parseCompare()];
        }

        return $left;
    }

    /** @return array<int|string, mixed> */
    private function parseCompare(): array
    {
        $left = $this->parseAdd();
        if ($this->isOp('>', '<', '>=', '<=', '==', '!=')) {
            $op = $this->peek()[1];
            ++$this->index;
            $left = ['bin', $op, $left, $this->parseAdd()];
        }

        return $left;
    }

    /** @return array<int|string, mixed> */
    private function parseAdd(): array
    {
        $left = $this->parseMul();
        while ($this->isOp('+', '-')) {
            $op = $this->peek()[1];
            ++$this->index;
            $left = ['bin', $op, $left, $this->parseMul()];
        }

        return $left;
    }

    /** @return array<int|string, mixed> */
    private function parseMul(): array
    {
        $left = $this->parseUnary();
        while ($this->isOp('*', '/')) {
            $op = $this->peek()[1];
            ++$this->index;
            $left = ['bin', $op, $left, $this->parseUnary()];
        }

        return $left;
    }

    /** @return array<int|string, mixed> */
    private function parseUnary(): array
    {
        if ($this->isOp('-', '+', '!')) {
            $op = $this->peek()[1];
            ++$this->index;
            if (++$this->depth > self::MAX_DEPTH) {
                throw new FormulaException('Die Formel ist zu stark verschachtelt.');
            }
            $node = ['un', $op, $this->parseUnary()];
            --$this->depth;

            return $node;
        }

        return $this->parsePrimary();
    }

    /** @return array<int|string, mixed> */
    private function parsePrimary(): array
    {
        $t = $this->peek();

        if ($t[0] === 'num') {
            ++$this->index;

            return ['num', (float) $t[1]];
        }

        if ($t[0] === 'id') {
            ++$this->index;
            if ($this->isOp('(')) {
                $name = strtolower($t[1]);
                if (!\in_array($name, self::FUNCTIONS, true)) {
                    throw new FormulaException(sprintf('Unbekannte Funktion "%s".', $t[1]));
                }
                ++$this->index;
                $args = [];
                if (!$this->isOp(')')) {
                    do {
                        $args[] = $this->parseOr();
                        $more = $this->isOp(',');
                        if ($more) {
                            ++$this->index;
                        }
                    } while ($more);
                }
                $this->expectOp(')');
                $this->checkArity($name, \count($args));

                return ['fn', $name, $args];
            }

            return ['var', $t[1]];
        }

        if ($this->isOp('(')) {
            ++$this->index;
            if (++$this->depth > self::MAX_DEPTH) {
                throw new FormulaException('Die Formel ist zu stark verschachtelt.');
            }
            $inner = $this->parseOr();
            --$this->depth;
            $this->expectOp(')');

            return $inner;
        }

        throw new FormulaException($t[0] === 'end' ? 'Die Formel endet unerwartet.' : sprintf('Unerwartetes "%s" in der Formel.', $t[1]));
    }

    private function checkArity(string $name, int $count): void
    {
        $ok = match ($name) {
            'min', 'max' => $count >= 1,
            'round' => $count === 1 || $count === 2,
            'floor', 'ceil', 'abs' => $count === 1,
            'if' => $count === 3,
            default => false,
        };
        if (!$ok) {
            throw new FormulaException(sprintf('Falsche Anzahl Argumente für %s().', $name));
        }
    }

    // ---------------------------------------------------------------- Auswertung

    /**
     * @param array<int|string, mixed> $node
     * @param array<string, int|float|bool> $vars
     */
    private function run(array $node, array $vars): float
    {
        switch ($node[0]) {
            case 'num':
                return $node[1];
            case 'var':
                if (!\array_key_exists($node[1], $vars)) {
                    throw new FormulaException(sprintf('Unbekannte Variable "%s".', $node[1]));
                }

                return (float) $vars[$node[1]];
            case 'un':
                $v = $this->run($node[2], $vars);

                return match ($node[1]) {
                    '-' => -$v,
                    '+' => $v,
                    default => $v == 0.0 ? 1.0 : 0.0,
                };
            case 'bin':
                return $this->binary($node[1], $node[2], $node[3], $vars);
            case 'fn':
                return $this->call($node[1], $node[2], $vars);
        }

        throw new FormulaException('Ungültiger Ausdruck.');
    }

    /**
     * @param array<int|string, mixed> $l
     * @param array<int|string, mixed> $r
     * @param array<string, int|float|bool> $vars
     */
    private function binary(string $op, array $l, array $r, array $vars): float
    {
        if ($op === '&&') {
            return ($this->run($l, $vars) != 0.0 && $this->run($r, $vars) != 0.0) ? 1.0 : 0.0;
        }
        if ($op === '||') {
            return ($this->run($l, $vars) != 0.0 || $this->run($r, $vars) != 0.0) ? 1.0 : 0.0;
        }

        $a = $this->run($l, $vars);
        $b = $this->run($r, $vars);

        switch ($op) {
            case '+':
                return $a + $b;
            case '-':
                return $a - $b;
            case '*':
                return $a * $b;
            case '/':
                if ($b == 0.0) {
                    throw new FormulaException('Division durch null.');
                }

                return $a / $b;
            case '>':
                return $a > $b ? 1.0 : 0.0;
            case '<':
                return $a < $b ? 1.0 : 0.0;
            case '>=':
                return $a >= $b ? 1.0 : 0.0;
            case '<=':
                return $a <= $b ? 1.0 : 0.0;
            case '==':
                return abs($a - $b) < 1e-9 ? 1.0 : 0.0;
            case '!=':
                return abs($a - $b) >= 1e-9 ? 1.0 : 0.0;
        }

        throw new FormulaException('Ungültiger Operator.');
    }

    /**
     * @param list<array<int|string, mixed>> $args
     * @param array<string, int|float|bool> $vars
     */
    private function call(string $name, array $args, array $vars): float
    {
        if ($name === 'if') {
            // Nur der gewählte Zweig wird ausgewertet.
            return $this->run($args[0], $vars) != 0.0 ? $this->run($args[1], $vars) : $this->run($args[2], $vars);
        }

        $values = array_map(fn (array $a): float => $this->run($a, $vars), $args);

        return match ($name) {
            'min' => min($values),
            'max' => max($values),
            'abs' => abs($values[0]),
            'floor' => floor($values[0]),
            'ceil' => ceil($values[0]),
            'round' => round($values[0], isset($values[1]) ? (int) $values[1] : 0),
            default => throw new FormulaException('Unbekannte Funktion.'),
        };
    }

    /**
     * @param array<int|string, mixed> $node
     * @param list<string> $found
     */
    private function collect(array $node, array &$found): void
    {
        switch ($node[0]) {
            case 'var':
                $found[] = $node[1];
                break;
            case 'un':
                $this->collect($node[2], $found);
                break;
            case 'bin':
                $this->collect($node[2], $found);
                $this->collect($node[3], $found);
                break;
            case 'fn':
                foreach ($node[2] as $arg) {
                    $this->collect($arg, $found);
                }
                break;
        }
    }
}
