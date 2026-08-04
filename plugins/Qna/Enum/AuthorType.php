<?php
declare(strict_types=1);
namespace Mublo\Plugin\Qna\Enum;

/**
 * 스레드 글 작성 주체
 *
 * member : 문의자(회원)
 * admin  : 운영자
 * guest  : 비회원(미래 대비)
 */
enum AuthorType: string
{
    case Member = 'member';
    case Admin  = 'admin';
    case Guest  = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Member => '문의자',
            self::Admin  => '운영자',
            self::Guest  => '비회원',
        };
    }

    public function isStaff(): bool
    {
        return $this === self::Admin;
    }
}
