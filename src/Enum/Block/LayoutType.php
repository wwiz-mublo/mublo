<?php
namespace Mublo\Enum\Block;

/**
 * 레이아웃 타입 Enum
 *
 * 페이지의 레이아웃 구성을 나타냅니다.
 */
enum LayoutType: int
{
    case FULL = 1;
    case LEFT = 2;
    case RIGHT = 3;
    case BOTH = 4;

    /**
     * 레이아웃 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::FULL => '1단 (전체)',
            self::LEFT => '2단 좌측',
            self::RIGHT => '2단 우측',
            self::BOTH => '3단',
        };
    }

    /**
     * 왼쪽 사이드바가 있는지 확인
     */
    public function hasLeftSidebar(): bool
    {
        return in_array($this, [self::LEFT, self::BOTH], true);
    }

    /**
     * 오른쪽 사이드바가 있는지 확인
     */
    public function hasRightSidebar(): bool
    {
        return in_array($this, [self::RIGHT, self::BOTH], true);
    }

    /**
     * 사이드바 없이 전체 너비인지 확인
     */
    public function isFullWidth(): bool
    {
        return $this === self::FULL;
    }

    /**
     * 사용 가능한 블록 위치 반환
     */
    public function getAvailablePositions(): array
    {
        $positions = BlockPosition::options();

        return match($this) {
            self::FULL => array_filter($positions, fn($_, $key) =>
                !in_array($key, ['left', 'right'], true), ARRAY_FILTER_USE_BOTH),
            self::LEFT => array_filter($positions, fn($_, $key) =>
                $key !== 'right', ARRAY_FILTER_USE_BOTH),
            self::RIGHT => array_filter($positions, fn($_, $key) =>
                $key !== 'left', ARRAY_FILTER_USE_BOTH),
            self::BOTH => $positions,
        };
    }

    /**
     * 모든 레이아웃 목록 반환 (라벨 포함)
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
