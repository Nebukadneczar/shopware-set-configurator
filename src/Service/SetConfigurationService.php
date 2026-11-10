<?php

declare(strict_types=1);

namespace SetConfigurator\Service;

use SetConfigurator\Core\Content\Field\FieldDefinition;
use SetConfigurator\Core\Content\Set\SetEntity;
use SetConfigurator\Service\Formula\FormulaException;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Fügt Set-Auflösung, Normalisierung, Validierung, Lagerprüfung und Preisberechnung für einen Shop-Kontext zusammen.
 */
class SetConfigurationService
{
    /** Obergrenze für die Menge des Haupt-Produkts, schützt vor Überläufen. */
    public const MAX_HOST_QUANTITY = 999;

    public function __construct(
        private readonly SetResolver $resolver,
        private readonly SelectionValidator $validator,
        private readonly PriceCalculator $calculator,
        private readonly SalesChannelRepository $productRepository
    ) {
    }

    /** Set für ein Haupt-Produkt (mit Vererbung von der Eltern-Variante). */
    public function resolveSet(string $productId, ?string $parentId, SalesChannelContext $context): ?SetEntity
    {
        return $this->resolver->resolveForProduct($productId, $parentId, $context->getContext());
    }

    /**
     * @param array<string, mixed> $rawSelection Kundeneingabe
     *
     * @return array{
     *   valid: bool,
     *   violations: list<array<string, mixed>>,
     *   selection: array<string, mixed>,
     *   price: array{total: float, options: float, lines: list<array<string, mixed>>}|null,
     *   summary: list<array<string, mixed>>,
     *   stock: array<string, int>,
     *   shortages: list<array{productId: string, name: string, available: int, required: int}>
     * }
     */
    public function evaluate(
        SetEntity $set,
        array $rawSelection,
        float $basePrice,
        int $hostQuantity,
        SalesChannelContext $context
    ): array {
        $loadSet = fn (string $id): ?SetEntity => $this->resolver->load($id, $context->getContext());

        $selection = SelectionHelper::normalize($set, $rawSelection, $loadSet);

        $products = $this->loadProducts($set, $selection, $loadSet, $context);

        $isVariantOf = static function (string $variantId, string $parentId) use ($products): bool {
            $variant = $products[$variantId] ?? null;

            return $variant !== null && $variant->getParentId() === $parentId;
        };
        $violations = $this->validator->validate($set, $selection, $loadSet, $isVariantOf);

        // Produkte der Auswahl müssen im Shop verfügbar sein.
        $stock = SelectionHelper::requiredStock($set, $selection, $loadSet);
        foreach (array_keys($stock) as $productId) {
            if (!isset($products[$productId])) {
                $violations[] = ['code' => 'product_unavailable', 'path' => '', 'productId' => $productId];
            }
        }

        $price = null;
        if ($violations === []) {
            try {
                $price = $this->calculator->calculate(
                    $set,
                    $selection,
                    $basePrice,
                    static function (string $id) use ($products): ?array {
                        $p = $products[$id] ?? null;
                        if ($p === null) {
                            return null;
                        }
                        $custom = [];
                        foreach ($p->getCustomFields() ?? [] as $k => $v) {
                            if (is_numeric($v)) {
                                $custom[(string) $k] = $v + 0;
                            }
                        }

                        return ['price' => $p->getCalculatedPrice()->getUnitPrice(), 'custom' => $custom];
                    },
                    $loadSet
                );
            } catch (FormulaException $e) {
                $violations[] = ['code' => 'formula_error', 'path' => '', 'message' => $e->getMessage()];
            }
        }

        // Lagerbestand: nur bei Abverkaufsartikeln (Closeout) hart begrenzt, wie im Shopware-Standard.
        $shortages = [];
        foreach ($stock as $productId => $perUnit) {
            $p = $products[$productId] ?? null;
            if ($p === null || !$p->getIsCloseout()) {
                continue;
            }
            $required = $perUnit * max(1, $hostQuantity);
            $available = max(0, (int) $p->getAvailableStock());
            if ($required > $available) {
                $shortages[] = [
                    'productId' => $productId,
                    'name' => (string) ($p->getTranslation('name') ?? $p->getProductNumber()),
                    'available' => $available,
                    'required' => $required,
                ];
            }
        }

        return [
            'valid' => $violations === [] && $shortages === [],
            'violations' => $violations,
            'selection' => $selection,
            'price' => $price,
            'summary' => $violations === [] ? $this->summarize($set, $selection, $products, $loadSet) : [],
            'stock' => $stock,
            'shortages' => $shortages,
        ];
    }

    /**
     * @param array<string, mixed> $selection
     * @param callable(string): ?SetEntity $loadSet
     *
     * @return array<string, SalesChannelProductEntity>
     */
    private function loadProducts(SetEntity $set, array $selection, callable $loadSet, SalesChannelContext $context): array
    {
        $ids = array_keys(SelectionHelper::requiredStock($set, $selection, $loadSet));
        if ($ids === []) {
            return [];
        }

        $criteria = new Criteria($ids);
        $criteria->addFilter(new EqualsFilter('active', true));

        $found = [];
        foreach ($this->productRepository->search($criteria, $context)->getEntities() as $product) {
            $found[$product->getId()] = $product;
        }

        return $found;
    }

    /**
     * Lesbare Zusammenfassung für Warenkorb, Bestellung und Mails (landet im Payload).
     *
     * @param array<string, mixed> $selection
     * @param array<string, SalesChannelProductEntity> $products
     * @param callable(string): ?SetEntity $loadSet
     *
     * @return list<array<string, mixed>>
     */
    private function summarize(SetEntity $set, array $selection, array $products, callable $loadSet, int $depth = 0): array
    {
        $rows = [];
        $input = $selection['slots'] ?? [];

        foreach ($set->getSlots() ?? [] as $slot) {
            $options = [];
            foreach ($slot->getOptions() ?? [] as $option) {
                $options[$option->getId()] = $option;
            }
            foreach ($input[$slot->getId()] ?? [] as $entry) {
                $option = $options[$entry['optionId']] ?? null;
                if ($option === null) {
                    continue;
                }

                $productId = $option->isProduct() ? ($entry['productId'] ?? $option->getProductId()) : null;
                $product = $productId !== null ? ($products[$productId] ?? null) : null;
                $label = $option->getLabel() ?: ($product !== null ? (string) $product->getTranslation('name') : '');

                $fields = [];
                foreach ($option->getFields() ?? [] as $field) {
                    $value = $entry['fields'][$field->getId()] ?? null;
                    if ($value === null || $value === '' || $value === false) {
                        continue;
                    }
                    $fields[] = ['label' => $field->getLabel(), 'value' => $this->displayValue($field, $value)];
                }

                $row = [
                    'slot' => $slot->getName(),
                    'label' => $label,
                    'productId' => $productId,
                    'productNumber' => $product?->getProductNumber(),
                    'quantity' => (int) $entry['quantity'],
                    'fields' => $fields,
                ];

                if ($option->getNestedSetId() !== null && isset($entry['nested']) && $depth < SelectionValidator::MAX_NESTING) {
                    $nestedSet = $loadSet($option->getNestedSetId());
                    if ($nestedSet !== null) {
                        $row['nested'] = $this->summarize($nestedSet, $entry['nested'], $products, $loadSet, $depth + 1);
                    }
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function displayValue(\SetConfigurator\Core\Content\Field\FieldEntity $field, mixed $value): string
    {
        if ($field->getType() === FieldDefinition::TYPE_CHECKBOX) {
            return 'ja';
        }
        if ($field->getType() === FieldDefinition::TYPE_SELECT) {
            foreach ($field->getChoices() as $choice) {
                if ((string) ($choice['value'] ?? '') === (string) $value) {
                    return (string) ($choice['label'] ?? $choice['value']);
                }
            }
        }

        return (string) $value;
    }
}
