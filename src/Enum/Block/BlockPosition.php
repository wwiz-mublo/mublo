<?php
declare(strict_types=1);
namespace Mublo\Enum\Block;

/**
 * 블록 위치 Enum
 *
 * 블록이 배치되는 위치를 나타냅니다.
 */
enum BlockPosition: string
{
    case TOPBAR = 'topbar';
    case INDEX = 'index';
    case LEFT = 'left';
    case RIGHT = 'right';
    case SUBHEAD = 'subhead';
    case SUBFOOT = 'subfoot';
    case CONTENTHEAD = 'contenthead';
    case CONTENTFOOT = 'contentfoot';

    /**
     * 위치 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::TOPBAR => '헤더 위 (최상단 바)',
            self::INDEX => '메인화면',
            self::LEFT => '왼쪽 사이드바',
            self::RIGHT => '오른쪽 사이드바',
            self::SUBHEAD => '서브페이지 상단',
            self::SUBFOOT => '서브페이지 하단',
            self::CONTENTHEAD => '콘텐츠 상단',
            self::CONTENTFOOT => '콘텐츠 하단',
        };
    }

    /**
     * 사이드바 위치인지 확인
     */
    public function isSidebar(): bool
    {
        return in_array($this, [self::LEFT, self::RIGHT], true);
    }

    /**
     * 메인 콘텐츠 영역인지 확인
     */
    public function isContent(): bool
    {
        return in_array($this, [self::INDEX, self::CONTENTHEAD, self::CONTENTFOOT], true);
    }

    /**
     * 서브페이지 영역인지 확인
     */
    public function isSubpage(): bool
    {
        return in_array($this, [self::SUBHEAD, self::SUBFOOT], true);
    }

    /**
     * 모든 위치 목록 반환 (라벨 포함)
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
