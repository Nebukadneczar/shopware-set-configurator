<?php

declare(strict_types=1);

namespace SetConfigurator\Cart;

use SetConfigurator\Service\SetConfigurationService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Läuft NACH dem ProductCartProcessor (Priorität 5000): Der Haupt-Produktpreis steht dann schon in der Position.
 * Hier wird er durch den Set-Preis ersetzt und der Lagerbedarf der gewählten Optionen geprüft.
 * Ungültige oder nicht lieferbare Konfigurationen werden entfernt und als Fehler gemeldet.
 */
class SetCartProcessor implements CartProcessorInterface
{
    public const PAYLOAD_SELECTION = 'setConfiguratorSelection';
    public const PAYLOAD_SUMMARY = 'setConfiguratorSummary';
    public const PAYLOAD_SET_ID = 'setConfiguratorId';
    public const PAYLOAD_STOCK = 'setConfiguratorStock';

    public function __construct(
        private readonly SetConfigurationService $service,
        private readonly QuantityPriceCalculator $priceCalculator
    ) {
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        foreach ($toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $selection = $lineItem->getPayloadValue(self::PAYLOAD_SELECTION);
            if (!\is_array($selection) || $lineItem->getReferencedId() === null || $lineItem->getPrice() === null) {
                continue;
            }

            $parentId = $lineItem->getPayloadValue('parentId');
            $set = $this->service->resolveSet($lineItem->getReferencedId(), \is_string($parentId) ? $parentId : null, $context);
            if ($set === null) {
                $this->drop($toCalculate, $lineItem, 'set-missing');
                continue;
            }

            $base = $lineItem->getPrice()->getUnitPrice();
            $result = $this->service->evaluate($set, $selection, $base, $lineItem->getQuantity(), $context);

            if ($result['violations'] !== [] || $result['price'] === null) {
                $this->drop($toCalculate, $lineItem, 'invalid');
                continue;
            }
            if ($result['shortages'] !== []) {
                $this->drop($toCalculate, $lineItem, 'out-of-stock', ['shortages' => $result['shortages']]);
                continue;
            }

            $definition = new QuantityPriceDefinition(
                $result['price']['total'],
                $lineItem->getPrice()->getTaxRules(),
                $lineItem->getQuantity()
            );
            $lineItem->setPriceDefinition($definition);
            $lineItem->setPrice($this->priceCalculator->calculate($definition, $context));

            $lineItem->setPayloadValue(self::PAYLOAD_SET_ID, $set->getId());
            $lineItem->setPayloadValue(self::PAYLOAD_SUMMARY, $result['summary']);
            $lineItem->setPayloadValue(self::PAYLOAD_STOCK, $result['stock']);
            $lineItem->setPayloadValue(self::PAYLOAD_SELECTION, $result['selection']);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function drop(Cart $cart, LineItem $lineItem, string $reason, array $parameters = []): void
    {
        $cart->getLineItems()->remove($lineItem->getId());
        $cart->addErrors(new SetConfigurationError($lineItem->getId(), $reason, $parameters));
    }
}
