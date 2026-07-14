<?php

namespace Mublo\Core\Report\Document\Section;

/**
 * 키-값 쌍 섹션
 *
 * 리포트 상단의 요약 정보 (필터 조건, 기간, 총계 등)를 표현.
 *
 * 사용 예:
 *   new KeyValueSection('조회 조건', [
 *       ['label' => '기간', 'value' => '2026-01-01 ~ 2026-01-31'],
 *       ['label' => '총 건수', 'value' => '1,234건'],
 *   ])
 */
class KeyValueSection implements SectionInterface
{
    private string $title;

    /** @var array<int, array{label: string, value: string}> */
    private array $items;

    /**
     * @param string $title 섹션 제목 (빈 문자열이면 제목 없음)
     * @param array<int, array{label: string, value: string}> $items
     */
    public function __construct(string $title, array $items)
    {
        $this->title = $title;
        $this->items = $items;
    }

    public function type(): string
    {
        return 'key_value';
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function items(): array
    {
        return $this->items;
    }
}
