<?php
namespace Mublo\Packages\Board\Enum;

/**
 * 게시판 권한 타입 Enum
 *
 * 게시판 관리자의 권한 수준을 나타냅니다.
 */
enum PermissionType: string
{
    case ADMIN = 'admin';
    case MODERATOR = 'moderator';
    case EDITOR = 'editor';

    /**
     * 권한 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::ADMIN => '관리자',
            self::MODERATOR => '중재자',
            self::EDITOR => '에디터',
        };
    }

    /**
     * 전체 관리 권한 여부
     */
    public function hasFullAccess(): bool
    {
        return $this === self::ADMIN;
    }

    /**
     * 글/댓글 관리 가능 여부
     */
    public function canModerate(): bool
    {
        return in_array($this, [self::ADMIN, self::MODERATOR], true);
    }

    /**
     * 글 작성/수정 가능 여부
     */
    public function canEdit(): bool
    {
        return true; // 모든 권한 타입은 글 작성/수정 가능
    }

    /**
     * 모든 권한 목록 반환 (라벨 포함)
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
