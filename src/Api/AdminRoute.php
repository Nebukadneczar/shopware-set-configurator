<?php

declare(strict_types=1);

namespace SetConfigurator\Api;

use SetConfigurator\Service\Formula\FormulaEvaluator;
use SetConfigurator\Service\Formula\FormulaException;
use SetConfigurator\Service\Transfer\SetTransferService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API: Formelprüfung sowie Export/Import von Sets (JSON).
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class AdminRoute extends AbstractController
{
    public function __construct(
        private readonly FormulaEvaluator $formula,
        private readonly SetTransferService $transfer
    ) {
    }

    #[Route(path: '/api/_action/set-configurator/validate-formula', name: 'api.action.set-configurator.validate-formula', defaults: ['_acl' => ['set_configurator:read']], methods: ['POST'])]
    public function validateFormula(Request $request): JsonResponse
    {
        $formula = $request->request->get('formula');
        if (!\is_string($formula) || trim($formula) === '') {
            return new JsonResponse(['valid' => true, 'error' => null, 'variables' => []]);
        }

        try {
            return new JsonResponse(['valid' => true, 'error' => null, 'variables' => $this->formula->variables($formula)]);
        } catch (FormulaException $e) {
            return new JsonResponse(['valid' => false, 'error' => $e->getMessage(), 'variables' => []]);
        }
    }

    /** Alle Sets (oder ?ids[]=...) als JSON-Datei. Verschachtelte Sets sind automatisch enthalten. */
    #[Route(path: '/api/_action/set-configurator/export', name: 'api.action.set-configurator.export', defaults: ['_acl' => ['set_configurator:read']], methods: ['GET'])]
    public function export(Request $request, Context $context): Response
    {
        $ids = $request->query->all('ids');
        $ids = array_values(array_filter($ids, static fn ($id): bool => \is_string($id) && preg_match('/^[0-9a-f]{32}$/', $id) === 1));

        $document = $this->transfer->export($request->query->has('ids') ? $ids : null, $context);

        $response = new JsonResponse($document);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Disposition', 'attachment; filename="produktsets-' . date('Y-m-d') . '.json"');

        return $response;
    }

    /** Body: { "data": { ...Exportdatei... }, "dryRun": true|false } */
    #[Route(path: '/api/_action/set-configurator/import', name: 'api.action.set-configurator.import', defaults: ['_acl' => ['set_configurator:create', 'set_configurator:update']], methods: ['POST'])]
    public function import(Request $request, Context $context): JsonResponse
    {
        $data = $request->request->all('data');
        $dryRun = $request->request->getBoolean('dryRun', true);

        $result = $this->transfer->import($data, $dryRun, $context);

        return new JsonResponse($result, $result['errors'] === [] ? 200 : 422);
    }
}
