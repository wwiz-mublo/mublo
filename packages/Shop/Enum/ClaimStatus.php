<?php

namespace Mublo\Packages\Shop\Enum;

enum ClaimStatus: string
{
    case REQUESTED = 'REQUESTED';
    case ACCEPTED = 'ACCEPTED';
    case COLLECTING = 'COLLECTING';
    case COLLECTED = 'COLLECTED';
    case INSPECTING = 'INSPECTING';
    case READY_TO_SHIP = 'READY_TO_SHIP';
    case RESHIPPING = 'RESHIPPING';
    case COMPLETED = 'COMPLETED';
    case REFUSED = 'REFUSED';
    case CANCELLED = 'CANCELLED';
    case REJECTED = 'REJECTED';
    case RETURNING = 'RETURNING';
    case CLOSED = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::REQUESTED => '교환신청',
            self::ACCEPTED => '교환승인',
            self::COLLECTING => '회수중',
            self::COLLECTED => '회수완료',
            self::INSPECTING => '검수중',
            self::READY_TO_SHIP => '재출고대기',
            self::RESHIPPING => '재출고',
            self::COMPLETED => '교환완료',
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

    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }
        return $options;
    }
}
