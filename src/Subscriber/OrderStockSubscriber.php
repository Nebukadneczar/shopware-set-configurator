<?php

declare(strict_types=1);

namespace SetConfigurator\Subscriber;

use Doctrine\DBAL\Connection;
use SetConfigurator\Cart\SetCartProcessor;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Lagerbestand der gewählten Set-Optionen (das Haupt-Produkt bucht Shopware selbst):
 * - Bestellabschluss: abbuchen
 * - Bestellung storniert: zurückbuchen (je Position nur einmal, Marker im Payload)
 *
 * Nicht abgedeckt: Wird eine stornierte Bestellung wieder geöffnet, wird nicht erneut abgebucht.
 */
class OrderStockSubscriber implements EventSubscriberInterface
{
    public const PAYLOAD_RESTORED = 'setConfiguratorStockRestored';

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheInvalidator $cacheInvalidator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
            // Shopware verschickt Enter- und Leave-Ereignisse unter diesem Namen; gefiltert wird im Handler.
            'state_machine.order.state_changed' => 'onOrderStateChanged',
        ];
    }

    public function onOrderStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER
            || $event->getStateMachine()->getTechnicalName() !== 'order.state'
            || $event->getStateName() !== 'cancelled'
        ) {
            return;
        }

        $this->onOrderCancelled($event);
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $lineItems = $event->getOrder()->getLineItems();
        if ($lineItems === null) {
            return;
        }

        $totals = [];
        foreach ($lineItems as $lineItem) {
            $stock = ($lineItem->getPayload() ?? [])[SetCartProcessor::PAYLOAD_STOCK] ?? null;
            $this->addTotals($totals, $stock, $lineItem->getQuantity());
        }

        $this->adjust($totals, -1, $event->getContext()->getVersionId());
    }

    public function onOrderCancelled(StateMachineStateChangeEvent $event): void
    {
        $orderId = $event->getTransition()->getEntityId();
        $versionId = $event->getContext()->getVersionId();
        if (!Uuid::isValid($orderId)) {
            return;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT `id`, `quantity`, `payload` FROM `order_line_item`
             WHERE `order_id` = :order AND `version_id` = :version AND `type` = \'product\' AND `payload` LIKE :needle',
            ['order' => Uuid::fromHexToBytes($orderId), 'version' => Uuid::fromHexToBytes($versionId), 'needle' => '%' . SetCartProcessor::PAYLOAD_STOCK . '%']
        );

        $totals = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!\is_array($payload) || !empty($payload[self::PAYLOAD_RESTORED])) {
                continue;
            }
            $this->addTotals($totals, $payload[SetCartProcessor::PAYLOAD_STOCK] ?? null, (int) $row['quantity']);

            $payload[self::PAYLOAD_RESTORED] = true;
            $this->connection->executeStatement(
                'UPDATE `order_line_item` SET `payload` = :payload WHERE `id` = :id AND `version_id` = :version',
                ['payload' => json_encode($payload, \JSON_THROW_ON_ERROR), 'id' => $row['id'], 'version' => Uuid::fromHexToBytes($versionId)]
            );
        }

        $this->adjust($totals, 1, $versionId);
    }

    /**
     * @param array<string, int> $totals
     */
    private function addTotals(array &$totals, mixed $stock, int $lineQuantity): void
    {
        if (!\is_array($stock)) {
            return;
        }
        foreach ($stock as $productId => $perUnit) {
            if (!\is_string($productId) || !\is_int($perUnit) || $perUnit < 1) {
                continue;
            }
            $totals[$productId] = ($totals[$productId] ?? 0) + $perUnit * $lineQuantity;
        }
    }

    /**
     * @param array<string, int> $totals productId => Menge
     * @param int $direction -1 = abbuchen, 1 = zurückbuchen
     */
    private function adjust(array $totals, int $direction, string $versionId): void
    {
        $tags = [];
        foreach ($totals as $productId => $quantity) {
            if (!Uuid::isValid($productId) || $quantity < 1) {
                continue;
            }
            $delta = $direction * $quantity;
            // Reihenfolge der Zuweisungen ist wichtig: MySQL wertet von links nach rechts aus.
            // "available" wird bei Abverkaufsartikeln (Closeout) mit 0 Bestand auf 0 gesetzt, beim Zurückbuchen wieder auf 1.
            $this->connection->executeStatement(
                'UPDATE `product` SET
                    `available` = IF(`is_closeout` = 1, IF((`available_stock` + :d) <= 0, 0, 1), `available`),
                    `stock` = `stock` + :d,
                    `available_stock` = `available_stock` + :d
                 WHERE `id` = :id AND `version_id` = :version',
                ['d' => $delta, 'id' => Uuid::fromHexToBytes($productId), 'version' => Uuid::fromHexToBytes($versionId)]
            );
            $tags[] = 'product-' . $productId;
        }

        if ($tags !== []) {
            $this->cacheInvalidator->invalidate($tags);
        }
    }
}
