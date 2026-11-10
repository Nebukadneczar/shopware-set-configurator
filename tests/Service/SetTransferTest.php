<?php

declare(strict_types=1);

namespace SetConfigurator\Tests\Service;

use SetConfigurator\Service\Formula\FormulaEvaluator;
use SetConfigurator\Service\Transfer\SetTransfer;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductEntity;

final class SetTransferTest extends TestCase
{
    private int $counter = 0;

    protected function setUp(): void
    {
        $this->counter = 0;
    }

    private function newId(): string
    {
        return sprintf('%032x', ++$this->counter);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, string> $productIds
     * @param array<string, string> $existing
     *
     * @return array<string, mixed>
     */
    private function plan(array $document, array $productIds = ['A-1' => 'p1'], array $existing = []): array
    {
        return SetTransfer::plan($document, $productIds, $existing, fn (): string => $this->newId(), new FormulaEvaluator());
    }

    /** @return array<string, mixed> */
    private function sampleDocument(): array
    {
        $product = new ProductEntity();
        $product->setId('p1');
        $product->setProductNumber('A-1');

        $inner = Builder::set('inner', [Builder::slot('i', [Builder::option('io', ['priceFormula' => '4'])])]);
        $outer = Builder::set('outer', [
            Builder::slot('a', [
                Builder::option(
                    'o1',
                    ['type' => 'product', 'productId' => 'p1', 'product' => $product, 'nestedSetId' => 'inner', 'customValues' => ['x' => 2], 'priceFormula' => 'price * 0.9'],
                    [Builder::field('f1', 'select', 'farbe', ['choices' => [['value' => 'rot', 'label' => 'Rot', 'number' => 1]], 'priceFormula' => 'value'])]
                ),
            ]),
        ], '(base + options) * 0.9');

        return SetTransfer::document([
            SetTransfer::exportSet($outer, ['A-1']),
            SetTransfer::exportSet($inner, []),
        ]);
    }

    public function testRejectsForeignFiles(): void
    {
        self::assertNotSame([], $this->plan(['hello' => 'world'])['errors']);
        self::assertNotSame([], $this->plan(['format' => SetTransfer::FORMAT, 'version' => 1, 'sets' => []])['errors']);
    }

    public function testRoundTripBuildsLinkedPayload(): void
    {
        $result = $this->plan($this->sampleDocument());

        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['sets']);

        [$outer, $inner] = $result['sets'];
        self::assertSame('Set outer', $outer['name']);
        self::assertSame(['p1'], $outer['productIds']);
        self::assertFalse($outer['existing']);

        $option = $outer['slots'][0]['options'][0];
        self::assertSame('p1', $option['productId']);
        self::assertSame($inner['id'], $option['nestedSetId']); // Schlüssel wurde zur neuen Set-ID
        self::assertSame(['x' => 2], $option['customValues']);
        self::assertSame('price * 0.9', $option['priceFormula']);
        self::assertSame('farbe', $option['fields'][0]['name']);
        self::assertSame('(base + options) * 0.9', $outer['base']['priceFormula']);
    }

    public function testExistingSetNameIsReplacedKeepingId(): void
    {
        $result = $this->plan($this->sampleDocument(), ['A-1' => 'p1'], ['Set outer' => 'abc']);

        self::assertSame([], $result['errors']);
        self::assertSame('abc', $result['sets'][0]['id']);
        self::assertTrue($result['sets'][0]['existing']);
        self::assertSame('abc', $result['sets'][0]['slots'][0]['setId']);
    }

    public function testReportsUnknownProductAndMissingNestedSet(): void
    {
        $doc = $this->sampleDocument();
        array_pop($doc['sets']); // verschachteltes Set fehlt in der Datei

        $errors = $this->plan($doc, [])['errors'];

        self::assertNotEmpty(array_filter($errors, static fn (string $e): bool => str_contains($e, 'nicht gefunden')));
        self::assertNotEmpty(array_filter($errors, static fn (string $e): bool => str_contains($e, 'nicht in der Datei')));
        self::assertNotEmpty(array_filter($errors, static fn (string $e): bool => str_contains($e, 'Zuweisung')));
    }

    public function testReportsInvalidFormulaTemplateAndFieldName(): void
    {
        $doc = SetTransfer::document([[
            'key' => 's',
            'name' => 'Kaputt',
            'priceFormula' => 'base +',
            'slots' => [[
                'name' => 'Slot',
                'template' => 'zauber',
                'minSelect' => 3,
                'maxSelect' => 1,
                'options' => [[
                    'type' => 'static',
                    'quantityMin' => 5,
                    'quantityMax' => 2,
                    'fields' => [['type' => 'text', 'name' => '1x', 'label' => 'X']],
                ]],
            ]],
        ]]);

        $errors = implode("\n", $this->plan($doc)['errors']);

        self::assertStringContainsString('Preisformel', $errors);
        self::assertStringContainsString('Vorlage', $errors);
        self::assertStringContainsString('Höchstens', $errors);
        self::assertStringContainsString('Mengen', $errors);
        self::assertStringContainsString('Feldname', $errors);
    }

    public function testDuplicateKeysAndNamesAreRejected(): void
    {
        $doc = SetTransfer::document([
            ['key' => 'a', 'name' => 'Gleich', 'slots' => []],
            ['key' => 'a', 'name' => 'Anders', 'slots' => []],
            ['key' => 'b', 'name' => 'Gleich', 'slots' => []],
        ]);

        self::assertCount(2, $this->plan($doc)['errors']);
    }
}
