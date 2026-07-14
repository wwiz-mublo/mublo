<?php
namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Contract\Payment\PaymentGatewayInterface;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Service\ShippingService;
use Mublo\Packages\Shop\Service\OrderFieldService;
use Mublo\Packages\Shop\Service\ProductInfoTemplateService;
use Mublo\Service\Member\PolicyService;
use Mublo\Service\Member\MemberLevelService;

/**
 * Admin ShopConfigController
 *
 * 쇼핑몰 설정 관리 컨트롤러
 *
 * 라우팅:
 * - GET  /admin/shop/config       → index (설정 폼)
 * - POST /admin/shop/config/store → store (설정 저장)
 * - POST /admin/shop/install      → install (마이그레이션 실행)
 */
class ShopConfigController
{
    private ShopConfigService $shopConfigService;
    private MigrationRunner $migrationRunner;
    private ContractRegistry $contractRegistry;
    private ShippingService $shippingService;
    private OrderFieldService $orderFieldService;
    private PolicyService $policyService;
    private ProductInfoTemplateService $templateService;
    private MemberLevelService $memberLevelService;

    private const PACKAGE_NAME = 'Shop';

    /** 콘텐츠 스킨 대상 기능 (Front/{기능}/{skin}/ 구조로 마이그레이션된 것만 UI 노출) */
    private const CONTENT_BASE_PATH = MUBLO_PACKAGE_PATH . '/Shop/views/Front';
    private const CONTENT_FEATURES = ['Product', 'Cart', 'Order', 'Wishlist', 'Review', 'Inquiry', 'Coupon', 'Exhibition', 'Mypage', 'Ui'];

    public function __construct(
        ShopConfigService $shopConfigService,
        MigrationRunner $migrationRunner,
        ContractRegistry $contractRegistry,
        ShippingService $shippingService,
        OrderFieldService $orderFieldService,
        PolicyService $policyService,
        ProductInfoTemplateService $templateService,
        MemberLevelService $memberLevelService
    ) {
        $this->shopConfigService = $shopConfigService;
        $this->migrationRunner = $migrationRunner;
        $this->contractRegistry = $contractRegistry;
        $this->shippingService = $shippingService;
        $this->orderFieldService = $orderFieldService;
        $this->policyService = $policyService;
        $this->templateService = $templateService;
        $this->memberLevelService = $memberLevelService;
    }

    /**
     * 쇼핑몰 설정 폼
     *
     * GET /admin/shop/config
     */
    public function index(array $params, Context $context): ViewResponse
    {
        // 마이그레이션 체크
        $status = $this->migrationRunner->getStatus('package', self::PACKAGE_NAME, $this->getMigrationPath());
        if (!empty($status['pending'])) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Install')
                ->withData([
                    'pageTitle' => '쇼핑몰 패키지 설치',
                    'pending' => $status['pending'],
                ]);
        }

        $domainId = $context->getDomainId() ?? 1;

        $result = $this->shopConfigService->getConfig($domainId);
        $config = $result->get('config', []);

        $anchor = [
            'anc_basic'      => '기본 설정',
            'anc_detail'     => '상세 탭 설정',
            'anc_cs'         => 'SEO / 고객센터',
            'anc_payment'    => '결제 설정',
            'anc_price'      => '할인 / 적립',
            'anc_orderfield' => '주문 추가 필드',
            'anc_policy'     => '약관 설정',
        ];

        // 등록된 PG사 목록 (인스턴스 resolve 없이 메타만 조회)
        $paymentGateways = $this->contractRegistry->allMeta(PaymentGatewayInterface::class);

        // 배송 템플릿 목록 (기본 배송 템플릿 선택용)
        $shippingResult = $this->shippingService->getList($domainId);

        // 주문 추가 필드 목록
        $orderFields = $this->orderFieldService->getFields($domainId);

        // 활성 약관 목록 (약관 선택 UI용)
        $activePolicies = $this->policyService->getActiveByDomain($domainId);

        // 활성 상품정보 템플릿 목록 (탭 순서 UI용)
        $activeTemplates = $this->templateService->getActive($domainId);

        // 회원 레벨 목록 (포인트/할인/적립 레벨별 설정용)
        $memberLevels = $this->memberLevelService->getAll();

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Config/Index')
            ->withData([
                'pageTitle' => '쇼핑몰 설정',
                'config' => $config,
                'anchor' => $anchor,
                'paymentGateways' => $paymentGateways,
                'shippingTemplates' => $shippingResult->get('items', []),
                'orderFields' => $orderFields,
                'activePolicies' => $activePolicies,
                'activeTemplates' => $activeTemplates,
                'memberLevels' => $memberLevels,
                'contentSkins' => $this->getContentSkinOptions(),
                'currentContentSkins' => $this->parseSkinConfig($config['skin_config'] ?? ''),
            ]);
    }

    /**
     * 마이그레이션 실행
     *
     * POST /admin/shop/install
     */
    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('package', self::PACKAGE_NAME, $this->getMigrationPath());

        if ($result['success']) {
            return JsonResponse::success(
                ['redirect' => '/admin/shop/config'],
                '쇼핑몰 패키지가 설치되었습니다. (실행: ' . count($result['executed']) . '개)'
            );
        }

        return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
    }

    /**
     * 마이그레이션 경로
     */
    private function getMigrationPath(): string
    {
        return __DIR__ . '/../../database/migrations';
    }

    /**
     * 쇼핑몰 설정 저장
     *
     * POST /admin/shop/config/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];

        // 배열 → JSON 변환 (사용 PG 목록)
        if (isset($formData['payment_pg_keys_arr']) && is_array($formData['payment_pg_keys_arr'])) {
            $formData['payment_pg_keys'] = json_encode(
                array_values($formData['payment_pg_keys_arr']),
                JSON_UNESCAPED_UNICODE
            );
        } elseif (!isset($formData['payment_pg_keys'])) {
            $formData['payment_pg_keys'] = '[]';
        }
        unset($formData['payment_pg_keys_arr']);

        // 배열 → JSON 변환 (결제 수단)
        if (isset($formData['payment_methods_arr']) && is_array($formData['payment_methods_arr'])) {
            $formData['payment_methods'] = json_encode(
                array_values($formData['payment_methods_arr']),
                JSON_UNESCAPED_UNICODE
            );
        } elseif (!isset($formData['payment_methods'])) {
            $formData['payment_methods'] = '[]';
        }
        unset($formData['payment_methods_arr']);

        // 배열 → JSON 변환 (레벨별 설정)
        foreach (['point_level_settings', 'discount_level_settings', 'reward_level_settings'] as $key) {
            if (isset($formData[$key]) && is_array($formData[$key])) {
                $formData[$key] = json_encode($formData[$key], JSON_UNESCAPED_UNICODE);
            }
        }

        // 콘텐츠 스킨(기능별)은 shop_config.skin_config JSON 맵으로 저장 (프레임과 별개 축)
        // skins가 제출된 경우에만 갱신 — 미제출(부분 저장)이면 기존 값 보존
        $submittedSkins = $request->input('skins');
        if (is_array($submittedSkins)) {
            $formData['skin_config'] = $this->buildSkinConfig($submittedSkins);
        }

        $result = $this->shopConfigService->saveConfig($domainId, $formData);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 기능별 콘텐츠 스킨 목록. Front/{기능}/basic/ 이 있는(=스킨 구조로 전환된) 기능만 포함.
     *
     * @return array<string, string[]> ['Product' => ['basic', ...], ...]
     */
    private function getContentSkinOptions(): array
    {
        $out = [];
        foreach (self::CONTENT_FEATURES as $feature) {
            $dir = self::CONTENT_BASE_PATH . '/' . $feature;
            if (!is_dir($dir . '/basic')) {
                continue; // 아직 전환 안 된 기능은 노출하지 않음
            }
            $skins = array_map('basename', glob($dir . '/*', GLOB_ONLYDIR) ?: []);
            $skins = array_values(array_filter($skins, fn($s) => $s !== '' && $s[0] !== '_' && $s[0] !== '.'));
            sort($skins);
            if (($k = array_search('basic', $skins, true)) !== false) {
                unset($skins[$k]);
                array_unshift($skins, 'basic');
            }
            $out[$feature] = array_values($skins);
        }
        return $out;
    }

    /**
     * skin_config(JSON 문자열)를 [기능(소문자) => 스킨] 맵으로 파싱.
     */
    private function parseSkinConfig($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * 폼 submit된 skins[기능] 값을 검증해 skin_config JSON 문자열로 조립.
     * basic(기본/폴백)은 저장 생략 — 맵엔 비-basic 오버라이드만 남긴다.
     */
    private function buildSkinConfig($skins): string
    {
        if (!is_array($skins)) {
            return '';
        }
        $options = $this->getContentSkinOptions();
        $clean = [];
        foreach ($skins as $feature => $skin) {
            $fkey = strtolower((string) $feature);
            $ucFeature = ucfirst($fkey);
            $skin = (string) $skin;
            if ($skin !== '' && $skin !== 'basic'
                && isset($options[$ucFeature]) && in_array($skin, $options[$ucFeature], true)) {
                $clean[$fkey] = $skin;
            }
        }
        return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : '';
    }
}
