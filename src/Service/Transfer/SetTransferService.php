<?php

declare(strict_types=1);

namespace SetConfigurator\Service\Transfer;

use SetConfigurator\Core\Content\Assignment\AssignmentCollection;
use SetConfigurator\Core\Content\Set\SetCollection;
use SetConfigurator\Service\Formula\FormulaEvaluator;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Export und Import von Sets als JSON (siehe SetTransfer für das Format).
 *
 * Import: Ein vorhandenes Set mit gleichem Namen wird ersetzt (Slots, Optionen, Felder und Zuweisungen),
 * sonst wird ein neues angelegt. Ist ein Produkt schon einem anderen Set zugewiesen, wird es umgehängt.
 */
class SetTransferService
{
    /**
     * @param EntityRepository<SetCollection> $setRepository
     * @param EntityRepository<\SetConfigurator\Core\Content\Slot\SlotCollection> $slotRepository
     * @param EntityRepository<AssignmentCollection> $assignmentRepository
     * @param EntityRepository<\Shopware\Core\Content\Product\ProductCollection> $productRepository
     */
    public function __construct(
        private readonly EntityRepository $setRepository,
        private readonly EntityRepository $slotRepository,
        private readonly EntityRepository $assignmentRepository,
        private readonly EntityRepository $productRepository,
        private readonly FormulaEvaluator $formula
    ) {
    }

    /**
     * @param list<string>|null $setIds null = alle Sets
     *
     * @return array<string, mixed>
     */
    public function export(?array $setIds, Context $context): array
    {
        $sets = $this->loadSetsWithNested($setIds, $context);

        $numbers = [];
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('setId', array_keys($sets) ?: ['']));
        $criteria->addAssociation('product');
        /** @var AssignmentCollection $assignments */
        $assignments = $this->assignmentRepository->search($criteria, $context)->getEntities();
        foreach ($assignments as $assignment) {
            if ($assignment->getProduct() !== null) {
                $numbers[$assignment->getSetId()][] = $assignment->getProduct()->getProductNumber();
            }
        }

        $rows = [];
        foreach ($sets as $id => $set) {
            $rows[] = SetTransfer::exportSet($set, $numbers[$id] ?? []);
        }

        return SetTransfer::document($rows);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array{ok: bool, dryRun: bool, errors: list<string>, created: int, replaced: int, reassigned: int}
     */
    public function import(array $document, bool $dryRun, Context $context): array
    {
        $result = ['ok' => false, 'dryRun' => $dryRun, 'errors' => [], 'created' => 0, 'replaced' => 0, 'reassigned' => 0];

        // Produkte und vorhandene Sets nachschlagen
        $numbers = [];
        $names = [];
        foreach (\is_array($document['sets'] ?? null) ? $document['sets'] : [] as $set) {
            if (!\is_array($set)) {
                continue;
            }
            if (\is_string($set['name'] ?? null)) {
                $names[] = $set['name'];
            }
            foreach (\is_array($set['assignments'] ?? null) ? $set['assignments'] : [] as $n) {
                if (\is_string($n)) {
                    $numbers[$n] = true;
                }
            }
            foreach (\is_array($set['slots'] ?? null) ? $set['slots'] : [] as $slot) {
                foreach (\is_array($slot['options'] ?? null) ? $slot['options'] : [] as $option) {
                    if (\is_array($option) && \is_string($option['productNumber'] ?? null)) {
                        $numbers[$option['productNumber']] = true;
                    }
                }
            }
        }

        $productIds = [];
        if ($numbers !== []) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('productNumber', array_map('strval', array_keys($numbers))));
            foreach ($this->productRepository->search($criteria, $context)->getEntities() as $product) {
                $productIds[$product->getProductNumber()] = $product->getId();
            }
        }

        $existing = [];
        if ($names !== []) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('name', $names));
            foreach ($this->setRepository->search($criteria, $context)->getEntities() as $set) {
                $existing[$set->getName()] = $set->getId();
            }
        }

        $plan = SetTransfer::plan($document, $productIds, $existing, static fn (): string => Uuid::randomHex(), $this->formula);
        $result['errors'] = $plan['errors'];
        if ($plan['errors'] !== []) {
            return $result;
        }

        foreach ($plan['sets'] as $set) {
            ++$result[$set['existing'] ? 'replaced' : 'created'];
        }
        if ($dryRun) {
            $result['ok'] = true;

            return $result;
        }

        // 1. Alte Inhalte ersetzter Sets entfernen (Slots löschen Optionen und Felder per Cascade).
        $replacedIds = array_values(array_map(static fn (array $s): string => $s['id'], array_filter($plan['sets'], static fn (array $s): bool => $s['existing'])));
        if ($replacedIds !== []) {
            $slotIds = $this->slotRepository->searchIds((new Criteria())->addFilter(new EqualsAnyFilter('setId', $replacedIds)), $context)->getIds();
            if ($slotIds !== []) {
                $this->slotRepository->delete(array_map(static fn ($id): array => ['id' => $id], $slotIds), $context);
            }
        }

        // 2. Zuweisungen: die der ersetzten Sets und alle, die gleich neu gesetzt werden (Umhängen).
        $allProductIds = array_values(array_unique(array_merge(...array_map(static fn (array $s): array => $s['productIds'], $plan['sets']))));
        $filters = array_values(array_filter([
            $replacedIds !== [] ? new EqualsAnyFilter('setId', $replacedIds) : null,
            $allProductIds !== [] ? new EqualsAnyFilter('productId', $allProductIds) : null,
        ]));
        // Wichtig: Ohne Filter dürfte hier nichts gelöscht werden (ein leerer Filter würde alle Zuweisungen treffen).
        if ($filters !== []) {
            $criteria = new Criteria();
            $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter(
                \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter::CONNECTION_OR,
                $filters
            ));
            $old = $this->assignmentRepository->search($criteria, $context)->getEntities();
            foreach ($old as $assignment) {
                if (!\in_array($assignment->getSetId(), $replacedIds, true)) {
                    ++$result['reassigned'];
                }
            }
            if ($old->count() > 0) {
                $this->assignmentRepository->delete(array_map(static fn ($id): array => ['id' => $id], array_values($old->getIds())), $context);
            }
        }

        // 3. Sets (ohne Inhalt), danach Slots mit Optionen und Feldern.
        //    Zwei Schritte, weil verschachtelte Sets sich gegenseitig per Fremdschlüssel verweisen können.
        $this->setRepository->upsert(array_map(static fn (array $s): array => $s['base'], $plan['sets']), $context);

        $slots = [];
        foreach ($plan['sets'] as $set) {
            array_push($slots, ...$set['slots']);
        }
        if ($slots !== []) {
            $this->slotRepository->upsert($slots, $context);
        }

        $assignments = [];
        foreach ($plan['sets'] as $set) {
            foreach ($set['productIds'] as $productId) {
                $assignments[] = [
                    'id' => Uuid::randomHex(),
                    'setId' => $set['id'],
                    'productId' => $productId,
                    'productVersionId' => Defaults::LIVE_VERSION,
                ];
            }
        }
        if ($assignments !== []) {
            $this->assignmentRepository->create($assignments, $context);
        }

        $result['ok'] = true;

        return $result;
    }

    /**
     * Lädt die gewünschten Sets und alle darin verschachtelten Sets (Abschluss), damit die Datei in sich vollständig ist.
     *
     * @param list<string>|null $setIds
     *
     * @return array<string, \SetConfigurator\Core\Content\Set\SetEntity>
     */
    private function loadSetsWithNested(?array $setIds, Context $context): array
    {
        if ($setIds === []) {
            return []; // Criteria([]) würde sonst ALLE Sets laden
        }

        $loaded = [];
        $queue = $setIds;
        $first = true;

        while ($first || ($queue !== null && $queue !== [])) {
            $first = false;
            $criteria = $queue === null ? new Criteria() : new Criteria($queue);
            $criteria->addAssociation('slots.options.product');
            $criteria->addAssociation('slots.options.fields');
            $criteria->getAssociation('slots')->addSorting(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting('position'));
            $criteria->getAssociation('slots.options')->addSorting(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting('position'));
            $criteria->getAssociation('slots.options.fields')->addSorting(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting('position'));

            $next = [];
            foreach ($this->setRepository->search($criteria, $context)->getEntities() as $set) {
                $loaded[$set->getId()] = $set;
            }
            foreach ($loaded as $set) {
                foreach ($set->getSlots() ?? [] as $slot) {
                    foreach ($slot->getOptions() ?? [] as $option) {
                        $nested = $option->getNestedSetId();
                        if ($nested !== null && !isset($loaded[$nested])) {
                            $next[$nested] = $nested;
                        }
                    }
                }
            }
            $queue = $queue === null ? null : array_values($next);
            if ($queue === null) {
                break; // alle Sets geladen, verschachtelte sind enthalten
            }
        }

        return $loaded;
    }
}
