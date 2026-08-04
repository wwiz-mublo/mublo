<?php

namespace Tests\Unit\Entity\Block;

use Mublo\Entity\Block\BlockKit;
use PHPUnit\Framework\TestCase;

class BlockKitTest extends TestCase
{
    /**
     * 목록 조회는 kit_json 컬럼을 SELECT 하지 않는다. 그때 엔티티는 "본문을 안 읽었다"
     * 는 뜻으로 null 을 들고 있어야 하며, 호출자가 "빈 블록 킷" 으로 오해하면 안 된다.
     */
    public function testAbsentKitJsonColumnMeansNotLoaded(): void
    {
        $kit = BlockKit::fromArray(['kit_id' => 1, 'kit_name' => '히어로']);

        $this->assertFalse($kit->hasJson());
        $this->assertNull($kit->getKitJson());
        $this->assertNull($kit->decodeJson());
    }

    public function testPresentKitJsonIsDecoded(): void
    {
        $kit = BlockKit::fromArray(['kit_json' => '{"format":"mublo-starter-kit","rows":[]}']);

        $this->assertTrue($kit->hasJson());
        $this->assertSame('mublo-starter-kit', $kit->decodeJson()['format']);
    }

    /**
     * 보관 시점에 검증된 JSON 이지만 DB 는 손으로 고칠 수 있다.
     * 깨진 본문이 치명적 오류로 번지지 않고 null 로 떨어져야 한다.
     */
    public function testBrokenKitJsonDecodesToNullInsteadOfThrowing(): void
    {
        $kit = BlockKit::fromArray(['kit_json' => '{"format": broken']);

        $this->assertTrue($kit->hasJson(), '문자열은 실려 있다');
        $this->assertNull($kit->decodeJson());
    }

    /** JSON 스칼라("3")는 배열이 아니다. 배열을 기대하는 호출자를 지킨다. */
    public function testScalarJsonDecodesToNull(): void
    {
        $this->assertNull(BlockKit::fromArray(['kit_json' => '3'])->decodeJson());
    }

    /** DB 의 NULL 과 빈 문자열은 둘 다 "값 없음" 이다. */
    public function testNullableColumnsCollapseEmptyStringToNull(): void
    {
        $kit = BlockKit::fromArray([
            'target_position' => '',
            'target_menu_code' => null,
            'screenshot_path' => '',
        ]);

        $this->assertNull($kit->getTargetPosition());
        $this->assertNull($kit->getTargetMenuCode());
        $this->assertNull($kit->getScreenshotPath());
    }

    /** DB 는 TINYINT(1) 을 문자열 "0"/"1" 로 돌려줄 수 있다. */
    public function testTinyIntFlagsBecomeBooleans(): void
    {
        $on = BlockKit::fromArray(['contains_script' => '1', 'is_deleted' => '1']);
        $off = BlockKit::fromArray(['contains_script' => '0', 'is_deleted' => '0']);

        $this->assertTrue($on->containsScript());
        $this->assertTrue($on->isDeleted());
        $this->assertFalse($off->containsScript());
        $this->assertFalse($off->isDeleted());
    }

    public function testToArrayRoundTripsThroughFromArray(): void
    {
        $source = [
            'kit_id' => 9,
            'domain_id' => 2,
            'kit_name' => '메인',
            'kit_description' => '설명',
            'kit_version' => '2.1.0',
            'kit_author' => '작성자',
            'kit_author_url' => 'https://example.com',
            'target_kind' => 'page',
            'target_position' => null,
            'target_menu_code' => null,
            'target_page_code' => 'about',
            'export_mode' => 'clone',
            'contains_script' => true,
            'row_count' => 3,
            'column_count' => 7,
            'kit_json' => '{"a":1}',
            'screenshot_path' => '/storage/kit/9.webp',
            'source_type' => 'export',
            'is_deleted' => false,
            'created_at' => '2026-07-10 12:00:00',
            'updated_at' => '2026-07-10 12:30:00',
        ];

        $this->assertSame($source, BlockKit::fromArray($source)->toArray());
    }

    /** 깨진 날짜 문자열이 예외로 번지지 않는다. */
    public function testUnparseableDateBecomesNull(): void
    {
        $kit = BlockKit::fromArray(['created_at' => '0000-00-00 00:00:00']);

        $this->assertNull($kit->getCreatedAt());
    }
}
