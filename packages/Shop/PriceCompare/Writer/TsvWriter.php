<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\PriceCompare\Writer;

/**
 * 탭 구분 텍스트 피드 라이터
 *
 * 국내 비교사 피드(EP)는 대부분 "첫 줄 컬럼명, 이후 한 줄 한 상품, 탭 구분" 형태다.
 * 그래서 라이터는 채널마다 만들지 않고 형식 단위로 하나만 둔다.
 *
 * 값에 섞인 탭·개행을 공백으로 바꾸는 것이 이 클래스의 실제 일이다. 상품명이나
 * 태그에 개행이 하나 들어가면 그 줄부터 컬럼이 밀려 파일 전체가 깨지는데,
 * 비교사는 깨진 줄만 버리는 게 아니라 피드를 통째로 거부하기도 한다.
 */
final class TsvWriter
{
    /**
     * @param list<string>       $columns
     * @param list<list<string>> $rows
     */
    public function render(array $columns, array $rows): string
    {
        $lines = [$this->line($columns)];

        foreach ($rows as $row) {
            $lines[] = $this->line($row);
        }

        return implode("\n", $lines) . "\n";
    }

    /** 컬럼명 줄만 (상품이 0건이어도 형식은 유지해야 한다) */
    public function header(array $columns): string
    {
        return $this->line($columns) . "\n";
    }

    /** @param list<string> $values */
    public function line(array $values): string
    {
        return implode("\t", array_map([$this, 'clean'], $values));
    }

    private function clean(mixed $value): string
    {
        $value = (string) $value;

        // 탭·CR·LF 는 구분자와 줄 경계를 깨뜨린다. 제거가 아니라 공백으로 바꿔
        // 단어가 붙어버리는 것을 막는다.
        $value = str_replace(["\t", "\r\n", "\r", "\n"], ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
