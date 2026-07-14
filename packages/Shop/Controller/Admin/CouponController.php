<?php
namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Shop\Service\CouponService;
use Mublo\Packages\Shop\Service\ProductService;
use Mublo\Packages\Shop\Enum\CouponType;
use Mublo\Helper\Form\FormHelper;

/**
 * Admin CouponController
 *
 * 쿠폰 관리 컨트롤러
 *
 * 라우팅:
 * - GET  /admin/shop/coupons              → index (쿠폰 목록)
 * - GET  /admin/shop/coupons/create       → create (생성 폼)
 * - GET  /admin/shop/coupons/{id}/edit    → edit (수정 폼)
 * - POST /admin/shop/coupons/store        → store (생성/수정)
 * - POST /admin/shop/coupons/{id}/delete  → delete (삭제)
 */
class CouponController
{
    private CouponService $couponService;
    private ?ProductService $productService;

    public function __construct(CouponService $couponService, ?ProductService $productService = null)
    {
        $this->couponService = $couponService;
        $this->productService = $productService;
    }

    /**
     * 쿠폰 목록
     *
     * GET /admin/shop/coupons
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $page = (int) ($request->get('page') ?? 1);
        $perPage = (int) ($request->get('per_page') ?? 20);

        $result = $this->couponService->getList($domainId, $page, $perPage);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Coupon/List')
            ->withData([
                'pageTitle' => '쿠폰 관리',
                'coupons' => $result->get('items', []),
                'pagination' => $result->get('pagination', []),
                'couponTypeOptions' => CouponType::options(),
            ]);
    }

    /**
     * 쿠폰 생성 폼
     *
     * GET /admin/shop/coupons/create
     */
    public function create(array $params, Context $context): ViewResponse
    {
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Coupon/Form')
            ->withData([
                'pageTitle' => '쿠폰 등록',
                'isEdit' => false,
                'coupon' => null,
                'couponTypeOptions' => CouponType::options(),
            ]);
    }

    /**
     * 쿠폰 수정 폼
     *
     * GET /admin/shop/coupons/{id}/edit
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $couponGroupId = (int) ($params['id'] ?? $params[0] ?? $request->query('id', 0));

        if ($couponGroupId <= 0) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => '쿠폰을 찾을 수 없습니다.']);
        }

        $result = $this->couponService->getDetail($couponGroupId);

        if ($result->isFailure()) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => $result->getMessage()]);
        }

        $coupon = $result->get('coupon', []);

        // 대상 상품명 조회
        if (!empty($coupon['target_goods_id']) && $this->productService) {
            $productResult = $this->productService->getDetail((int) $coupon['target_goods_id'], $domainId);
            if ($productResult->isSuccess()) {
                $coupon['target_goods_name'] = $productResult->get('product', [])['goods_name'] ?? '';
            }
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Coupon/Form')
            ->withData([
                'pageTitle' => '쿠폰 수정',
                'isEdit' => true,
                'coupon' => $coupon,
                'couponTypeOptions' => CouponType::options(),
            ]);
    }

    /**
     * 쿠폰 저장 (생성/수정)
     *
     * POST /admin/shop/coupons/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $couponGroupId = (int) ($data['coupon_group_id'] ?? 0);

        if ($couponGroupId > 0) {
            $result = $this->couponService->update($couponGroupId, $data);
        } else {
            $result = $this->couponService->create($domainId, $data);
        }

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['redirect' => '/admin/shop/coupons'],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 쿠폰 삭제
     *
     * POST /admin/shop/coupons/{id}/delete
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();

        $couponGroupId = (int) ($params['id'] ?? $params[0] ?? $request->json('coupon_group_id', 0));

        if ($couponGroupId <= 0) {
            return JsonResponse::error('쿠폰 ID가 필요합니다.');
        }

        $result = $this->couponService->delete($couponGroupId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 쿠폰 일괄 삭제
     *
     * POST /admin/shop/coupons/list-delete
     */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $ids = array_values(array_filter(
            array_map('intval', (array) ($request->input('chk') ?? [])),
            fn($id) => $id > 0
        ));

        if (empty($ids)) {
            return JsonResponse::error('삭제할 쿠폰을 선택해주세요.');
        }

        // 발급 이력이 있는 정책은 삭제 불가 → 제외 (서비스 delete도 이중 가드)
        $deleted = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            if ($this->couponService->delete($id)->isSuccess()) {
                $deleted++;
            } else {
                $skipped++;
            }
        }

        $msg = "{$deleted}건이 삭제되었습니다.";
        if ($skipped > 0) {
            $msg .= " (발급 이력 등으로 {$skipped}건 제외)";
        }

        return JsonResponse::success(null, $msg);
    }

    /**
     * 관리자 수동 쿠폰 발급
     *
     * POST /admin/shop/coupons/issue
     */
    public function issue(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();

        $couponGroupId = (int) $request->json('coupon_group_id', 0);
        $memberId = (int) $request->json('member_id', 0);

        if ($couponGroupId <= 0) {
            return JsonResponse::error('쿠폰 정책을 선택해주세요.');
        }
        if ($memberId <= 0) {
            return JsonResponse::error('회원을 선택해주세요.');
        }

        $result = $this->couponService->issueCoupon($couponGroupId, $memberId);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 폼 데이터 스키마
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => [
                'coupon_group_id',
                'discount_value', 'max_discount', 'min_order_amount',
                'valid_days', 'target_goods_id',
                'use_limit_per_member', 'download_limit_per_member',
                'total_issue_limit',
            ],
            'enum' => [
                'coupon_type' => ['values' => ['ADMIN', 'AUTO', 'DOWNLOAD'], 'default' => 'ADMIN'],
                'coupon_method' => ['values' => ['ORDER', 'GOODS', 'CATEGORY', 'SHIPPING'], 'default' => 'ORDER'],
                'discount_type' => ['values' => ['FIXED', 'PERCENTAGE'], 'default' => 'FIXED'],
                'duplicate_policy' => ['values' => ['DENY_SAME_METHOD', 'ALLOW', 'DENY_ALL'], 'default' => 'DENY_SAME_METHOD'],
                'auto_issue_trigger' => ['values' => ['JOIN', 'LOGIN', 'LEVEL'], 'default' => null],
            ],
            'bool' => [
                'is_active', 'first_order_only',
            ],
            'date' => [
                'issue_start', 'issue_end',
            ],
            'required_string' => ['name'],
        ];
    }
}
