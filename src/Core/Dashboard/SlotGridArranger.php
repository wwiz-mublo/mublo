<?php

namespace Mublo\Core\Dashboard;

/**
 * AUTO 모드 4슬롯 그리드 정렬
 *
 * Priority 순서를 보존하는 Sequential 배치.
 * 현재 Row에 공간이 부족하면 다음 Row로 넘어간다 (Greedy Fill 미적용).
 */
class SlotGridArranger
{
    public const MAX_SLOTS = 4;

    /** 슬롯 → Bootstrap col-lg 클래스 매핑 */
    private const SLOT_CLASSES = [
        1 => 'col-12 col-md-6 col-lg-3',
        2 => 'col-12 col-lg-6',
        3 => 'col-12 col-lg-9',
        4 => 'col-12',
    ];

    /**
     * 위젯 배열을 4슬롯 기반 row/col 그리드로 정렬
     *
     * @param array $widgets [['widget' => DashboardWidgetInterface, 'priority' => int], ...]
     * @return array 2차원 배열 [row => [['widget' => ..., 'slot' => int, 'col' => int, 'colClass' => string], ...]]
     */
    public function arrange(array $widgets): array
    {
        usort($widgets, fn($a, $b) => $a['priority'] <=> $b['priority']);

        $grid = [];
        $currentRow = 0;
        $remainingSlots = self::MAX_SLOTS;

        foreach ($widgets as $entry) {
            $slot = max(1, min(self::MAX_SLOTS, $entry['widget']->defaultSlot()));

            if ($slot > $remainingSlots) {
                $currentRow++;
                $remainingSlots = self::MAX_SLOTS;
            }

            $col = self::MAX_SLOTS - $remainingSlots;
            $grid[$currentRow][] = [
                'widget'   => $entry['widget'],
                'slot'     => $slot,
                'col'      => $col,
                'colClass' => self::SLOT_CLASSES[$slot] ?? self::SLOT_CLASSES[2],
            ];

            $remainingSlots -= $slot;
            if ($remainingSlots <= 0) {
                $currentRow++;
                $remainingSlots = self::MAX_SLOTS;
            }
        }

        return $grid;
    }

    /**
     * 슬롯 크기에 해당하는 Bootstrap 클래스 반환
     */
    public static function slotClass(int $slot): string
    {
        $slot = max(1, min(self::MAX_SLOTS, $slot));
        return self::SLOT_CLASSES[$slot];
    }
}
