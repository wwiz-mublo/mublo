<?php

namespace Mublo\Packages\Shop\Enum;

/**
 * @deprecated OrderAction으로 대체됨. Repack-shop 호환용으로만 유지.
 * @see OrderAction
 */
enum OrderStatus: string
{
    case PENDING = 'PENDING';
    case PAID = 'PAID';
    case PREPARING = 'PREPARING';
    case SHIPPED = 'SHIPPED';
    case DELIVERED = 'DELIVERED';
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED = 'CANCELLED';
    case RETURNED = 'RETURNED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '주문접수',
            self::PAID => '결제완료',
            self::PREPARING => '배송준비',
            self::SHIPPED => '배송중',
            self::DELIVERED => '배송완료',
            self::CONFIRMED => '구매확정',
            self::CANCELLED => '주문취소',
            self::RETURNED => '반품완료',
        };
    }

    public function isActive(): bool
    {
        return !in_array($this, [self::CANCELLED, self::RETURNED]);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::PENDING, self::PAID]);
    }

    public function isShipped(): bool
    {
        return in_array($this, [self::SHIPPED, self::DELIVERED, self::CONFIRMED]);
    }

    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }
}
