<?php
declare(strict_types=1);
/**
 * Shop Package Routes
 *
 * PrefixedRouteCollector를 통해 자동으로 접두사가 적용됩니다.
 *
 * URL 규칙:
 * - Front: /{prefix}/... → /shop/...
 * - Admin: /admin/{prefix}/... → /admin/shop/...
 */

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;

// Front Controllers
use Mublo\Packages\Shop\Controller\Front\ProductController;
use Mublo\Packages\Shop\Controller\Front\CartController;
use Mublo\Packages\Shop\Controller\Front\OrderController;
use Mublo\Packages\Shop\Controller\Front\AddressController;
use Mublo\Packages\Shop\Controller\Front\CouponController as FrontCouponController;
use Mublo\Packages\Shop\Controller\Front\WishlistController;
use Mublo\Packages\Shop\Controller\Front\ReviewController as FrontReviewController;
use Mublo\Packages\Shop\Controller\Front\InquiryController as FrontInquiryController;
use Mublo\Packages\Shop\Controller\Front\ExhibitionController as FrontExhibitionController;
use Mublo\Packages\Shop\Controller\Front\PriceCompareController;

// Admin Controllers
use Mublo\Packages\Shop\Controller\Admin\ShopConfigController;
use Mublo\Packages\Shop\Controller\Admin\CategoryController;
use Mublo\Packages\Shop\Controller\Admin\OptionPresetController;
use Mublo\Packages\Shop\Controller\Admin\ProductController as AdminProductController;
use Mublo\Packages\Shop\Controller\Admin\OrderController as AdminOrderController;
use Mublo\Packages\Shop\Controller\Admin\CouponController;
use Mublo\Packages\Shop\Controller\Admin\ShippingTemplateController;
use Mublo\Packages\Shop\Controller\Admin\OrderStateController;
use Mublo\Packages\Shop\Controller\Admin\OrderFieldController;
use Mublo\Packages\Shop\Controller\Admin\ProductInfoTemplateController;
use Mublo\Packages\Shop\Controller\Admin\ReviewController as AdminReviewController;
use Mublo\Packages\Shop\Controller\Admin\InquiryController;
use Mublo\Packages\Shop\Controller\Admin\DashboardController;
use Mublo\Packages\Shop\Controller\Admin\ExhibitionController;
use Mublo\Packages\Shop\Controller\Admin\WishlistController as AdminWishlistController;
use Mublo\Packages\Shop\Controller\Admin\ClaimController;
use Mublo\Packages\Shop\Controller\Admin\PriceCompareController as AdminPriceCompareController;

return function (PrefixedRouteCollector $r): void {

    // ================================================
    // Front Routes
    // ================================================

    // 쇼핑몰 메인 (/shop)
    $r->addRoute('GET', '/', [
        'controller' => ProductController::class,
        'method'     => 'index',
    ]);

    // 상품 목록 (/shop/products)
    $r->addRoute('GET', '/products', [
        'controller' => ProductController::class,
        'method'     => 'index',
    ]);

    // 카테고리별 상품 목록 (/shop/category/{code}) — 메뉴 등록용 경로
    $r->addRoute('GET', '/category/{categoryCode}', [
        'controller' => ProductController::class,
        'method'     => 'index',
    ]);

    // 상품 목록 AJAX
    $r->addRoute('GET', '/products/list', [
        'controller' => ProductController::class,
        'method'     => 'list',
    ]);

    // 상품 상세
    $r->addRoute('GET', '/products/{id:\d+}', [
        'controller' => ProductController::class,
        'method'     => 'view',
    ]);

    // 상품 상세 (슬러그 포함)
    $r->addRoute('GET', '/products/{id:\d+}/{slug}', [
        'controller' => ProductController::class,
        'method'     => 'view',
    ]);

    // 장바구니 담기
    $r->addRoute('POST', '/cart/add', [
        'controller' => CartController::class,
        'method'     => 'add',
    ]);

    // 장바구니 목록
    $r->addRoute('GET', '/cart', [
        'controller' => CartController::class,
        'method'     => 'index',
    ]);

    // 장바구니 수량 변경
    $r->addRoute('POST', '/cart/update', [
        'controller' => CartController::class,
        'method'     => 'update',
    ]);

    // 장바구니 상품 삭제
    $r->addRoute('POST', '/cart/remove', [
        'controller' => CartController::class,
        'method'     => 'remove',
    ]);

    // 장바구니 옵션 수정
    $r->addRoute('POST', '/cart/update-option', [
        'controller' => CartController::class,
        'method'     => 'updateOption',
    ]);

    // 장바구니 가격 새로고침 (가격변동 항목 → 현재가로 담기)
    $r->addRoute('POST', '/cart/refresh-price', [
        'controller' => CartController::class,
        'method'     => 'refreshPrice',
    ]);

    // 장바구니 배송비/합계 재계산 (선택 변경 시 AJAX)
    $r->addRoute('POST', '/cart/recalculate', [
        'controller' => CartController::class,
        'method'     => 'recalculate',
    ]);

    // 체크아웃 준비
    $r->addRoute('POST', '/cart/prepare-checkout', [
        'controller' => CartController::class,
        'method'     => 'prepareCheckout',
    ]);

    // 체크아웃 페이지
    $r->addRoute('GET', '/checkout', [
        'controller' => CartController::class,
        'method'     => 'checkout',
    ]);

    // 체크아웃 배송비 재계산 (배송지 우편번호 입력 시 도서산간 추가비 반영 AJAX)
    $r->addRoute('POST', '/checkout/recalculate-shipping', [
        'controller' => CartController::class,
        'method'     => 'recalculateShipping',
    ]);

    // 결제 준비 (주문 생성 + PG prepare)
    $r->addRoute('POST', '/checkout/payment', [
        'controller' => CartController::class,
        'method'     => 'payment',
    ]);

    // 결제 검증 (PG verify + 주문 완료)
    $r->addRoute('POST', '/checkout/verify', [
        'controller' => CartController::class,
        'method'     => 'verify',
    ]);

    // 주문 완료
    $r->addRoute('GET', '/order/{orderNo}/complete', [
        'controller' => OrderController::class,
        'method'     => 'complete',
    ]);

    // 주문 내역 (회원)
    $r->addRoute('GET', '/orders', [
        'controller' => OrderController::class,
        'method'     => 'index',
    ]);

    // 비회원 주문 조회 (주문인명 + 연락처) — 정적 경로를 동적 {orderNo}보다 먼저 등록
    $r->addRoute('GET', '/order/lookup', [
        'controller' => OrderController::class,
        'method'     => 'lookupForm',
    ]);
    $r->addRoute('POST', '/order/lookup', [
        'controller' => OrderController::class,
        'method'     => 'lookup',
    ]);

    // 사용자 주문 취소 (회원 + 비회원 세션 소유 주문) — 정적 세그먼트 포함이라 {orderNo} GET과 충돌 없음
    $r->addRoute('POST', '/order/{orderNo}/cancel', [
        'controller' => OrderController::class,
        'method'     => 'cancel',
    ]);

    // 사용자 구매확정 (배송중/배송완료 → 구매확정) — 확정 후 구매후기 작성 가능
    $r->addRoute('POST', '/order/{orderNo}/confirm', [
        'controller' => OrderController::class,
        'method'     => 'confirm',
    ]);

    // 품목 단위 구매확정 — 부분 배송에서 먼저 도착한 상품만 확정할 수 있어야 한다
    $r->addRoute('POST', '/order/{orderNo}/items/{detailId}/confirm', [
        'controller' => OrderController::class,
        'method'     => 'confirmItem',
    ]);

    $r->addRoute('POST', '/order/{orderNo}/items/{detailId}/claim', [
        'controller' => OrderController::class,
        'method'     => 'requestClaim',
    ]);

    $r->addRoute('POST', '/order/{orderNo}/exchanges/{claimId}/cancel', [
        'controller' => OrderController::class,
        'method'     => 'cancelExchange',
    ]);

    // 주문 상세 (회원 + 비회원 세션 소유 주문)
    $r->addRoute('GET', '/order/{orderNo}', [
        'controller' => OrderController::class,
        'method'     => 'view',
    ]);

    // 체크아웃 파일 업로드 (주문 추가 필드)
    $r->addRoute('POST', '/checkout/upload-file', [
        'controller' => CartController::class,
        'method'     => 'uploadFieldFile',
    ]);

    // 회원 배송지 주소록
    $r->addRoute('GET', '/address/list', [
        'controller' => AddressController::class,
        'method'     => 'list',
    ]);
    $r->addRoute('POST', '/address/store', [
        'controller' => AddressController::class,
        'method'     => 'store',
    ]);
    $r->addRoute('POST', '/address/update', [
        'controller' => AddressController::class,
        'method'     => 'update',
    ]);
    $r->addRoute('POST', '/address/delete', [
        'controller' => AddressController::class,
        'method'     => 'delete',
    ]);
    $r->addRoute('POST', '/address/default', [
        'controller' => AddressController::class,
        'method'     => 'setDefault',
    ]);

    // --- 쿠폰 ---
    $r->addRoute('GET', '/coupons', [
        'controller' => FrontCouponController::class,
        'method'     => 'page',
    ]);

    // --- 쿠폰 (Front API) ---
    $r->addRoute('GET', '/api/coupons/my', [
        'controller' => FrontCouponController::class,
        'method'     => 'myCoupons',
    ]);
    $r->addRoute('GET', '/api/coupons/downloadable', [
        'controller' => FrontCouponController::class,
        'method'     => 'downloadable',
    ]);
    $r->addRoute('POST', '/api/coupons/download', [
        'controller' => FrontCouponController::class,
        'method'     => 'download',
    ]);
    $r->addRoute('GET', '/api/coupons/applicable', [
        'controller' => FrontCouponController::class,
        'method'     => 'applicable',
    ]);
    $r->addRoute('POST', '/api/coupons/register', [
        'controller' => FrontCouponController::class,
        'method'     => 'register',
    ]);

    // --- 찜 목록 ---
    $r->addRoute('GET', '/wishlist', [
        'controller' => WishlistController::class,
        'method'     => 'index',
    ]);

    $r->addRoute('POST', '/api/wishlist/toggle', [
        'controller' => WishlistController::class,
        'method'     => 'toggle',
    ]);

    // --- 구매후기 (Front) ---
    $r->addRoute('GET', '/reviews', [
        'controller' => FrontReviewController::class,
        'method'     => 'list',
    ]);

    $r->addRoute('GET', '/reviews/my', [
        'controller' => FrontReviewController::class,
        'method'     => 'myReviews',
    ]);

    // 상품 상세 탭용 구매후기 (JSON)
    $r->addRoute('GET', '/api/reviews', [
        'controller' => FrontReviewController::class,
        'method'     => 'apiList',
    ]);

    $r->addRoute('GET', '/reviews/form', [
        'controller' => FrontReviewController::class,
        'method'     => 'form',
    ]);

    $r->addRoute('POST', '/reviews/store', [
        'controller' => FrontReviewController::class,
        'method'     => 'store',
    ]);

    $r->addRoute('POST', '/reviews/delete', [
        'controller' => FrontReviewController::class,
        'method'     => 'delete',
    ]);

    // --- 상품문의 (Front) ---
    $r->addRoute('GET', '/inquiries', [
        'controller' => FrontInquiryController::class,
        'method'     => 'list',
    ]);

    $r->addRoute('GET', '/inquiries/my', [
        'controller' => FrontInquiryController::class,
        'method'     => 'myInquiries',
    ]);

    // 상품 상세 탭용 상품문의 (JSON)
    $r->addRoute('GET', '/api/inquiries', [
        'controller' => FrontInquiryController::class,
        'method'     => 'apiList',
    ]);

    $r->addRoute('POST', '/inquiries/store', [
        'controller' => FrontInquiryController::class,
        'method'     => 'store',
    ]);

    $r->addRoute('POST', '/inquiries/delete', [
        'controller' => FrontInquiryController::class,
        'method'     => 'delete',
    ]);

    // --- 기획전 (Front) ---
    $r->addRoute('GET', '/exhibitions', [
        'controller' => FrontExhibitionController::class,
        'method'     => 'index',
    ]);

    $r->addRoute('GET', '/exhibitions/{id:\d+}', [
        'controller' => FrontExhibitionController::class,
        'method'     => 'view',
    ]);

    // 슬러그 URL (숫자는 위 id 라우트가 우선 처리)
    $r->addRoute('GET', '/exhibitions/{slug}', [
        'controller' => FrontExhibitionController::class,
        'method'     => 'view',
    ]);

    // --- 가격비교 피드 (Front, 공개) ---
    //
    // 채널이 늘어도 라우트는 이 하나다. {channel} 은 ContractRegistry 에 등록된
    // 채널 키이고, /shop/feed·/shop/ep·/shop/naver 같은 경로는 비워 둔다.
    // 자기 피드를 따로 만드는 쪽이 쓸 자리다.
    $r->addRoute('GET', '/price-compare/{channel}', [
        'controller' => PriceCompareController::class,
        'method'     => 'feed',
    ]);

    // 변경분 피드 — 비교사가 전체 피드보다 자주 받아가는 주소
    $r->addRoute('GET', '/price-compare/{channel}/summary', [
        'controller' => PriceCompareController::class,
        'method'     => 'summary',
    ]);

    // ================================================
    // Admin Routes
    // ================================================

    // --- 가격비교 사이트 (안내) ---
    $r->addRoute('GET', '/admin/price-compare', [
        'controller' => AdminPriceCompareController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRoute('POST', '/admin/price-compare/store', [
        'controller' => AdminPriceCompareController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 쇼핑몰 설정 ---
    $r->addRoute('GET', '/admin/config', [
        'controller' => ShopConfigController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/config/store', [
        'controller' => ShopConfigController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 카테고리 관리 ---
    $r->addRoute('GET', '/admin/categories', [
        'controller' => CategoryController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 아이템 CRUD
    $r->addRoute('POST', '/admin/categories/item-store', [
        'controller' => CategoryController::class,
        'method'     => 'itemStore',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/categories/item-view', [
        'controller' => CategoryController::class,
        'method'     => 'itemView',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/categories/item-delete', [
        'controller' => CategoryController::class,
        'method'     => 'itemDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 트리 관리
    $r->addRoute('POST', '/admin/categories/tree-update', [
        'controller' => CategoryController::class,
        'method'     => 'treeUpdate',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 메뉴 아이템 등록/해제 (카테고리를 프론트 메뉴로 시딩)
    $r->addRoute('POST', '/admin/categories/menu-register', [
        'controller' => CategoryController::class,
        'method'     => 'menuRegister',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/categories/menu-unregister', [
        'controller' => CategoryController::class,
        'method'     => 'menuUnregister',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 옵션 프리셋 관리 ---
    $r->addRoute('GET', '/admin/options', [
        'controller' => OptionPresetController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/options/create', [
        'controller' => OptionPresetController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/options/{id:\d+}/edit', [
        'controller' => OptionPresetController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/options/store', [
        'controller' => OptionPresetController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/options/{id:\d+}/delete', [
        'controller' => OptionPresetController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/options/list-delete', [
        'controller' => OptionPresetController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 프리셋 상세 조회 (상품 등록 시 프리셋 불러오기용)
    $r->addRoute('POST', '/admin/options/detail', [
        'controller' => OptionPresetController::class,
        'method'     => 'detail',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 블록 에디터용 상품 목록 (AJAX) ---
    $r->addRoute(['GET', 'POST'], '/admin/block-items', [
        'controller' => AdminProductController::class,
        'method'     => 'blockItems',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 상품 관리 ---
    $r->addRoute('GET', '/admin/products', [
        'controller' => AdminProductController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/products/create', [
        'controller' => AdminProductController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/products/{id:\d+}/edit', [
        'controller' => AdminProductController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/products/store', [
        'controller' => AdminProductController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/products/{id:\d+}/delete', [
        'controller' => AdminProductController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/products/listDelete', [
        'controller' => AdminProductController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 주문 관리 ---
    $r->addRoute('GET', '/admin/claims', [
        'controller' => ClaimController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/claims/{claimId:\d+}', [
        'controller' => ClaimController::class,
        'method'     => 'view',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/claims/{claimId:\d+}/process', [
        'controller' => ClaimController::class,
        'method'     => 'process',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/claims/actions', [
        'controller' => ClaimController::class,
        'method'     => 'storeActions',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/orders', [
        'controller' => AdminOrderController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/orders/upload-invoices', [
        'controller' => AdminOrderController::class,
        'method'     => 'uploadInvoices',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/orders/{orderNo}', [
        'controller' => AdminOrderController::class,
        'method'     => 'view',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/orders/{orderNo}/status', [
        'controller' => AdminOrderController::class,
        'method'     => 'updateStatus',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 주문 아이템 관리 ---
    $r->addRoute('POST', '/admin/orders/{orderNo}/items/bulk-status', [
        'controller' => AdminOrderController::class,
        'method'     => 'bulkUpdateItemStatus',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/orders/{orderNo}/items/{detailId}/status', [
        'controller' => AdminOrderController::class,
        'method'     => 'updateItemStatus',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/orders/{orderNo}/items/{detailId}/cancel', [
        'controller' => AdminOrderController::class,
        'method'     => 'cancelItem',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/orders/{orderNo}/items/{detailId}/return', [
        'controller' => AdminOrderController::class,
        'method'     => 'returnItem',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 환불 ---
    $r->addRoute('POST', '/admin/orders/{orderNo}/refund', [
        'controller' => AdminOrderController::class,
        'method'     => 'refund',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRoute('POST', '/admin/orders/{orderNo}/refund-point-retry', [
        'controller' => AdminOrderController::class,
        'method'     => 'retryRefundPoint',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 배송(운송장) ---
    $r->addRoute('POST', '/admin/orders/{orderNo}/shipments', [
        'controller' => AdminOrderController::class,
        'method'     => 'registerShipment',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRoute('POST', '/admin/orders/{orderNo}/shipments/{shipmentId}', [
        'controller' => AdminOrderController::class,
        'method'     => 'updateShipment',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRoute('POST', '/admin/orders/{orderNo}/shipments/{shipmentId}/delete', [
        'controller' => AdminOrderController::class,
        'method'     => 'deleteShipment',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 관리자 메모 ---
    $r->addRoute('POST', '/admin/orders/{orderNo}/memos', [
        'controller' => AdminOrderController::class,
        'method'     => 'addMemo',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/orders/{orderNo}/memos/{memoId}/delete', [
        'controller' => AdminOrderController::class,
        'method'     => 'deleteMemo',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 주문상태 설정 ---
    $r->addRoute('GET', '/admin/order-states', [
        'controller' => OrderStateController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/order-states/store', [
        'controller' => OrderStateController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/order-states/store-actions', [
        'controller' => OrderStateController::class,
        'method'     => 'storeActions',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 쿠폰 관리 ---
    $r->addRoute('GET', '/admin/coupons', [
        'controller' => CouponController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/coupons/create', [
        'controller' => CouponController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/coupons/{id:\d+}/edit', [
        'controller' => CouponController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/coupons/store', [
        'controller' => CouponController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/coupons/{id:\d+}/delete', [
        'controller' => CouponController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/coupons/list-delete', [
        'controller' => CouponController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/coupons/issue', [
        'controller' => CouponController::class,
        'method'     => 'issue',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 배송 템플릿 ---
    $r->addRoute('GET', '/admin/shipping', [
        'controller' => ShippingTemplateController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/shipping/create', [
        'controller' => ShippingTemplateController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/shipping/{id:\d+}/edit', [
        'controller' => ShippingTemplateController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/shipping/store', [
        'controller' => ShippingTemplateController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/shipping/{id:\d+}/delete', [
        'controller' => ShippingTemplateController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/shipping/list-delete', [
        'controller' => ShippingTemplateController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 주문 추가 필드 관리 ---
    $r->addRoute('POST', '/admin/order-fields/store', [
        'controller' => OrderFieldController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/order-fields/delete', [
        'controller' => OrderFieldController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/order-fields/order-update', [
        'controller' => OrderFieldController::class,
        'method'     => 'orderUpdate',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 상품정보 템플릿 ---
    $r->addRoute('GET', '/admin/info-templates', [
        'controller' => ProductInfoTemplateController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/info-templates/create', [
        'controller' => ProductInfoTemplateController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/info-templates/{id}/edit', [
        'controller' => ProductInfoTemplateController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/info-templates/store', [
        'controller' => ProductInfoTemplateController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/info-templates/delete', [
        'controller' => ProductInfoTemplateController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/info-templates/list-delete', [
        'controller' => ProductInfoTemplateController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/info-templates/list-sort', [
        'controller' => ProductInfoTemplateController::class,
        'method'     => 'listSort',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 구매후기 ---
    $r->addRoute('GET', '/admin/reviews', [
        'controller' => AdminReviewController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/reviews/create', [
        'controller' => AdminReviewController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 관리자 후기 등록 — 후기 미작성 상품 목록 (모달 피커)
    $r->addRoute('GET', '/admin/reviews/reviewable-items', [
        'controller' => AdminReviewController::class,
        'method'     => 'reviewableItems',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/reviews/{id}/edit', [
        'controller' => AdminReviewController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/reviews/store', [
        'controller' => AdminReviewController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/reviews/reply', [
        'controller' => AdminReviewController::class,
        'method'     => 'reply',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/reviews/toggle-visibility', [
        'controller' => AdminReviewController::class,
        'method'     => 'toggleVisibility',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/reviews/delete', [
        'controller' => AdminReviewController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/reviews/list-modify', [
        'controller' => AdminReviewController::class,
        'method'     => 'listModify',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/reviews/list-delete', [
        'controller' => AdminReviewController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 상품문의 (QNA) ---
    $r->addRoute('GET', '/admin/inquiries', [
        'controller' => InquiryController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/inquiries/create', [
        'controller' => InquiryController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/inquiries/{id}/edit', [
        'controller' => InquiryController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/inquiries/store', [
        'controller' => InquiryController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/inquiries/answer', [
        'controller' => InquiryController::class,
        'method'     => 'answer',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/inquiries/delete', [
        'controller' => InquiryController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/inquiries/list-modify', [
        'controller' => InquiryController::class,
        'method'     => 'listModify',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/inquiries/list-delete', [
        'controller' => InquiryController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 대시보드 ---
    $r->addRoute('GET', '/admin/dashboard', [
        'controller' => DashboardController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 기획전 관리 ---
    $r->addRoute('GET', '/admin/exhibitions', [
        'controller' => ExhibitionController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/exhibitions/create', [
        'controller' => ExhibitionController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/exhibitions/{id:\d+}/edit', [
        'controller' => ExhibitionController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/exhibitions/store', [
        'controller' => ExhibitionController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/exhibitions/upload-banner', [
        'controller' => ExhibitionController::class,
        'method'     => 'uploadBanner',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/exhibitions/delete', [
        'controller' => ExhibitionController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/exhibitions/list-delete', [
        'controller' => ExhibitionController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/exhibitions/menu-register', [
        'controller' => ExhibitionController::class,
        'method'     => 'menuRegister',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/exhibitions/add-item', [
        'controller' => ExhibitionController::class,
        'method'     => 'addItem',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/exhibitions/delete-item', [
        'controller' => ExhibitionController::class,
        'method'     => 'deleteItem',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/exhibitions/sync-items', [
        'controller' => ExhibitionController::class,
        'method'     => 'syncItems',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 찜한상품 관리 ---
    $r->addRoute('GET', '/admin/wishlists', [
        'controller' => AdminWishlistController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/wishlists/delete', [
        'controller' => AdminWishlistController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/wishlists/list-delete', [
        'controller' => AdminWishlistController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 플러그인 설치 (마이그레이션) ---
    $r->addRoute('POST', '/admin/install', [
        'controller' => ShopConfigController::class,
        'method'     => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);
};
