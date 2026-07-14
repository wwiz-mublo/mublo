<?php
namespace Mublo\Enum\Block;

/**
 * 블록 콘텐츠 타입 Enum
 *
 * 블록에 표시되는 콘텐츠의 종류를 나타냅니다.
 */
enum BlockContentType: string
{
    case HTML = 'html';
    case IMAGE = 'image';
    case MOVIE = 'movie';
    case OUTLOGIN = 'outlogin';
    case MENU = 'menu';
    case INCLUDE = 'include';

    /**
     * 타입 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::HTML => 'HTML 직접입력',
            self::IMAGE => '이미지',
            self::MOVIE => '동영상',
            self::OUTLOGIN => '로그인 위젯',
            self::MENU => '메뉴',
            self::INCLUDE => 'PHP 파일 포함',
        };
    }

    /**
     * 미디어 타입인지 확인
     */
    public function isMedia(): bool
    {
        return in_array($this, [self::IMAGE, self::MOVIE], true);
    }

    /**
     * 개발자용 타입인지 확인
     */
    public function isDeveloperOnly(): bool
    {
        return $this === self::INCLUDE;
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
