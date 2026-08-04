<?php
declare(strict_types=1);
namespace Mublo\Plugin\Qna\Enum;

/**
 * 문의 처리 상태
 *
 * open     : 접수(운영자 답변 대기)
 * answered : 운영자 답변 완료
 * closed   : 종료
 * hold     : 보류(추가 확인 필요 등)
 */
enum InquiryStatus: string
{
    case Open     = 'open';
    case Answered = 'answered';
    case Closed   = 'closed';
    case Hold     = 'hold';

    public function label(): string
    {
        return match ($this) {
            self::Open     => '접수',
            self::Answered => '답변완료',
            self::Closed   => '종료',
            self::Hold     => '보류',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Open     => 'warning',
            self::Answered => 'success',
            self::Closed   => 'secondary',
            self::Hold     => 'info',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases()
        );
    }
}
