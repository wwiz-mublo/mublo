<?php

namespace Mublo\Core\Report\Document\Section;

/**
 * 텍스트 블록 섹션
 *
 * 자유 텍스트 (설명, 주의사항, 안내문 등)를 리포트에 포함.
 *
 * 사용 예:
 *   new TextSection('이 보고서는 정산 완료 건만 포함합니다.', '안내')
 */
class TextSection implements SectionInterface
{
    private string $content;
    private string $title;

    /**
     * @param string $content 텍스트 내용
     * @param string $title 섹션 제목 (빈 문자열이면 제목 없음)
     */
    public function __construct(string $content, string $title = '')
    {
        $this->content = $content;
        $this->title = $title;
    }

    public function type(): string
    {
        return 'text';
    }

    public function content(): string
    {
        return $this->content;
    }

    public function title(): string
    {
        return $this->title;
    }
}
