<?php

namespace Tests\Unit\Entity\Block;

use Mublo\Entity\Block\BlockColumn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * title_config 는 관리자 폼이 만든 JSON 이라 타입이 보장되지 않는다.
 *
 * 글꼴 크기는 숫자 입력이라 26 처럼 정수로 저장된 설치본이 있고, 문자열로 저장된
 * 설치본도 있다. declare(strict_types=1) 아래에서 ?string 게터가 정수를 그대로
 * 돌려주면 TypeError 가 나고, 렌더러가 그 예외를 받아 칸이 통째로 비어 버린다.
 *
 * 실제로 그렇게 됐다 — 메인 페이지의 html·device·faq 블록이 전부 빈 채로 나왔고
 * 로그에만 "Return value must be of type ?string, int returned" 가 쌓였다.
 */
class BlockColumnTitleConfigTest extends TestCase
{
    /** @param array<string, mixed> $titleConfig */
    private function column(array $titleConfig): BlockColumn
    {
        return BlockColumn::fromArray([
            'column_id' => 1,
            'row_id' => 1,
            'domain_id' => 1,
            'title_config' => json_encode($titleConfig),
        ]);
    }

    /**
     * DB 에 실제로 들어 있던 형태 — 크기가 전부 정수다.
     */
    public function testNumericSizesAreReturnedAsStrings(): void
    {
        $column = $this->column([
            'size_pc' => 26,
            'size_mo' => 17,
            'copytext_size_pc' => 14,
            'copytext_size_mo' => 12,
        ]);

        $this->assertSame('26', $column->getTitlePcSize());
        $this->assertSame('17', $column->getTitleMobileSize());
        $this->assertSame('14', $column->getCopytextPcSize());
        $this->assertSame('12', $column->getCopytextMobileSize());
    }

    /**
     * 문자열로 저장된 설치본도 그대로 동작해야 한다.
     */
    public function testStringSizesAreUnchanged(): void
    {
        $column = $this->column(['size_pc' => '26', 'size_mo' => '17']);

        $this->assertSame('26', $column->getTitlePcSize());
        $this->assertSame('17', $column->getTitleMobileSize());
    }

    /**
     * 값이 없거나 빈 문자열이면 null — 스킨이 "지정 안 함" 으로 분기한다.
     */
    #[DataProvider('emptyValueProvider')]
    public function testEmptyValuesBecomeNull(mixed $value): void
    {
        $this->assertNull($this->column(['size_pc' => $value])->getTitlePcSize());
    }

    public static function emptyValueProvider(): array
    {
        return [
            'null'      => [null],
            '빈 문자열' => [''],
        ];
    }

    public function testMissingKeyIsNull(): void
    {
        $this->assertNull($this->column([])->getTitlePcSize());
        $this->assertNull($this->column([])->getTitleText());
    }

    /**
     * 배열·객체는 문자열로 만들 수 없다 — 값이 없는 것으로 본다.
     * (문자열 변환을 시도하면 "Array to string conversion" 으로 또 터진다)
     */
    public function testNonScalarBecomesNull(): void
    {
        $this->assertNull($this->column(['size_pc' => ['26']])->getTitlePcSize());
    }

    /**
     * 숫자로 저장될 수 있는 나머지 문자열 게터도 같은 보호를 받아야 한다.
     */
    public function testAllStringGettersToleratePlainNumbers(): void
    {
        $column = $this->column([
            'text' => 2026,
            'color' => 0,
            'pc_image' => 1,
            'mo_image' => 2,
            'more_url' => 3,
            'more_text' => 4,
            'copytext' => 5,
            'copytext_color' => 6,
            'copytext_position' => 7,
        ]);

        $this->assertSame('2026', $column->getTitleText());
        $this->assertSame('0', $column->getTitleColor());
        $this->assertSame('1', $column->getTitlePcImage());
        $this->assertSame('2', $column->getTitleMobileImage());
        $this->assertSame('3', $column->getMoreUrl());
        $this->assertSame('4', $column->getMoreText());
        $this->assertSame('5', $column->getCopytext());
        $this->assertSame('6', $column->getCopytextColor());
        $this->assertSame('7', $column->getCopytextPosition());
    }

    /**
     * position 은 기본값이 있다 — 없으면 'left'.
     */
    public function testPositionKeepsItsDefault(): void
    {
        $this->assertSame('left', $this->column([])->getTitlePosition());
        $this->assertSame('center', $this->column(['position' => 'center'])->getTitlePosition());
    }

    /**
     * 렌더러가 실제로 쓰는 경로 — toTitleView() 가 예외 없이 완성돼야 한다.
     */
    public function testTitleViewIsBuiltFromNumericConfig(): void
    {
        $column = $this->column([
            'show' => true,
            'text' => '추천 상품',
            'size_pc' => 26,
            'size_mo' => 17,
        ]);

        $view = $column->toTitleView();

        $this->assertIsArray($view);
    }
}
