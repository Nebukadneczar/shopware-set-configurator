<?php

declare(strict_types=1);

namespace SetConfigurator\Subscriber;

use SetConfigurator\Service\SetConfigurationService;
use SetConfigurator\Service\SetPresenter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt das aufbereitete Set an die Produktseite (Extension "setConfigurator"), falls dem Produkt ein aktives Set zugewiesen ist.
 * In Twig: page.extensions.setConfigurator.get('set')
 */
class ProductPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SetConfigurationService $service,
        private readonly SetPresenter $presenter
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProductPageLoadedEvent::class => 'onProductPageLoaded'];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $product = $event->getPage()->getProduct();
        $context = $event->getSalesChannelContext();

        $set = $this->service->resolveSet($product->getId(), $product->getParentId(), $context);
        if ($set === null) {
            return;
        }

        $event->getPage()->addExtension('setConfigurator', new ArrayStruct([
            'productId' => $product->getId(),
            'set' => $this->presenter->present($set, $product->getCalculatedPrice()->getUnitPrice(), $context),
        ]));
    }
}
