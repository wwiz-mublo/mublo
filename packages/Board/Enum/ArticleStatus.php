<?php
namespace Mublo\Packages\Board\Enum;

/**
 * 게시글/댓글 상태 Enum
 *
 * 게시글 및 댓글의 발행 상태를 나타냅니다.
 */
enum ArticleStatus: string
{
    case PUBLISHED = 'published';
    case DRAFT = 'draft';
    case DELETED = 'deleted';

    /**
     * 상태 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::PUBLISHED => '게시됨',
            self::DRAFT => '임시저장',
            self::DELETED => '삭제됨',
        };
    }

    /**
     * 목록에 표시되는 상태인지 확인
     */
    public function isVisible(): bool
    {
        return $this === self::PUBLISHED;
    }

    /**
     * 수정 가능한 상태인지 확인
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::PUBLISHED, self::DRAFT], true);
    }

    /**
     * 모든 상태 목록 반환 (라벨 포함)
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
