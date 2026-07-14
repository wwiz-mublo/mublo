<?php

namespace Mublo\Core\Dashboard;

use Mublo\Repository\DashboardLayoutRepository;

/**
 * 대시보드 레이아웃 로드/저장/모드 판단
 */
class DashboardLayoutManager
{
    private DashboardLayoutRepository $repo;
    private DashboardWidgetRegistry $registry;
    private LayoutSanitizer $sanitizer;

    public function __construct(
        DashboardLayoutRepository $repo,
        DashboardWidgetRegistry $registry,
        LayoutSanitizer $sanitizer
    ) {
        $this->repo = $repo;
        $this->registry = $registry;
        $this->sanitizer = $sanitizer;
    }

    /**
     * 사용자 레이아웃 로드 + sanitize + 새 위젯 자동 삽입
     */
    public function load(int $domainId, int $userId): array
    {
        $layout = $this->repo->findByUser($domainId, $userId);

        // 패키지 제거 대응: Registry에 없는 위젯 제거 + row 정규화
        if (!empty($layout)) {
            $layout = $this->sanitizer->sanitize($layout, $this->registry->ids());
        }

        // MANUAL 사용자: 새로 추가된 위젯 자동 삽입 (DB 영속화)
        if (!empty($layout) && $this->hasPosition($layout)) {
            $layout = $this->appendNewWidgets($layout, $domainId, $userId);
        }

        return $layout;
    }

    /**
     * 모드 판단
     *
     * @return string 'AUTO' | 'AUTO_OVERRIDE' | 'MANUAL'
     */
    public function getMode(int $domainId, int $userId): string
    {
        $rows = $this->repo->findByUser($domainId, $userId);

        if (empty($rows)) {
            return 'AUTO';
        }

        return $this->hasPosition($rows) ? 'MANUAL' : 'AUTO_OVERRIDE';
    }

    /**
     * 레이아웃에서 모드 판단 (이미 로드된 데이터 기준)
     */
    public function getModeFromLayout(array $layout): string
    {
        if (empty($layout)) {
            return 'AUTO';
        }

        return $this->hasPosition($layout) ? 'MANUAL' : 'AUTO_OVERRIDE';
    }

    /**
     * hidden 위젯 제외
     *
     * @param array $widgets Registry에서 가져온 위젯 배열
     * @param array $layout DB 레이아웃 배열
     * @return array hidden이 아닌 위젯만
     */
    public function filterHidden(array $widgets, array $layout): array
    {
        $hiddenIds = [];
        foreach ($layout as $entry) {
            if (!empty($entry['hidden'])) {
                $hiddenIds[] = $entry['widget_id'];
            }
        }

        if (empty($hiddenIds)) {
            return $widgets;
        }

        return array_filter($widgets, function ($entry) use ($hiddenIds) {
            $id = $entry['widget'] instanceof DashboardWidgetInterface
                ? $entry['widget']->id()
                : ($entry['widget_id'] ?? '');
            return !in_array($id, $hiddenIds, true);
        });
    }

    /**
     * 숨겨진 위젯 목록 (복원용)
     *
     * @return array [['widget_id' => ..., 'title' => ..., 'source' => ...], ...]
     */
    public function getHiddenWidgets(array $layout): array
    {
        $hidden = [];
        foreach ($layout as $entry) {
            if (!empty($entry['hidden'])) {
                $widget = $this->registry->get($entry['widget_id']);
                if ($widget) {
                    $hidden[] = [
                        'widget_id' => $entry['widget_id'],
                        'title'     => $widget->title(),
                        'source'    => $this->registry->getSource($entry['widget_id']),
                    ];
                }
            }
        }
        return $hidden;
    }

    /**
     * MANUAL 모드 그리드 정렬 (DB row/col 기반)
     *
     * row 내 slot 합계가 4를 초과하면 초과분을 다음 row로 밀어낸다.
     *
     * @return array 2차원 배열 [row => [['widget' => ..., 'slot' => ..., 'col' => ..., 'colClass' => ...], ...]]
     */
    public function arrangeManual(array $widgets, array $layout): array
    {
        // widget_id → widget 인스턴스 맵
        $widgetMap = [];
        foreach ($widgets as $entry) {
            $w = $entry['widget'];
            $widgetMap[$w->id()] = $entry;
        }

        // layout을 row/col로 정렬
        usort($layout, function ($a, $b) {
            $rowCmp = ($a['row'] ?? 0) <=> ($b['row'] ?? 0);
            return $rowCmp !== 0 ? $rowCmp : (($a['col'] ?? 0) <=> ($b['col'] ?? 0));
        });

        // 1차: row별 위젯 목록 구성
        $rowGroups = [];
        foreach ($layout as $entry) {
            $widgetId = $entry['widget_id'];
            if (!isset($widgetMap[$widgetId])) {
                continue;
            }

            $widget = $widgetMap[$widgetId]['widget'];
            $slot = $entry['slot_size'] ?? $widget->defaultSlot();
            $slot = max(1, min(4, $slot));
            $row = $entry['row'] ?? 0;

            $rowGroups[$row][] = [
                'widget' => $widget,
                'slot'   => $slot,
            ];
        }

        ksort($rowGroups);

        // 2차: row별 slot 합계 검증 — 초과 시 다음 row로 밀어냄
        $grid = [];
        $outputRow = 0;

        foreach ($rowGroups as $items) {
            $usedSlots = 0;

            foreach ($items as $item) {
                if ($usedSlots + $item['slot'] > SlotGridArranger::MAX_SLOTS) {
                    // 현재 row 꽉 참 → 다음 row
                    $outputRow++;
                    $usedSlots = 0;
                }

                $grid[$outputRow][] = [
                    'widget'   => $item['widget'],
                    'slot'     => $item['slot'],
                    'col'      => $usedSlots,
                    'colClass' => SlotGridArranger::slotClass($item['slot']),
                ];

                $usedSlots += $item['slot'];
            }

            $outputRow++;
        }

        return $grid;
    }

    /**
     * 위젯 숨김
     */
    public function hideWidget(int $domainId, int $userId, string $widgetId): void
    {
        $this->repo->save($domainId, $userId, $widgetId, [
            'hidden' => 1,
        ]);
    }

    /**
     * 위젯 복원
     */
    public function showWidget(int $domainId, int $userId, string $widgetId): void
    {
        $this->repo->save($domainId, $userId, $widgetId, [
            'hidden' => 0,
        ]);
    }

    /**
     * 위젯 이동 (up/down)
     *
     * 순서 기반 재배치: 플랫 리스트에서 위치를 교환한 뒤
     * SlotGridArranger와 동일한 로직으로 row/col을 재계산한다.
     * 같은 row에 있는 위젯끼리도 정상적으로 이동된다.
     */
    public function moveWidget(int $domainId, int $userId, string $widgetId, string $direction): bool
    {
        $layout = $this->repo->findByUser($domainId, $userId);

        // AUTO 상태면 먼저 현재 auto 배치를 DB화
        if (empty($layout) || !$this->hasPosition($layout)) {
            $layout = $this->autoToManual($domainId, $userId);
        }

        // row/col 기준 정렬 → 플랫 순서 리스트
        usort($layout, function ($a, $b) {
            $rowCmp = ($a['row'] ?? 0) <=> ($b['row'] ?? 0);
            return $rowCmp !== 0 ? $rowCmp : (($a['col'] ?? 0) <=> ($b['col'] ?? 0));
        });

        // 대상 위젯 인덱스 찾기
        $targetIndex = null;
        foreach ($layout as $i => $entry) {
            if ($entry['widget_id'] === $widgetId) {
                $targetIndex = $i;
                break;
            }
        }

        if ($targetIndex === null) {
            return false;
        }

        // swap 대상 결정
        $swapIndex = $direction === 'up' ? $targetIndex - 1 : $targetIndex + 1;
        if ($swapIndex < 0 || $swapIndex >= count($layout)) {
            return false;
        }

        // 배열 내 위치 교환 (row 값이 아닌 요소 자체를 swap)
        $temp = $layout[$targetIndex];
        $layout[$targetIndex] = $layout[$swapIndex];
        $layout[$swapIndex] = $temp;

        // row/col 재계산 (SlotGridArranger 로직과 동일)
        $layout = $this->recalculatePositions($layout);

        // DB에 저장
        $this->repo->saveAll($domainId, $userId, $layout);
        return true;
    }

    /**
     * 위젯 순서 재배치 (드래그앤드롭)
     *
     * 프론트에서 전달받은 widget_ids 순서대로 row/col을 재계산하여 저장.
     *
     * @param string[] $widgetIds 새 순서의 위젯 ID 배열
     */
    public function reorder(int $domainId, int $userId, array $widgetIds): void
    {
        // 중복 widget_id 제거 (선착순 유지)
        $widgetIds = array_values(array_unique($widgetIds));

        $layout = $this->repo->findByUser($domainId, $userId);

        // AUTO 상태면 MANUAL로 전환
        if (empty($layout) || !$this->hasPosition($layout)) {
            $layout = $this->autoToManual($domainId, $userId);
        }

        // widget_id → layout entry 맵 (visible / hidden 분리)
        $visibleMap = [];
        $hiddenEntries = [];
        foreach ($layout as $entry) {
            if (!empty($entry['hidden'])) {
                $hiddenEntries[] = $entry;
            } else {
                $visibleMap[$entry['widget_id']] = $entry;
            }
        }

        // 새 순서로 visible 위젯만 재구성
        $ordered = [];
        foreach ($widgetIds as $widgetId) {
            if (isset($visibleMap[$widgetId])) {
                $ordered[] = $visibleMap[$widgetId];
                unset($visibleMap[$widgetId]);
            }
        }
        // 프론트에서 누락된 visible 위젯은 끝에 추가
        foreach ($visibleMap as $entry) {
            $ordered[] = $entry;
        }

        // visible만 row/col 재계산 후, hidden은 원본 유지한 채 합침
        $ordered = $this->recalculatePositions($ordered);
        $ordered = array_merge($ordered, $hiddenEntries);
        $this->repo->saveAll($domainId, $userId, $ordered);
    }

    /**
     * 레이아웃 초기화 (AUTO 복귀)
     */
    public function resetLayout(int $domainId, int $userId): void
    {
        $this->repo->deleteByUser($domainId, $userId);
    }

    /**
     * AUTO 배치를 MANUAL로 전환 (DB에 현재 자동 배치 기록)
     */
    private function autoToManual(int $domainId, int $userId): array
    {
        $allWidgets = $this->registry->all();
        $arranger = new SlotGridArranger();
        $grid = $arranger->arrange(array_values($allWidgets));

        $entries = [];
        foreach ($grid as $rowIndex => $row) {
            foreach ($row as $item) {
                $entries[] = [
                    'widget_id' => $item['widget']->id(),
                    'row'       => $rowIndex,
                    'col'       => $item['col'],
                    'slot_size' => $item['slot'],
                    'hidden'    => 0,
                ];
            }
        }

        $this->repo->saveAll($domainId, $userId, $entries);
        return $entries;
    }

    /**
     * MANUAL 사용자에게 새로 추가된 위젯 자동 삽입 + DB 영속화
     *
     * 신규 위젯 발견 시 DB에 즉시 저장하여 매 요청마다 재발견하지 않도록 한다.
     */
    private function appendNewWidgets(array $layout, int $domainId, int $userId): array
    {
        $userWidgetIds = array_column($layout, 'widget_id');
        $registeredIds = $this->registry->ids();
        $newWidgets = array_diff($registeredIds, $userWidgetIds);

        if (empty($newWidgets)) {
            return $layout;
        }

        $lastRow = 0;
        foreach ($layout as $entry) {
            if (($entry['row'] ?? 0) > $lastRow) {
                $lastRow = $entry['row'];
            }
        }

        foreach ($newWidgets as $widgetId) {
            $widget = $this->registry->get($widgetId);
            if (!$widget) continue;

            $newEntry = [
                'widget_id' => $widgetId,
                'row'       => ++$lastRow,
                'col'       => 0,
                'slot_size' => $widget->defaultSlot(),
                'hidden'    => 0,
            ];

            $this->repo->save($domainId, $userId, $widgetId, $newEntry);
            $layout[] = $newEntry;
        }

        return $layout;
    }

    /**
     * 플랫 리스트 순서를 기준으로 row/col을 재계산
     *
     * 배열 순서가 곧 우선순위. slot 합계 4 기준으로 row를 나눈다.
     * slot_size가 없으면 Registry에서 위젯의 defaultSlot()을 조회한다.
     */
    private function recalculatePositions(array $layout): array
    {
        $maxSlots = SlotGridArranger::MAX_SLOTS;
        $currentRow = 0;
        $usedSlots = 0;

        foreach ($layout as &$entry) {
            $slot = $entry['slot_size'] ?? null;
            if ($slot === null) {
                $widget = $this->registry->get($entry['widget_id'] ?? '');
                $slot = $widget ? $widget->defaultSlot() : 2;
            }
            $slot = max(1, min($maxSlots, $slot));

            if ($usedSlots + $slot > $maxSlots) {
                $currentRow++;
                $usedSlots = 0;
            }

            $entry['row'] = $currentRow;
            $entry['col'] = $usedSlots;
            $entry['slot_size'] = $slot;

            $usedSlots += $slot;
            if ($usedSlots >= $maxSlots) {
                $currentRow++;
                $usedSlots = 0;
            }
        }
        unset($entry);

        return $layout;
    }

    /**
     * 레이아웃에 위치 정보(row/col)가 있는지 확인
     */
    private function hasPosition(array $layout): bool
    {
        foreach ($layout as $entry) {
            if (($entry['row'] ?? null) !== null || ($entry['col'] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }
}
