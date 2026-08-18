<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Enum;

/**
 * 클레임(교환·반품) 사유.
 *
 * 단순 라벨이 아니라 비용 부담을 정하는 값이다. 불량·오배송·배송지연은 판매자 귀책이라
 * 비용을 물리지 않고, 나머지는 고객 귀책이라 교환비를 청구하거나 반품비를 환불액에서
 * 뺀다. 그래서 ClaimService::request 는 고객이 보낸 귀책값을 믿지 않고 이 규칙으로
 * 다시 확정한다 — 안 그러면 단순 변심을 불량으로 골라 비용을 피할 수 있다.
 *
 * 값 목록은 DB(`shop_returns.reason_type` ENUM)와 한 쌍이다. 사유를 추가하려면 여기와
 * 마이그레이션을 함께 고쳐야 한다.
 *
 * 상점마다 사유를 다르게 쓰고 싶다는 요구가 오면 그때 order_states 처럼 설정으로 푼다.
 * 다만 그때도 귀책이 사유와 한 몸으로 따라다녀야 한다 — 귀책 없는 사유를 허용하면
 * 그 사유로 들어온 클레임은 누가 배송비를 낼지 정해지지 않은 채 접수된다.
 */
enum ClaimReason: string
{
    case CHANGE_MIND = 'CHANGE_MIND';
    case WRONG_OPTION = 'WRONG_OPTION';
    case DEFECT = 'DEFECT';
    case WRONG_PRODUCT = 'WRONG_PRODUCT';
    case LATE_DELIVERY = 'LATE_DELIVERY';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::CHANGE_MIND => '단순 변심',
            self::WRONG_OPTION => '옵션을 잘못 선택함',
            self::DEFECT => '상품 불량',
            self::WRONG_PRODUCT => '오배송',
            self::LATE_DELIVERY => '배송 지연',
            self::OTHER => '기타',
        };
    }

    /** 판매자 귀책 사유인지 (비용을 고객에게 물리지 않는다). */
    public function isSellerFault(): bool
    {
        return in_array($this, [self::DEFECT, self::WRONG_PRODUCT, self::LATE_DELIVERY], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $reason) {
            $options[$reason->value] = $reason->label();
        }
        return $options;
    }

    /** 코드 → 라벨 (알 수 없는 값이면 원문을 그대로 돌려준다). */
    public static function labelFor(?string $value): string
    {
        $value = (string) $value;
        return self::tryFrom($value)?->label() ?? $value;
    }
}
