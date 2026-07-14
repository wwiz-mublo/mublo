<?php
namespace Mublo\Enum\Policy;

/**
 * 정책/약관 타입 Enum
 *
 * 약관의 종류를 나타냅니다.
 */
enum PolicyType: string
{
    case TERMS = 'terms';
    case PRIVACY = 'privacy';
    case MARKETING = 'marketing';
    case LOCATION = 'location';
    case CUSTOM = 'custom';

    /**
     * 타입 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::TERMS => '이용약관',
            self::PRIVACY => '개인정보처리방침',
            self::MARKETING => '마케팅 수신 동의',
            self::LOCATION => '위치정보 이용약관',
            self::CUSTOM => '커스텀',
        };
    }

    /**
     * 필수 약관 여부 (이용약관, 개인정보처리방침)
     */
    public function isEssential(): bool
    {
        return in_array($this, [self::TERMS, self::PRIVACY], true);
    }

    /**
     * 선택 약관 여부
     */
    public function isOptional(): bool
    {
        return !$this->isEssential();
    }

    /**
     * 모든 타입 목록 반환 (라벨 포함)
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }
}
