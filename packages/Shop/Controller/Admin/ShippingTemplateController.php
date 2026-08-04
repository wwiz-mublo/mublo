<?php
declare(strict_types=1);
namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Shop\Service\ShippingService;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Enum\ShippingMethod;
use Mublo\Helper\Form\FormHelper;

/**
 * Admin ShippingTemplateController
 *
 * 배송 템플릿 관리 컨트롤러
 *
 * 라우팅:
 * - GET  /admin/shop/shipping              → index (목록)
 * - GET  /admin/shop/shipping/create       → create (생성 폼)
 * - GET  /admin/shop/shipping/{id}/edit    → edit (수정 폼)
 * - POST /admin/shop/shipping/store        → store (생성/수정)
 * - POST /admin/shop/shipping/{id}/delete  → delete (삭제)
 */
class ShippingTemplateController
{
    private ShippingService $shippingService;
    private ShopConfigService $shopConfigService;

    public function __construct(ShippingService $shippingService, ShopConfigService $shopConfigService)
    {
        $this->shippingService   = $shippingService;
        $this->shopConfigService = $shopConfigService;
    }

    /**
     * 쇼핑몰 기본 배송 템플릿 id (shop_config.default_shipping_template_id)
     */
    private function getDefaultTemplateId(int $domainId): int
    {
        $config = $this->shopConfigService->getConfig($domainId)->get('config', []);
        return (int) ($config['default_shipping_template_id'] ?? 0);
    }

    /**
     * 배송 템플릿 목록
     *
     * GET /admin/shop/shipping
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        $result = $this->shippingService->getList($domainId);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Shipping/List')
            ->withData([
                'pageTitle' => '배송 템플릿',
                'templates' => $result->get('items', []),
                'shippingMethodOptions' => ShippingMethod::options(),
                'defaultTemplateId' => $this->getDefaultTemplateId($domainId),
            ]);
    }

    /**
     * 배송 템플릿 생성 폼
     *
     * GET /admin/shop/shipping/create
     */
    public function create(array $params, Context $context): ViewResponse
    {
        $companiesResult = $this->shippingService->getDeliveryCompanies();

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Shipping/Form')
            ->withData([
                'pageTitle' => '배송 템플릿 등록',
                'isEdit' => false,
                'template' => null,
                'shippingMethodOptions' => ShippingMethod::options(),
                'deliveryCompanies' => $companiesResult->get('companies', []),
            ]);
    }

    /**
     * 배송 템플릿 수정 폼
     *
     * GET /admin/shop/shipping/{id}/edit
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $request = $context->getRequest();

        $shippingId = (int) ($params['id'] ?? $params[0] ?? $request->query('id', 0));

        if ($shippingId <= 0) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => '배송 템플릿을 찾을 수 없습니다.']);
        }

        $domainId = $context->getDomainId() ?? 1;
        $result = $this->shippingService->getTemplate($domainId, $shippingId);

        if ($result->isFailure()) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => $result->getMessage()]);
        }

        $companiesResult = $this->shippingService->getDeliveryCompanies();

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Shipping/Form')
            ->withData([
                'pageTitle' => '배송 템플릿 수정',
                'isEdit' => true,
                'template' => $result->get('template', []),
                'shippingMethodOptions' => ShippingMethod::options(),
                'deliveryCompanies' => $companiesResult->get('companies', []),
            ]);
    }

    /**
     * 배송 템플릿 저장 (생성/수정)
     *
     * POST /admin/shop/shipping/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // price_ranges JSON 파싱
        if (!empty($data['price_ranges']) && is_string($data['price_ranges'])) {
            $decoded = json_decode($data['price_ranges'], true);
            $data['price_ranges'] = is_array($decoded) ? $decoded : [];
        }

        // extra_cost_ranges JSON 파싱 (도서산간 추가배송비)
        if (!empty($data['extra_cost_ranges']) && is_string($data['extra_cost_ranges'])) {
            $decoded = json_decode($data['extra_cost_ranges'], true);
            $data['extra_cost_ranges'] = is_array($decoded) ? $decoded : [];
        }

        $shippingId = (int) ($data['shipping_id'] ?? 0);
        unset($data['shipping_id']);

        $isEdit = $shippingId > 0;
        if ($isEdit) {
            $result = $this->shippingService->update($domainId, $shippingId, $data);
        } else {
            $result = $this->shippingService->create($domainId, $data);
        }

        if ($result->isSuccess()) {
            // 폼에 머무르도록 목록 redirect 대신 저장된 id/모드를 반환한다.
            // 신규 등록 후 폼은 이 id로 수정 모드로 전환되어 재저장 시 중복 생성을 막는다.
            $savedId = $isEdit ? $shippingId : (int) $result->get('shipping_id', 0);
            return JsonResponse::success(
                ['shipping_id' => $savedId, 'is_edit' => $isEdit],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 배송 템플릿 삭제
     *
     * POST /admin/shop/shipping/{id}/delete
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();

        $shippingId = (int) ($params['id'] ?? $params[0] ?? $request->json('shipping_id', 0));

        if ($shippingId <= 0) {
            return JsonResponse::error('배송 템플릿 ID가 필요합니다.');
        }

        $domainId = $context->getDomainId() ?? 1;

        if ($shippingId === $this->getDefaultTemplateId($domainId)) {
            return JsonResponse::error('쇼핑몰 기본 배송 템플릿은 삭제할 수 없습니다. 다른 템플릿을 기본으로 지정한 뒤 삭제하세요.');
        }

        $usage = $this->shippingService->getUsageCount($domainId, $shippingId);
        if ($usage > 0) {
            return JsonResponse::error("{$usage}개 상품에서 사용 중이라 삭제할 수 없습니다. 해당 상품의 배송 템플릿을 변경한 뒤 삭제하세요.");
        }

        $result = $this->shippingService->delete($domainId, $shippingId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    public function listDelete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $ids = array_values(array_filter(
            array_map('intval', (array) ($request->input('chk') ?? [])),
            fn($id) => $id > 0
        ));

        if (empty($ids)) {
            return JsonResponse::error('삭제할 배송 템플릿을 선택해주세요.');
        }

        // 기본 배송 템플릿 + 사용 중 템플릿은 벌크 삭제에서 제외
        $domainId  = $context->getDomainId() ?? 1;
        $defaultId = $this->getDefaultTemplateId($domainId);
        $usage     = $this->shippingService->getUsageCounts($domainId, $ids);

        $skipped   = 0;
        $deletable = [];
        foreach ($ids as $id) {
            if ($id === $defaultId || ($usage[$id] ?? 0) > 0) {
                $skipped++;
                continue;
            }
            $deletable[] = $id;
        }

        $deleted = 0;
        foreach ($deletable as $id) {
            if ($this->shippingService->delete($domainId, $id)->isSuccess()) {
                $deleted++;
            }
        }

        $msg = "{$deleted}건이 삭제되었습니다.";
        if ($skipped > 0) {
            $msg .= " (기본·사용 중 템플릿 {$skipped}건 제외)";
        }

        return JsonResponse::success(null, $msg);
    }

    /**
     * 폼 데이터 스키마
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => [
                'shipping_id', 'basic_cost', 'free_threshold',
                'goods_per_unit', 'return_cost', 'exchange_cost',
                'delivery_company_id',
            ],
            'enum' => [
                'shipping_method' => [
                    'values' => ['FREE', 'COND', 'PAID', 'QUANTITY', 'AMOUNT'],
                    'default' => 'PAID',
                ],
                'delivery_method' => [
                    'values' => ['COURIER', 'POSTAL', 'PICKUP', 'OWN', 'ETC'],
                    'default' => 'COURIER',
                ],
            ],
            'bool' => [
                'is_active', 'extra_cost_enabled',
            ],
            'required_string' => ['name'],
        ];
    }
}
