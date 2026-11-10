<?php

declare(strict_types=1);

namespace SetConfigurator\Api;

use SetConfigurator\Cart\SetCartProcessor;
use SetConfigurator\Service\SelectionHelper;
use SetConfigurator\Service\SetConfigurationService;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Store-API für den Set-Konfigurator (Set Configurator).
 *
 * POST /store-api/set-configurator/{productId}/configure   Vorschau: Prüfung, Preis, Zusammenfassung (ändert nichts)
 * POST /store-api/set-configurator/{productId}/add         Konfiguration in den Warenkorb legen
 *
 * Dieselben Aktionen gibt es für die Storefront (AJAX) unter /set-configurator/{productId}/configure und /add,
 * dazu POST /set-configurator/upload für Upload-Felder (Datei → Medien-ID, privat gespeichert).
 *
 * Body: { "selection": { "slots": { ... } }, "quantity": 1 }  (Format siehe SelectionValidator)
 */
#[Route(defaults: ['_routeScope' => ['store-api']])]
class SetRoute extends AbstractController
{
    public function __construct(
        private readonly SetConfigurationService $service,
        private readonly SalesChannelRepository $productRepository,
        private readonly CartService $cartService,
        private readonly MediaService $mediaService
    ) {
    }

    #[Route(path: '/store-api/set-configurator/{productId}/configure', name: 'store-api.set-configurator.configure', methods: ['POST'])]
    public function configure(string $productId, Request $request, SalesChannelContext $context): JsonResponse
    {
        [$product, $set, $selection, $quantity] = $this->prepare($productId, $request, $context);
        if ($product === null || $set === null) {
            return $this->error('set_not_found', 404);
        }

        $result = $this->service->evaluate($set, $selection, $product->getCalculatedPrice()->getUnitPrice(), $quantity, $context);

        return new JsonResponse($this->present($result), $result['valid'] ? 200 : 422);
    }

    #[Route(path: '/store-api/set-configurator/{productId}/add', name: 'store-api.set-configurator.add', methods: ['POST'])]
    public function add(string $productId, Request $request, SalesChannelContext $context): JsonResponse
    {
        [$product, $set, $selection, $quantity] = $this->prepare($productId, $request, $context);
        if ($product === null || $set === null) {
            return $this->error('set_not_found', 404);
        }

        $result = $this->service->evaluate($set, $selection, $product->getCalculatedPrice()->getUnitPrice(), $quantity, $context);
        if (!$result['valid']) {
            return new JsonResponse($this->present($result), 422);
        }

        $lineItemId = SelectionHelper::fingerprint($productId, $result['selection']);
        $lineItem = new LineItem($lineItemId, LineItem::PRODUCT_LINE_ITEM_TYPE, $productId, $quantity);
        $lineItem->setStackable(true);
        $lineItem->setRemovable(true);
        $lineItem->setPayloadValue(SetCartProcessor::PAYLOAD_SELECTION, $result['selection']);

        $cart = $this->cartService->add($this->cartService->getCart($context->getToken(), $context), $lineItem, $context);

        if (!$cart->getLineItems()->has($lineItemId)) {
            // Der Cart-Processor hat die Position entfernt (Lager oder Konfiguration geändert).
            $keys = [];
            foreach ($cart->getErrors() as $e) {
                $keys[] = $e->getMessageKey();
            }

            return new JsonResponse(['code' => 'rejected_by_cart', 'errors' => $keys], 409);
        }

        return new JsonResponse(['lineItemId' => $lineItemId] + $this->present($result));
    }

    #[Route(path: '/set-configurator/{productId}/configure', name: 'frontend.set-configurator.configure', defaults: ['_routeScope' => ['storefront'], 'XmlHttpRequest' => true], methods: ['POST'])]
    public function configureStorefront(string $productId, Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->configure($productId, $request, $context);
    }

    #[Route(path: '/set-configurator/{productId}/add', name: 'frontend.set-configurator.add', defaults: ['_routeScope' => ['storefront'], 'XmlHttpRequest' => true], methods: ['POST'])]
    public function addStorefront(string $productId, Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->add($productId, $request, $context);
    }

    private const UPLOAD_TYPES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
    ];

    private const UPLOAD_MAX_BYTES = 5 * 1024 * 1024;

    #[Route(path: '/set-configurator/upload', name: 'frontend.set-configurator.upload', defaults: ['_routeScope' => ['storefront'], 'XmlHttpRequest' => true], methods: ['POST'])]
    public function upload(Request $request, SalesChannelContext $context): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->error('upload_failed', 400);
        }
        if ($file->getSize() > self::UPLOAD_MAX_BYTES) {
            return $this->error('upload_too_large', 413);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: '';
        if (!isset(self::UPLOAD_TYPES[$extension]) || !\in_array($mime, self::UPLOAD_TYPES[$extension], true)) {
            return $this->error('upload_type_not_allowed', 415);
        }

        $mediaId = $this->mediaService->saveFile(
            (string) file_get_contents($file->getPathname()),
            $extension,
            $mime,
            'set-upload-' . bin2hex(random_bytes(8)),
            Context::createDefaultContext(),
            null,
            null,
            true
        );

        return new JsonResponse(['mediaId' => $mediaId]);
    }

    /**
     * @return array{0: ?SalesChannelProductEntity, 1: ?\SetConfigurator\Core\Content\Set\SetEntity, 2: array<string, mixed>, 3: int}
     */
    private function prepare(string $productId, Request $request, SalesChannelContext $context): array
    {
        $criteria = new Criteria([$productId]);
        $criteria->addFilter(new EqualsFilter('active', true));
        /** @var SalesChannelProductEntity|null $product */
        $product = $this->productRepository->search($criteria, $context)->first();
        if ($product === null) {
            return [null, null, [], 1];
        }

        $set = $this->service->resolveSet($productId, $product->getParentId(), $context);

        $body = $request->request->all();
        $selection = \is_array($body['selection'] ?? null) ? $body['selection'] : [];
        $quantity = isset($body['quantity']) && is_numeric($body['quantity']) ? (int) $body['quantity'] : 1;
        $quantity = max(1, min(SetConfigurationService::MAX_HOST_QUANTITY, $quantity));

        return [$product, $set, $selection, $quantity];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function present(array $result): array
    {
        return [
            'valid' => $result['valid'],
            'violations' => $result['violations'],
            'shortages' => $result['shortages'],
            'price' => $result['price'],
            'summary' => $result['summary'],
        ];
    }

    private function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code], $status);
    }
}
