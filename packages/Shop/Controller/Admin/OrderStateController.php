<?php
declare(strict_types=1);
namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\ActionTypeRegistry;
use Mublo\Packages\Shop\Enum\OrderAction;

/**
 * Admin OrderStateController
 *
 * 주문상태 FSM 설정 + 상태별 액션 설정 관리 컨트롤러
 *
 * 라우팅:
 * - GET  /admin/shop/order-states              → index (설정 폼)
 * - POST /admin/shop/order-states/store        → store (FSM 저장)
 * - POST /admin/shop/order-states/store-actions → storeActions (액션 저장)
 */
class OrderStateController
{
    private ShopConfigService $shopConfigService;
    private OrderStateResolver $stateResolver;
    private ActionTypeRegistry $actionRegistry;

    public function __construct(
        ShopConfigService $shopConfigService,
        OrderStateResolver $stateResolver,
        ActionTypeRegistry $actionRegistry
    ) {
        $this->shopConfigService = $shopConfigService;
        $this->stateResolver = $stateResolver;
        $this->actionRegistry = $actionRegistry;
    }

    /**
     * 주문상태 설정 페이지
     *
     * GET /admin/shop/order-states
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        $orderStates = $this->stateResolver->getAllStates($domainId);
        $stateActions = $this->shopConfigService->getAllStateActions($domainId);

        // 등록된 액션 타입 목록, 스키마, 설명, 중복 허용 여부 (UI 동적 폼 생성용)
        $actionTypes = $this->actionRegistry->getRegisteredTypes();
        $actionSchemas = $this->actionRegistry->getAllSchemas();
        $actionDescriptions = $this->actionRegistry->getAllDescriptions();
        $actionAllowDuplicates = $this->actionRegistry->getAllowDuplicates();

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/OrderState/Index')
            ->withData([
                'pageTitle' => '주문상태 설정',
                'orderStates' => $orderStates,
                'stateActions' => $stateActions,
                'actionTypes' => $actionTypes,
                'actionSchemas' => $actionSchemas,
                'actionDescriptions' => $actionDescriptions,
                'actionAllowDuplicates' => $actionAllowDuplicates,
            ]);
    }

    /**
     * 주문상태 FSM 설정 저장
     *
     * POST /admin/shop/order-states/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $orderStatesJson = $formData['order_states'] ?? '';

        if (empty($orderStatesJson) || !is_string($orderStatesJson)) {
            return JsonResponse::error('주문상태 데이터가 필요합니다.');
        }

        // 이전 설정 가져오기 (시스템 상태 불변 검증용)
        $configResult = $this->shopConfigService->getConfig($domainId);
        $previousConfig = $configResult->get('config', []);
        $previousJson = $previousConfig['order_states'] ?? null;

        // 검증 및 정규화
        $validation = $this->shopConfigService->validateOrderStates($orderStatesJson, $previousJson);

        if (!$validation['valid']) {
            return JsonResponse::error(
                implode("\n", $validation['errors']),
                ['errors' => $validation['errors'], 'warnings' => $validation['warnings']]
            );
        }

        // shop_config에 저장
        $result = $this->shopConfigService->saveConfig($domainId, [
            'order_states' => $validation['json'],
        ]);

        // 캐시 초기화
        $this->stateResolver->clearCache($domainId);

        if ($result->isSuccess()) {
            $response = [];
            if (!empty($validation['warnings'])) {
                $response['warnings'] = $validation['warnings'];
            }
            return JsonResponse::success($response, '주문상태 설정이 저장되었습니다.');
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 상태별 액션 설정 저장
     *
     * POST /admin/shop/order-states/store-actions
     */
    public function storeActions(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $actionsJson = $formData['state_actions'] ?? '';

        if (empty($actionsJson) || !is_string($actionsJson)) {
            return JsonResponse::error('액션 설정 데이터가 필요합니다.');
        }

        $stateActions = json_decode($actionsJson, true);
        if (!is_array($stateActions)) {
            return JsonResponse::error('유효하지 않은 JSON 형식입니다.');
        }

        // 각 액션 검증
        $errors = [];
        $validStateIds = array_fill_keys(array_map(
            static fn(array $state): string => (string) ($state['id'] ?? ''),
            $this->stateResolver->getAllStates($domainId)
        ), true);
        foreach ($stateActions as $stateId => $actions) {
            if (!isset($validStateIds[(string) $stateId])) {
                $errors[] = "존재하지 않는 상태 '{$stateId}'에 액션이 설정되어 있습니다.";
                continue;
            }
            if (!is_array($actions)) {
                continue;
            }

            // 필드 검증
            foreach ($actions as $i => $action) {
                if (!($action['enabled'] ?? true)) {
                    continue; // 비활성 액션은 검증 스킵
                }
                $validation = $this->actionRegistry->validateAction($action);
                if (!$validation['valid']) {
                    foreach ($validation['errors'] as $err) {
                        $errors[] = "[{$stateId}] " . $err;
                    }
                }
            }

            // 중복 등록 검증
            $dupErrors = $this->actionRegistry->validateDuplicates($actions);
            foreach ($dupErrors as $err) {
                $errors[] = "[{$stateId}] " . $err;
            }
        }

        if (!empty($errors)) {
            return JsonResponse::error(
                '액션 설정에 오류가 있습니다.',
                ['errors' => $errors]
            );
        }

        $result = $this->shopConfigService->saveStateActions($domainId, $stateActions);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, '상태별 액션 설정이 저장되었습니다.');
        }

        return JsonResponse::error($result->getMessage());
    }
}
