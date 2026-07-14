<?php

namespace Mublo\Core\Dashboard;

/**
 * 패키지 제거 시 레이아웃 자동 정리
 *
 * 렌더링 전 항상 실행하여 안전성 보장.
 * Registry에 없는 widget_id 제거 + MANUAL 모드 row 번호 정규화.
 */
class LayoutSanitizer
{
    /**
     * 레이아웃 정리
     *
     * @param array $layout DB에서 로드한 레이아웃 배열
     * @param string[] $registeredIds Registry에 등록된 위젯 ID 목록
     * @return array 정리된 레이아웃
     */
    public function sanitize(array $layout, array $registeredIds): array
    {
        $cleaned = [];
        foreach ($layout as $entry) {
            if (!in_array($entry['widget_id'], $registeredIds, true)) {
                continue;
            }
            $cleaned[] = $entry;
        }

        return $this->normalizeRowOrder($cleaned);
    }

    /**
     * row 번호를 0부터 연속으로 재정렬
     *
     * 예: row 1,5,9 → 0,1,2 (빈 번호 제거, 순서 유지)
     */
    private function normalizeRowOrder(array $layout): array
    {
        if (empty($layout)) {
            return $layout;
        }

        // AUTO+Override (row가 모두 NULL)인 경우 정규화 불필요
        $hasPosition = false;
        foreach ($layout as $entry) {
            if ($entry['row'] !== null) {
                $hasPosition = true;
                break;
            }
        }
        if (!$hasPosition) {
            return $layout;
        }

        // row 기준 정렬 후 연속 번호 부여
        usort($layout, fn($a, $b) => ($a['row'] ?? 0) <=> ($b['row'] ?? 0));

        $rowMap = [];
        $newRow = 0;
        foreach ($layout as &$entry) {
            $oldRow = $entry['row'];
            if ($oldRow === null) {
                continue;
            }
            if (!isset($rowMap[$oldRow])) {
                $rowMap[$oldRow] = $newRow++;
            }
            $entry['row'] = $rowMap[$oldRow];
        }

        return $layout;
    }
}
