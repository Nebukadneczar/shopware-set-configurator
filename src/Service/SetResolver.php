<?php

declare(strict_types=1);

namespace SetConfigurator\Service;

use SetConfigurator\Core\Content\Assignment\AssignmentCollection;
use SetConfigurator\Core\Content\Set\SetEntity;
use SetConfigurator\Core\Content\Target\TargetCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Findet das Set eines Produkts und lädt es vollständig (Slots, Optionen, Produkte, Zusatzfelder).
 *
 * Rangfolge:
 *  1. Zuweisung direkt am Produkt, dann am Elternprodukt (Vererbung auf Varianten)
 *  2. Zuweisung über Kategorie (genaueste Kategorie zuerst)
 *  3. Zuweisung über Hersteller
 * Nur aktive Sets zählen.
 */
class SetResolver
{
    /**
     * @param EntityRepository<\SetConfigurator\Core\Content\Set\SetCollection> $setRepository
     * @param EntityRepository<AssignmentCollection> $assignmentRepository
     * @param EntityRepository<TargetCollection> $targetRepository
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly EntityRepository $setRepository,
        private readonly EntityRepository $assignmentRepository,
        private readonly EntityRepository $targetRepository,
        private readonly EntityRepository $productRepository
    ) {
    }

    public function resolveForProduct(string $productId, ?string $parentId, Context $context): ?SetEntity
    {
        $setId = $this->direct($productId, $parentId, $context) ?? $this->byTarget($productId, $parentId, $context);

        return $setId === null ? null : $this->load($setId, $context);
    }

    private function direct(string $productId, ?string $parentId, Context $context): ?string
    {
        $ids = array_values(array_filter([$productId, $parentId]));

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('productId', $ids));
        $criteria->addFilter(new EqualsFilter('set.active', true));

        /** @var AssignmentCollection $assignments */
        $assignments = $this->assignmentRepository->search($criteria, $context)->getEntities();

        $setId = null;
        foreach ($assignments as $assignment) {
            if ($assignment->getProductId() === $productId) {
                return $assignment->getSetId(); // eigene Zuweisung hat Vorrang
            }
            if ($parentId !== null && $assignment->getProductId() === $parentId) {
                $setId = $assignment->getSetId();
            }
        }

        return $setId;
    }

    private function byTarget(string $productId, ?string $parentId, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('set.active', true));
        /** @var TargetCollection $targets */
        $targets = $this->targetRepository->search($criteria, $context)->getEntities();
        if ($targets->count() === 0) {
            return null; // häufigster Fall: keine Zuweisungen nach Kategorie/Hersteller, keine weiteren Abfragen
        }

        // Variante und Elternprodukt zusammen betrachten (Kategorien/Hersteller werden meist am Elternprodukt gepflegt).
        $products = $this->productRepository->search(new Criteria(array_values(array_filter([$productId, $parentId]))), $context)->getEntities();
        $categoryIds = [];
        $categoryTree = [];
        $manufacturerIds = [];
        foreach ($products as $product) {
            $categoryIds = array_merge($categoryIds, $product->getCategoryIds() ?? []);
            $categoryTree = array_merge($categoryTree, $product->getCategoryTree() ?? []);
            if ($product->getManufacturerId() !== null) {
                $manufacturerIds[] = $product->getManufacturerId();
            }
        }

        $rows = [];
        foreach ($targets as $target) {
            $rows[] = [
                'setId' => $target->getSetId(),
                'type' => $target->getType(),
                'categoryId' => $target->getCategoryId(),
                'manufacturerId' => $target->getManufacturerId(),
                'includeChildren' => $target->isIncludeChildren(),
                'createdAt' => $target->getCreatedAt()?->getTimestamp() ?? 0,
            ];
        }

        return TargetMatcher::pick(
            $rows,
            array_values(array_unique($categoryIds)),
            array_values(array_unique($categoryTree)),
            array_values(array_unique($manufacturerIds))
        );
    }

    public function load(string $setId, Context $context): ?SetEntity
    {
        $criteria = new Criteria([$setId]);
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('slots.options.product');
        $criteria->addAssociation('slots.options.fields');
        $criteria->getAssociation('slots')->addSorting(new FieldSorting('position'));
        $criteria->getAssociation('slots.options')->addSorting(new FieldSorting('position'));
        $criteria->getAssociation('slots.options.fields')->addSorting(new FieldSorting('position'));

        return $this->setRepository->search($criteria, $context)->first();
    }
}
