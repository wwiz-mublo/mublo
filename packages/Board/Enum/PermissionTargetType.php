<?php
namespace Mublo\Packages\Board\Enum;

/**
 * 게시판 권한 대상 타입 Enum
 *
 * 권한이 적용되는 대상의 유형을 나타냅니다.
 */
enum PermissionTargetType: string
{
    case GROUP = 'group';
    case CATEGORY = 'category';
    case BOARD = 'board';

    /**
     * 대상 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::GROUP => '그룹',
            self::CATEGORY => '카테고리',
            self::BOARD => '게시판',
        };
    }

    /**
     * 권한 범위가 넓은지 확인 (하위 항목에 상속)
     */
    public function isInheritable(): bool
    {
        return in_array($this, [self::GROUP, self::CATEGORY], true);
    }

    /**
     * 모든 대상 타입 목록 반환 (라벨 포함)
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
