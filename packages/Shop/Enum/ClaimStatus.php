<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Enum;

/**
 * 클레임(반품·교환) 처리 상태.
 *
 * 교환과 반품은 회수·검수까지 같은 길을 걷고 마지막 갈래에서만 갈린다.
 * 교환은 재출고로, 반품은 환불로 끝난다.
 */
enum ClaimStatus: string
{
    case REQUESTED = 'REQUESTED';
    case ACCEPTED = 'ACCEPTED';
    case COLLECTING = 'COLLECTING';
    case COLLECTED = 'COLLECTED';
    case INSPECTING = 'INSPECTING';
    case READY_TO_SHIP = 'READY_TO_SHIP';
    case RESHIPPING = 'RESHIPPING';
    case READY_TO_REFUND = 'READY_TO_REFUND';
    case COMPLETED = 'COMPLETED';
    case REFUSED = 'REFUSED';
    case CANCELLED = 'CANCELLED';
    case REJECTED = 'REJECTED';
    case RETURNING = 'RETURNING';
    case CLOSED = 'CLOSED';

    /**
     * 상태 라벨.
     *
     * 신청·승인·완료는 교환이냐 반품이냐에 따라 말이 달라진다. 유형을 주지 않으면
     * 어느 쪽에도 치우치지 않는 표현을 쓴다(목록 필터처럼 두 유형이 섞이는 자리).
     */
    public function label(?string $returnType = null): string
    {
        $typed = match ($returnType) {
            'EXCHANGE' => match ($this) {
                self::REQUESTED => '교환신청',
                self::ACCEPTED => '교환승인',
                self::COMPLETED => '교환완료',
                default => null,
            },
            'RETURN' => match ($this) {
                self::REQUESTED => '반품신청',
                self::ACCEPTED => '반품승인',
                self::COMPLETED => '반품완료',
                default => null,
            },
            default => null,
        };

        return $typed ?? match ($this) {
            self::REQUESTED => '신청접수',
            self::ACCEPTED => '승인',
            self::COLLECTING => '회수중',
            self::COLLECTED => '회수완료',
            self::INSPECTING => '검수중',
            self::READY_TO_SHIP => '재출고대기',
            self::RESHIPPING => '재출고',
            self::READY_TO_REFUND => '환불대기',
            self::COMPLETED => '처리완료',
            self::REFUSED => '신청거절',
            self::CANCELLED => '신청취소',
            self::REJECTED => '검수거절',
            self::RETURNING => '고객반송',
            self::CLOSED => '종결',
        };
    }

    public function isActive(): bool
    {
        return !in_array($this, [self::COMPLETED, self::REFUSED, self::CANCELLED, self::CLOSED], true);
    }

    /**
     * 이 클레임이 주문 수량을 잡아먹고 있는지.
     *
     * 기준은 "그 개체가 고객 손을 떠났는가" 다. 거절·취소·종결된 건은 물건이 고객에게
     * 있으므로 수량이 되돌아오고, 반품·취소 완료는 우리가 받았거나 보내지 않았으므로
     * 소진된다. 교환 완료는 교체품이 고객에게 있으니 되돌아온다 — 교체받은 상품이
     * 또 하자일 수 있어 다시 신청할 수 있어야 한다.
     *
     * (ClaimRepository::getActiveQuantityForUpdate 의 SQL 조건과 같은 규칙.
     *  프론트가 남은 수량을 미리 계산할 때도 이 판정을 쓴다 — 두 규칙이 갈리면
     *  눌리는데 거절당하는 버튼이 생긴다)
     */
    public function consumesQuantity(string $returnType = ''): bool
    {
        if (in_array([$this, $returnType], [[self::COMPLETED, 'EXCHANGE']], true)) {
            return false;
        }
        return !in_array($this, [self::REFUSED, self::CANCELLED, self::CLOSED], true);
    }

    /** 이 상태가 해당 클레임 유형의 길에 있는지 (교환은 재출고로, 반품은 환불로 끝난다). */
    public function appliesTo(string $returnType): bool
    {
        return match ($this) {
            self::READY_TO_SHIP, self::RESHIPPING => $returnType === 'EXCHANGE',
            self::READY_TO_REFUND => $returnType === 'RETURN',
            default => true,
        };
    }

    /**
     * 상태 목록. 유형을 주면 그 유형이 실제로 거치는 상태만 돌려준다.
     *
     * @return array<string, string>
     */
    public static function options(?string $returnType = null): array
    {
        $options = [];
        foreach (self::cases() as $status) {
            if ($returnType !== null && !$status->appliesTo($returnType)) {
                continue;
            }
            $options[$status->value] = $status->label($returnType);
        }
        return $options;
    }
}
