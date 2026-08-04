<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Core\Session\SessionInterface;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ShippingFeeCalculator;

/**
 * DirectBuyService
 *
 * 바로구매 세션 관리
 *
 * 책임:
 * - 바로구매 세션 저장/조회/삭제
 * - 세션 조회 시 가격 재검증
 */
class DirectBuyService
{
    private ProductRepository $productRepository;
    private PriceCalculator $priceCalculator;
    private ShippingFeeCalculator $shippingFeeCalculator;
    private ?SessionInterface $sessionManager;

    public function __construct(
        ProductRepository $productRepository,
        PriceCalculator $priceCalculator,
        ShippingFeeCalculator $shippingFeeCalculator,
        ?SessionInterface $sessionManager = null
    ) {
        $this->productRepository = $productRepository;
        $this->priceCalculator = $priceCalculator;
        $this->shippingFeeCalculator = $shippingFeeCalculator;
        $this->sessionManager = $sessionManager;
    }

    /**
     * 바로구매 세션 저장
     */
    public function processDirectBuy(string $sessionId, int $memberId, array $cartItems, Product $product): Result
    {
        if (!$this->sessionManager) {
            return Result::failure('세션 관리자를 사용할 수 없습니다.');
        }

        $totalPrice = 0;
        $totalQuantity = 0;
        $enrichedItems = [];

        $mainImages = $this->productRepository->getMainImages([$product->getGoodsId()]);

        $lines = [];
        foreach ($cartItems as $item) {
            $totalPrice += $item['total_price'];
            $totalQuantity += $item['quantity'];

            $item['product'] = [
                'goods_id' => $product->getGoodsId(),
                'goods_name' => $product->getGoodsName(),
            ];
            $item['product_image'] = $mainImages[$product->getGoodsId()] ?? null;

            $enrichedItems[] = $item;

            $lines[] = [
                'goods_id'             => $product->getGoodsId(),
                'shipping_template_id' => $product->getShippingTemplateId(),
                'shipping_apply_type'  => $product->getShippingApplyType(),
                'total_price'          => (int) $item['total_price'],
                'quantity'             => (int) $item['quantity'],
            ];
        }

        $shipping = $this->shippingFeeCalculator->calculate($product->getDomainId(), $lines);
        if ($shipping['unresolved']) {
            return Result::failure('배송 정책이 설정되지 않았습니다. 관리자에게 문의해주세요.');
        }
        $shippingFee = $shipping['total'];

        $this->sessionManager->set('shop_direct_buy', [
            'cart_session_id' => $sessionId,
            'member_id' => $memberId,
            'items' => $enrichedItems,
            'totalPrice' => $totalPrice,
            'shippingFee' => $shippingFee,
            // 체크아웃에서 배송지 우편번호로 도서산간 추가비를 재계산하기 위해 보관
            'domain_id' => $product->getDomainId(),
            'shipping_lines' => $lines,
            'created_at' => time(),
        ]);

        return Result::success('바로구매 준비가 완료되었습니다.', [
            'redirect' => '/shop/checkout?mode=direct',
        ]);
    }

    /**
     * 바로구매 세션 데이터 조회
     *
     * 조회 시 상품 가격을 재검증하여 가격 변동 시 null 반환
     */
    public function getDirectBuyData(int $domainId): ?array
    {
        if (!$this->sessionManager) {
            return null;
        }

        $data = $this->sessionManager->get('shop_direct_buy');
        if (!$data || !is_array($data)) {
            return null;
        }

        $storedDomainId = (int) ($data['domain_id'] ?? 0);
        if ($storedDomainId <= 0 || $storedDomainId !== $domainId) {
            return null;
        }

        // 30분 만료
        if (time() - ($data['created_at'] ?? 0) > 1800) {
            $this->sessionManager->remove('shop_direct_buy');
            return null;
        }

        // 가격 재검증: 세션 저장 시점과 현재 가격 비교
        $items = $data['items'] ?? [];
        if (!empty($items)) {
            $goodsId = (int) ($items[0]['goods_id'] ?? 0);
            if ($goodsId > 0) {
                $product = $this->productRepository->findInDomain($domainId, $goodsId);
                if (!$product || !$product->isActive()) {
                    $this->sessionManager->remove('shop_direct_buy');
                    return null;
                }

                // EXTRA 옵션이 아닌 첫 번째 아이템의 가격 비교
                foreach ($items as $item) {
                    if (($item['option_type'] ?? '') === 'EXTRA') {
                        continue;
                    }
                    $priceResult = $this->priceCalculator->calculateSalesPrice(
                        $product->getDisplayPrice(),
                        $product->getDiscountType(),
                        $product->getDiscountValue()
                    );
                    if ((int) ($item['goods_price'] ?? 0) !== $priceResult['sales_price']) {
                        $this->sessionManager->remove('shop_direct_buy');
                        return null;
                    }
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * 바로구매 배송비를 배송지 우편번호 기준으로 재계산
     *
     * 세션에 보관한 라인/도메인으로 ShippingFeeCalculator를 다시 호출하여
     * 도서산간 추가배송비를 반영한다. (체크아웃 재계산·결제 청구에 공통 사용)
     *
     * @return ?array ShippingFeeCalculator::calculate() 결과. 세션 없으면 null.
     */
    public function calculateShipping(int $domainId, ?string $zipcode): ?array
    {
        $data = $this->getDirectBuyData($domainId);
        if (!$data) {
            return null;
        }
        $lines = $data['shipping_lines'] ?? [];
        return $this->shippingFeeCalculator->calculate($domainId, $lines, $zipcode);
    }

    /**
     * 바로구매 세션 데이터 삭제
     */
    public function clearDirectBuyData(): void
    {
        $this->sessionManager?->remove('shop_direct_buy');
    }
}
