<?php

declare(strict_types=1);

namespace SetConfigurator\Service;

use SetConfigurator\Core\Content\Option\OptionEntity;
use SetConfigurator\Core\Content\Set\SetEntity;
use SetConfigurator\Service\Formula\FormulaEvaluator;
use SetConfigurator\Service\Formula\FormulaException;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Bereitet ein Set für die Storefront-Vorlagen auf (einfache Arrays, keine Entitäten).
 */
class SetPresenter
{
    public function __construct(
        private readonly SetResolver $resolver,
        private readonly SalesChannelRepository $productRepository,
        private readonly FormulaEvaluator $formula
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(SetEntity $set, float $hostPrice, SalesChannelContext $context): array
    {
        $loadSet = fn (string $id): ?SetEntity => $this->resolver->load($id, $context->getContext());

        $sets = [$set->getId() => $set];
        $this->collectNested($set, $loadSet, $sets, 0);

        $products = $this->loadProducts($sets, $context);

        return $this->build($set, $hostPrice, $products, $loadSet, [$set->getId()], 0);
    }

    /**
     * @param array<string, SetEntity> $sets
     * @param callable(string): ?SetEntity $loadSet
     */
    private function collectNested(SetEntity $set, callable $loadSet, array &$sets, int $depth): void
    {
        if ($depth >= SelectionValidator::MAX_NESTING) {
            return;
        }
        foreach ($set->getSlots() ?? [] as $slot) {
            foreach ($slot->getOptions() ?? [] as $option) {
                $nestedId = $option->getNestedSetId();
                if ($nestedId !== null && !isset($sets[$nestedId])) {
                    $nested = $loadSet($nestedId);
                    if ($nested !== null) {
                        $sets[$nestedId] = $nested;
                        $this->collectNested($nested, $loadSet, $sets, $depth + 1);
                    }
                }
            }
        }
    }

    /**
     * @param array<string, SetEntity> $sets
     *
     * @return array{products: array<string, SalesChannelProductEntity>, variants: array<string, list<SalesChannelProductEntity>>}
     */
    private function loadProducts(array $sets, SalesChannelContext $context): array
    {
        $ids = [];
        foreach ($sets as $set) {
            foreach ($set->getSlots() ?? [] as $slot) {
                foreach ($slot->getOptions() ?? [] as $option) {
                    if ($option->isProduct() && $option->getProductId() !== null) {
                        $ids[$option->getProductId()] = true;
                    }
                }
            }
        }
        if ($ids === []) {
            return ['products' => [], 'variants' => []];
        }

        $criteria = new Criteria(array_keys($ids));
        $criteria->addFilter(new EqualsFilter('active', true));
        $products = [];
        foreach ($this->productRepository->search($criteria, $context)->getEntities() as $p) {
            $products[$p->getId()] = $p;
        }

        $parents = array_keys(array_filter($products, static fn (SalesChannelProductEntity $p): bool => ($p->getChildCount() ?? 0) > 0));
        $variants = [];
        if ($parents !== []) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('parentId', $parents));
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addSorting(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting('productNumber'));
            $criteria->setLimit(500);
            foreach ($this->productRepository->search($criteria, $context)->getEntities() as $v) {
                $variants[(string) $v->getParentId()][] = $v;
            }
        }

        return ['products' => $products, 'variants' => $variants];
    }

    /**
     * @param array{products: array<string, SalesChannelProductEntity>, variants: array<string, list<SalesChannelProductEntity>>} $loaded
     * @param callable(string): ?SetEntity $loadSet
     * @param list<string> $path
     *
     * @return array<string, mixed>
     */
    private function build(SetEntity $set, float $hostPrice, array $loaded, callable $loadSet, array $path, int $depth): array
    {
        $slots = [];
        foreach ($set->getSlots() ?? [] as $slot) {
            $options = [];
            foreach ($slot->getOptions() ?? [] as $option) {
                $row = $this->option($option, $hostPrice, $loaded);
                if ($row === null) {
                    continue; // Produkt nicht (mehr) verfügbar: Option nicht anzeigen
                }
                $nestedId = $option->getNestedSetId();
                if ($nestedId !== null && $depth < SelectionValidator::MAX_NESTING && !\in_array($nestedId, $path, true)) {
                    $nested = $loadSet($nestedId);
                    if ($nested !== null) {
                        $row['nested'] = $this->build($nested, 0.0, $loaded, $loadSet, [...$path, $nestedId], $depth + 1);
                    }
                }
                $options[] = $row;
            }
            $slots[] = [
                'id' => $slot->getId(),
                'name' => $slot->getName(),
                'description' => $slot->getDescription(),
                'template' => $slot->getTemplate(),
                'min' => $slot->getMinSelect(),
                'max' => $slot->getMaxSelect(),
                'multiple' => $slot->getMaxSelect() === null || $slot->getMaxSelect() > 1,
                'options' => $options,
            ];
        }

        return ['id' => $set->getId(), 'name' => $set->getName(), 'description' => $set->getDescription(), 'slots' => $slots];
    }

    /**
     * @param array{products: array<string, SalesChannelProductEntity>, variants: array<string, list<SalesChannelProductEntity>>} $loaded
     *
     * @return array<string, mixed>|null
     */
    private function option(OptionEntity $option, float $hostPrice, array $loaded): ?array
    {
        $product = null;
        $variants = [];
        $price = 0.0;
        $vars = ['base' => $hostPrice, 'quantity' => 1.0, 'nested' => 0.0];

        if ($option->isProduct()) {
            $product = $loaded['products'][$option->getProductId()] ?? null;
            if ($product === null) {
                return null;
            }
            $price = $product->getCalculatedPrice()->getUnitPrice();
            foreach ($loaded['variants'][$product->getId()] ?? [] as $v) {
                $variants[] = [
                    'id' => $v->getId(),
                    'name' => $this->variantName($v),
                    'price' => $v->getCalculatedPrice()->getUnitPrice(),
                    'available' => $v->getAvailable() !== false,
                ];
            }
        }
        $vars['price'] = $price;
        foreach ($option->getCustomValues() as $k => $v) {
            if (is_numeric($v)) {
                $vars['option.' . $k] = (float) $v;
            }
        }
        foreach ($option->getFields() ?? [] as $field) {
            $vars['field.' . $field->getName()] = 0.0;
        }

        // Anzeigepreis: Formel ohne Feldaufschläge, sonst Produktpreis. Bei Fehlern kein Preis anzeigen.
        $display = $price;
        $formula = $option->getPriceFormula();
        if ($formula !== null && trim($formula) !== '') {
            try {
                $display = $this->formula->evaluate($formula, $vars);
            } catch (FormulaException) {
                $display = null;
            }
        }

        $fields = [];
        foreach ($option->getFields() ?? [] as $field) {
            $fields[] = [
                'id' => $field->getId(),
                'type' => $field->getType(),
                'name' => $field->getName(),
                'label' => $field->getLabel(),
                'required' => $field->isRequired(),
                'choices' => array_map(static fn (array $c): array => [
                    'value' => (string) ($c['value'] ?? ''),
                    'label' => (string) ($c['label'] ?? $c['value'] ?? ''),
                ], $field->getChoices()),
            ];
        }

        return [
            'id' => $option->getId(),
            'type' => $option->getType(),
            'label' => $option->getLabel() ?: ($product !== null ? (string) $product->getTranslation('name') : ''),
            'preselected' => $option->isPreselected(),
            'quantityMin' => $option->getQuantityMin(),
            'quantityMax' => $option->getQuantityMax(),
            'fixedQuantity' => $option->isFixedQuantity(),
            'price' => $display,
            'productId' => $option->getProductId(),
            'productNumber' => $product?->getProductNumber(),
            'hasVariants' => $variants !== [],
            'variants' => $variants,
            'fields' => $fields,
            'nested' => null,
        ];
    }

    private function variantName(SalesChannelProductEntity $variant): string
    {
        $parts = [];
        foreach ($variant->getVariation() ?? [] as $v) {
            $parts[] = (string) ($v['option'] ?? '');
        }
        $parts = array_filter($parts);

        return $parts !== [] ? implode(' / ', $parts) : (string) $variant->getTranslation('name');
    }
}
