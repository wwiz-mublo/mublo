<?php
namespace Mublo\Packages\Board\Enum;

/**
 * 반응 대상 타입 Enum
 *
 * 반응이 적용되는 대상을 나타냅니다.
 */
enum ReactionTargetType: string
{
    case ARTICLE = 'article';
    case COMMENT = 'comment';

    /**
     * 대상 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::ARTICLE => '게시글',
            self::COMMENT => '댓글',
        };
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
