<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\Shop\Enum\ClaimStatus;
use Mublo\Packages\Shop\Service\ClaimService;
use Mublo\Packages\Shop\Service\ShipmentService;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Service\ActionTypeRegistry;

final class ClaimController
{
    public function __construct(
        private ClaimService $exchanges,
        private ShipmentService $shipments,
        private AuthContextInterface $auth,
        private ShopConfigService $config,
        private ActionTypeRegistry $actionTypes,
    ) {}

    public function index(array $params, Context $context): ViewResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $filters = [
            'return_type' => trim((string) ($request->get('return_type') ?? '')),
            'status' => trim((string) ($request->get('status') ?? '')),
            'keyword' => trim((string) ($request->get('keyword') ?? '')),
        ];
        $result = $this->exchanges->list(
            $domainId,
            $filters,
            max(1, (int) ($request->get('page') ?? 1)),
            max(1, (int) ($request->get('per_page') ?? 20)),
        );
        $schemas = $this->actionTypes->getAllSchemas();
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Claim/List')
            ->withData([
                'pageTitle' => '교환·반품 관리',
                'claims' => $result['items'],
                'pagination' => $result['pagination'],
                'filters' => $filters,
                'statusOptions' => ClaimStatus::options(),
                'claimActions' => $this->config->getAllClaimStateActions($domainId),
                'notificationChannels' => $schemas['notification']['fields']['channel']['options'] ?? [],
            ]);
    }

    public function storeActions(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $raw = $context->getRequest()->json('actions', []);
        if (!is_array($raw)) {
            return JsonResponse::error('액션 설정 형식이 올바르지 않습니다.');
        }
        $validStatuses = array_fill_keys(array_keys(ClaimStatus::options()), true);
        $existingActions = $this->config->getAllClaimStateActions($domainId);
        $normalized = [];
        $errors = [];
        foreach ($raw as $status => $actions) {
            if (!isset($validStatuses[(string) $status]) || !is_array($actions)) {
                continue;
            }
            foreach ($actions as $action) {
                if (!is_array($action) || !in_array(($action['type'] ?? ''), ['notification', 'webhook'], true)) {
                    continue;
                }
                if (($action['type'] ?? '') === 'webhook' && empty($action['secret'])) {
                    foreach ((array) ($existingActions[$status] ?? []) as $existing) {
                        if (($existing['type'] ?? '') === 'webhook' && !empty($existing['secret'])) {
                            $action['secret'] = $existing['secret'];
                            break;
                        }
                    }
                }
                $validation = $this->actionTypes->validateAction($action);
                if (!$validation['valid']) {
                    foreach ($validation['errors'] as $error) {
                        $errors[] = '[' . $status . '] ' . $error;
                    }
                    continue;
                }
                $normalized[(string) $status][] = $action;
            }
        }
        if ($errors !== []) {
            return JsonResponse::error('교환·반품 Action 설정을 확인해주세요.', ['errors' => $errors]);
        }
        $result = $this->config->saveClaimStateActions($domainId, $normalized);
        return $result->isSuccess()
            ? JsonResponse::success([], '교환·반품 Action 설정을 저장했습니다.')
            : JsonResponse::error($result->getMessage());
    }

    public function view(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $claimId = (int) ($params['claimId'] ?? 0);
        $claim = $this->exchanges->get($domainId, $claimId);
        if ($claim === null) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withStatusCode(404)
                ->withData(['message' => '클레임 건을 찾을 수 없습니다.']);
        }
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Claim/View')
            ->withData([
                'pageTitle' => '클레임 상세',
                'claim' => $claim,
                'companies' => $this->shipments->getDeliveryCompanies(),
                'statusOptions' => ClaimStatus::options(),
            ]);
    }

    public function process(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $claimId = (int) ($params['claimId'] ?? 0);
        $request = $context->getRequest();
        $action = trim((string) ($request->json('action', '') ?? ''));
        $claim = $this->exchanges->get($domainId, $claimId);
        if ($claim === null) {
            return JsonResponse::error('클레임 건을 찾을 수 없습니다.', null, 404);
        }
        $staffId = $this->auth->id();
        $reason = trim((string) ($request->json('reason', '') ?? ''));
        $shipment = [
            'company_id' => (int) ($request->json('company_id', 0) ?? 0) ?: null,
            'invoice_no' => trim((string) ($request->json('invoice_no', '') ?? '')),
            'admin_memo' => trim((string) ($request->json('admin_memo', '') ?? '')),
        ];

        if ($action === 'shipment_status') {
            $shipmentId = (int) ($request->json('shipment_id', 0) ?? 0);
            $ownedShipmentIds = array_map('intval', array_column((array) ($claim['shipments'] ?? []), 'shipment_id'));
            if (!in_array($shipmentId, $ownedShipmentIds, true)) {
                return JsonResponse::error('교환 건에 속한 배송 정보를 찾을 수 없습니다.');
            }
        }

        $result = match ($action) {
            'accept' => $this->exchanges->accept($domainId, $claimId, $staffId, $reason),
            'refuse' => $this->exchanges->refuse($domainId, $claimId, $staffId, $reason),
            'cancel' => $this->exchanges->cancel($domainId, $claimId, 'STAFF', $staffId, $reason),
            'collect' => $this->exchanges->startCollection($domainId, $claimId, $shipment, $staffId),
            'collected' => $this->exchanges->markCollected($domainId, $claimId, $staffId),
            'inspect_start' => $this->exchanges->startInspection($domainId, $claimId, $staffId),
            'inspect_approve' => $this->exchanges->inspect($domainId, $claimId, true, strtoupper((string) $request->json('inspection_result', '')), $staffId, $reason),
            'inspect_reject' => $this->exchanges->inspect($domainId, $claimId, false, strtoupper((string) $request->json('inspection_result', '')), $staffId, $reason),
            'fee_paid' => $this->exchanges->markFeePaid($domainId, $claimId, strtoupper((string) $request->json('fee_method', 'MANUAL')), $staffId),
            'reship' => $this->exchanges->reship($domainId, $claimId, $shipment, $staffId),
            'complete' => $this->exchanges->complete($domainId, $claimId, $staffId),
            'refund_complete' => $this->exchanges->completeRefund($domainId, $claimId, $staffId, $reason),
            'return_rejected' => $this->exchanges->returnRejected($domainId, $claimId, $shipment, $staffId),
            'close' => $this->exchanges->closeRejected($domainId, $claimId, $staffId),
            'shipment_status' => $this->shipments->updateStatus(
                (int) ($request->json('shipment_id', 0) ?? 0),
                strtoupper((string) $request->json('shipment_status', '')),
                (string) ($claim['order_no'] ?? ''),
                $claimId,
            ),
            default => \Mublo\Core\Result\Result::failure('지원하지 않는 클레임 처리입니다.'),
        };

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }
}
