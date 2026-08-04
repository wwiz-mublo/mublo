<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\PriceCompare\Writer;

/**
 * RSS 2.0 + g: 네임스페이스 피드 라이터
 *
 * 구글 계열이 받는 형식이다. 채널이 선언한 컬럼명이 그대로 요소명이 된다
 * (columns() 의 'image_link' → <g:image_link>). 그래서 채널은 TSV 든 RSS 든
 * 같은 모양(컬럼명 + 값)으로 규격을 선언하고, 형식 차이는 이 라이터가 흡수한다.
 *
 * 빈 값은 요소를 아예 만들지 않는다. 빈 요소를 보내면 "값이 있는데 비었다"로
 * 읽혀 항목 단위 거부가 나는 쪽이 있고, 없는 값은 없는 대로 두는 것이 맞다.
 */
final class RssWriter
{
    /**
     * @param list<string>       $columns
     * @param list<list<string>> $rows
     */
    public function render(array $columns, array $rows, string $title, string $link): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= "  <channel>\n";
        $xml .= '    <title>' . $this->escape($title) . "</title>\n";
        $xml .= '    <link>' . $this->escape($link) . "</link>\n";
        $xml .= '    <description>' . $this->escape($title) . "</description>\n";

        foreach ($rows as $row) {
            $xml .= $this->item($columns, $row);
        }

        $xml .= "  </channel>\n";

        return $xml . '</rss>' . "\n";
    }

    /**
     * @param list<string> $columns
     * @param list<string> $values
     */
    private function item(array $columns, array $values): string
    {
        $xml = "    <item>\n";

        foreach ($columns as $index => $column) {
            $value = (string) ($values[$index] ?? '');
            if (trim($value) === '') {
                continue;
            }

            $name = $this->elementName($column);
            if ($name === '') {
                continue;
            }

            $xml .= '      <g:' . $name . '>' . $this->escape($value) . '</g:' . $name . ">\n";
        }

        return $xml . "    </item>\n";
    }

    /** 요소명으로 쓸 수 없는 컬럼명은 버린다(XML 을 깨뜨리지 않기 위한 방어) */
    private function elementName(string $column): string
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/', $column) === 1 ? $column : '';
    }

    private function escape(string $value): string
    {
        // 제어문자는 XML 1.0 에서 허용되지 않아 파서가 파일 전체를 버린다.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
