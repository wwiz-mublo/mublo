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
use Mublo\Packages\Shop\Service\RefundService;

final class ClaimController
{
    public function __construct(
        private ClaimService $exchanges,
        private ShipmentService $shipments,
        private AuthContextInterface $auth,
        private ShopConfigService $config,
        private ActionTypeRegistry $actionTypes,
        private ?RefundService $refunds = null,
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
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Claim/List')
            ->withData([
                'pageTitle' => '반품·교환 관리',
                'claims' => $result['items'],
                'pagination' => $result['pagination'],
                'filters' => $filters,
                'statusOptions' => ClaimStatus::options(),
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
            return JsonResponse::error('반품·교환 Action 설정을 확인해주세요.', ['errors' => $errors]);
        }
        $result = $this->config->saveClaimStateActions($domainId, $normalized);
        return $result->isSuccess()
            ? JsonResponse::success([], '반품·교환 Action 설정을 저장했습니다.')
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
                'refundedForClaim' => $this->refunds?->getRefundedAmountForClaim($claimId) ?? 0,
                'statusOptions' => ClaimStatus::options(),
            ]);
    }

    /**
     * 반품 환불 실행 + 반품 완료 (환불대기 → 반품완료).
     *
     * 환불을 클레임 화면에서 실행해야 그 환불이 어느 반품 건인지 기록에 남는다.
     * 주문 화면의 범용 환불은 클레임과 이어지지 않으므로, 반품 환불은 이 경로를 쓴다.
     * 금액은 접수 때 계산해 둔 환불 예정액으로 고정한다 — 자유 입력은 예정액과
     * 어긋난 금액이 나가는 길을 연다.
     */
    private function refundAndComplete(
        int $domainId,
        int $claimId,
        ?int $staffId,
        $request,
        string $reason,
    ): \Mublo\Core\Result\Result {
        if ($this->refunds === null) {
            return \Mublo\Core\Result\Result::failure('환불 기능을 사용할 수 없습니다.');
        }
        $context = $this->exchanges->getRefundContext($domainId, $claimId);
        if ($context === null) {
            return \Mublo\Core\Result\Result::failure('반품 건을 찾을 수 없습니다.');
        }
        if ($context['refund_amount'] <= 0) {
            return \Mublo\Core\Result\Result::failure('환불할 금액이 없습니다. 환불 예정액을 확인해주세요.');
        }

        $method = strtoupper(trim((string) ($request->json('refund_method', '') ?? '')));
        if (!in_array($method, ['PG_CANCEL', 'BANK', 'POINT'], true)) {
            return \Mublo\Core\Result\Result::failure('환불 방법을 선택해주세요.');
        }

        $refund = $this->refunds->processRefund(
            $context['order_no'],
            $context['refund_amount'],
            $method,
            $reason !== '' ? $reason : "반품 환불 (클레임 #{$claimId})",
            $domainId,
            $staffId ?? 0,
            [
                'bank' => trim((string) ($request->json('refund_bank', '') ?? '')),
                'account' => trim((string) ($request->json('refund_account', '') ?? '')),
                'holder' => trim((string) ($request->json('refund_holder', '') ?? '')),
            ],
            $claimId,
        );
        if ($refund->isFailure()) {
            return $refund;
        }

        // 환불이 실제로 나간 뒤에만 반품을 종료한다
        return $this->exchanges->completeRefund($domainId, $claimId, $staffId, $reason);
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

        // 송장을 남기는 처리는 택배사가 있어야 한다. 없이 저장되면 추적 링크도 못 만들고,
        // 어느 택배사로 보냈는지 아무도 모르는 송장이 남는다.
        if (in_array($action, ['collect', 'reship', 'return_rejected'], true)
            && (int) ($shipment['company_id'] ?? 0) <= 0
        ) {
            return JsonResponse::error('택배사를 선택해주세요.');
        }

        if (in_array($action, ['shipment_status', 'shipment_update'], true)) {
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
            'refund' => $this->refundAndComplete($domainId, $claimId, $staffId, $request, $reason),
            'return_rejected' => $this->exchanges->returnRejected($domainId, $claimId, $shipment, $staffId),
            'close' => $this->exchanges->closeRejected($domainId, $claimId, $staffId),
            'shipment_update' => $this->shipments->updateShipment(
                (int) ($request->json('shipment_id', 0) ?? 0),
                [
                    'company_id' => (int) ($request->json('company_id', 0) ?? 0) ?: null,
                    'invoice_no' => trim((string) ($request->json('invoice_no', '') ?? '')),
                    'admin_memo' => trim((string) ($request->json('admin_memo', '') ?? '')) ?: null,
                ],
            ),
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
