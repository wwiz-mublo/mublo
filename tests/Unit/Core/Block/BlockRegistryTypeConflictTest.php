<?php

namespace Tests\Unit\Core\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Enum\Block\BlockContentType;
use PHPUnit\Framework\TestCase;

/**
 * 머블로는 확장 제작자에게 타입 코드 네이밍을 강제하지 않는다.
 * 그래서 코어의 책임은 충돌을 막는 것이 아니라, 충돌이 확장 전체의 실패로
 * 번지지 않게 하는 것이다.
 */
class BlockRegistryTypeConflictTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BlockRegistry::reset();
    }

    protected function tearDown(): void
    {
        BlockRegistry::reset();
        parent::tearDown();
    }

    public function testLateClaimOnTakenCodeIsSkippedInsteadOfThrowing(): void
    {
        $this->register('review', 'Shop 구매후기');
        $this->register('review', 'Mshop 구매후기');

        $registered = BlockRegistry::getContentType('review');

        // 선착순. 늦게 온 쪽은 예외 없이 빠진다.
        $this->assertSame('Shop 구매후기', $registered['title']);
    }

    public function testSkippedClaimDoesNotStopTheRestOfTheExtension(): void
    {
        $this->register('review', 'Shop 구매후기');

        $this->register('review', 'Mshop 구매후기');
        $this->register('mshop-consult', 'Mshop 상담신청');

        // 충돌한 타입 하나만 잃고 나머지 등록은 살아남아야 한다.
        $this->assertTrue(BlockRegistry::hasContentType('mshop-consult'));
    }

    public function testSkippedClaimIsRecordedWithBothSides(): void
    {
        $this->register('review', 'Shop 구매후기');
        $this->register('review', 'Mshop 구매후기');

        $conflicts = BlockRegistry::getTypeConflicts();

        $this->assertCount(1, $conflicts);
        $this->assertSame('review', $conflicts[0]['type']);
        $this->assertSame('Mshop 구매후기', $conflicts[0]['claimantTitle']);
        $this->assertSame('Shop 구매후기', $conflicts[0]['holderTitle']);
    }

    public function testExplicitOverwriteStillWins(): void
    {
        $this->register('review', 'Shop 구매후기');
        $this->register('review', '커스텀 구매후기', ['allowOverwrite' => true]);

        $this->assertSame('커스텀 구매후기', BlockRegistry::getContentType('review')['title']);
        $this->assertSame([], BlockRegistry::getTypeConflicts());
    }

    /**
     * 확장이 코어 코드를 선점하면 등록은 조용히 통과하고, 나중 첫 읽기에서
     * 코어 지연 초기화가 터졌다. 그 뒤로는 모든 레지스트리 호출이 계속 실패했다.
     */
    public function testExtensionCannotPreemptCoreTypeAndPoisonTheRegistry(): void
    {
        $this->register(BlockContentType::IMAGE->value, '가짜 이미지');

        // 예전엔 여기서 InvalidArgumentException 이 터졌다.
        $types = BlockRegistry::getContentTypes();

        $this->assertArrayHasKey(BlockContentType::IMAGE->value, $types);
        $this->assertSame('이미지', $types[BlockContentType::IMAGE->value]['title']);
        // 후속 호출도 정상이어야 한다(예전엔 무관한 'html' 을 지목하며 계속 터졌다).
        $this->assertTrue(BlockRegistry::hasContentType(BlockContentType::MENU->value));
    }

    public function testCoreTypesSurviveEvenWhenAnExtensionRegistersFirst(): void
    {
        $this->register('mshop-consult', 'Mshop 상담신청');

        foreach (BlockContentType::cases() as $core) {
            $this->assertTrue(
                BlockRegistry::hasContentType($core->value),
                "코어 타입 {$core->value} 이(가) 사라졌다"
            );
        }
    }

    private function register(string $type, string $title, array $options = []): void
    {
        BlockRegistry::registerContentType(
            type: $type,
            kind: 'PACKAGE',
            title: $title,
            rendererClass: 'RendererIsNotValidatedInThisTest',
            options: $options + ['skipValidation' => true],
        );
    }
}
