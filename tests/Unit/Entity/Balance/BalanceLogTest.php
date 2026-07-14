<?php
/**
 * tests/Unit/Entity/Balance/BalanceLogTest.php
 *
 * BalanceLog Entity 테스트
 */

namespace Tests\Unit\Entity\Balance;

use PHPUnit\Framework\TestCase;
use Mublo\Entity\Balance\BalanceLog;
use Mublo\Enum\Balance\BalanceSourceType;
use DateTimeImmutable;

class BalanceLogTest extends TestCase
{
    /**
     * 기본 데이터 배열 반환
     */
    private function getSampleData(): array
    {
        return [
            'log_id' => 1,
            'domain_id' => 1,
            'member_id' => 100,
            'amount' => 500,
            'balance_before' => 1000,
            'balance_after' => 1500,
            'source_type' => 'plugin',
            'source_name' => 'MemberPoint',
            'action' => 'article_write',
            'message' => '게시글 작성 [자유게시판] "오늘 날씨가..."',
            'reference_type' => 'article',
            'reference_id' => '123',
            'ip_address' => '192.168.1.1',
            'admin_id' => null,
            'memo' => null,
            'idempotency_key' => 'article_write_123_1706500000',
            'created_at' => '2025-01-29 10:00:00',
        ];
    }

    /**
     * fromArray로 Entity 생성 테스트
     */
    public function testFromArrayCreatesEntity(): void
    {
        $data = $this->getSampleData();
        $entity = BalanceLog::fromArray($data);

        $this->assertInstanceOf(BalanceLog::class, $entity);
    }

    /**
     * 기본 getter 메서드 테스트
     */
    public function testGettersReturnCorrectValues(): void
    {
        $data = $this->getSampleData();
        $entity = BalanceLog::fromArray($data);

        $this->assertSame(1, $entity->getLogId());
        $this->assertSame(1, $entity->getDomainId());
        $this->assertSame(100, $entity->getMemberId());
        $this->assertSame(500, $entity->getAmount());
        $this->assertSame(1000, $entity->getBalanceBefore());
        $this->assertSame(1500, $entity->getBalanceAfter());
        $this->assertSame(BalanceSourceType::PLUGIN, $entity->getSourceType());
        $this->assertSame('MemberPoint', $entity->getSourceName());
        $this->assertSame('article_write', $entity->getAction());
        $this->assertSame('게시글 작성 [자유게시판] "오늘 날씨가..."', $entity->getMessage());
        $this->assertSame('article', $entity->getReferenceType());
        $this->assertSame('123', $entity->getReferenceId());
        $this->assertSame('192.168.1.1', $entity->getIpAddress());
        $this->assertNull($entity->getAdminId());
        $this->assertNull($entity->getMemo());
        $this->assertSame('article_write_123_1706500000', $entity->getIdempotencyKey());
    }

    /**
     * toArray 메서드 테스트
     */
    public function testToArrayReturnsCorrectData(): void
    {
        $data = $this->getSampleData();
        $entity = BalanceLog::fromArray($data);
        $result = $entity->toArray();

        $this->assertSame(1, $result['log_id']);
        $this->assertSame(1, $result['domain_id']);
        $this->assertSame(100, $result['member_id']);
        $this->assertSame(500, $result['amount']);
        $this->assertSame(1000, $result['balance_before']);
        $this->assertSame(1500, $result['balance_after']);
        $this->assertSame('plugin', $result['source_type']);
        $this->assertSame('MemberPoint', $result['source_name']);
        $this->assertSame('article_write', $result['action']);
        $this->assertSame('article', $result['reference_type']);
        $this->assertSame('123', $result['reference_id']);
        $this->assertSame('article_write_123_1706500000', $result['idempotency_key']);
    }

    /**
     * NULL 허용 필드 테스트
     */
    public function testNullableFieldsAcceptNull(): void
    {
        $data = $this->getSampleData();
        $data['reference_type'] = null;
        $data['reference_id'] = null;
        $data['ip_address'] = null;
        $data['admin_id'] = null;
        $data['memo'] = null;
        $data['idempotency_key'] = null;

        $entity = BalanceLog::fromArray($data);

        $this->assertNull($entity->getReferenceType());
        $this->assertNull($entity->getReferenceId());
        $this->assertNull($entity->getIpAddress());
        $this->assertNull($entity->getAdminId());
        $this->assertNull($entity->getMemo());
        $this->assertNull($entity->getIdempotencyKey());
    }

    /**
     * 음수 금액(차감) 테스트
     */
    public function testNegativeAmountForDeduction(): void
    {
        $data = $this->getSampleData();
        $data['amount'] = -300;
        $data['balance_before'] = 1000;
        $data['balance_after'] = 700;

        $entity = BalanceLog::fromArray($data);

        $this->assertSame(-300, $entity->getAmount());
        $this->assertSame(700, $entity->getBalanceAfter());
        $this->assertTrue($entity->isDeduction());
        $this->assertFalse($entity->isAddition());
    }

    /**
     * 양수 금액(지급) 테스트
     */
    public function testPositiveAmountForAddition(): void
    {
        $data = $this->getSampleData();
        $entity = BalanceLog::fromArray($data);

        $this->assertTrue($entity->isAddition());
        $this->assertFalse($entity->isDeduction());
    }

    /**
     * 관리자 조정 여부 테스트
     */
    public function testIsAdminAdjustment(): void
    {
        $data = $this->getSampleData();
        $data['source_type'] = 'admin';
        $data['admin_id'] = 1;
        $data['memo'] = '이벤트 당첨 보상';

        $entity = BalanceLog::fromArray($data);

        $this->assertTrue($entity->isAdminAdjustment());
        $this->assertSame(1, $entity->getAdminId());
        $this->assertSame('이벤트 당첨 보상', $entity->getMemo());
    }

    /**
     * 시스템 조정 여부 테스트
     */
    public function testIsSystemAdjustment(): void
    {
        $data = $this->getSampleData();
        $data['source_type'] = 'system';
        $data['source_name'] = 'BalanceReconciler';
        $data['action'] = 'system_repair';

        $entity = BalanceLog::fromArray($data);

        $this->assertTrue($entity->isSystemAdjustment());
        $this->assertSame(BalanceSourceType::SYSTEM, $entity->getSourceType());
    }

    /**
     * DateTimeImmutable 변환 테스트
     */
    public function testCreatedAtReturnsDateTimeImmutable(): void
    {
        $data = $this->getSampleData();
        $entity = BalanceLog::fromArray($data);

        $this->assertInstanceOf(DateTimeImmutable::class, $entity->getCreatedAt());
        $this->assertSame('2025-01-29', $entity->getCreatedAt()->format('Y-m-d'));
    }

    /**
     * source_type ENUM 값 테스트
     */
    public function testValidSourceTypes(): void
    {
        $validTypes = ['core', 'plugin', 'package', 'admin', 'system'];

        foreach ($validTypes as $type) {
            $data = $this->getSampleData();
            $data['source_type'] = $type;
            $entity = BalanceLog::fromArray($data);

            $this->assertSame(BalanceSourceType::from($type), $entity->getSourceType());
        }
    }

    /**
     * 기본값 테스트
     */
    public function testDefaultValuesWhenMissing(): void
    {
        $minimalData = [
            'log_id' => 1,
            'domain_id' => 1,
            'member_id' => 100,
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
            'source_type' => 'core',
            'source_name' => 'Core',
            'action' => 'initial',
            'message' => '초기 지급',
        ];

        $entity = BalanceLog::fromArray($minimalData);

        $this->assertNull($entity->getReferenceType());
        $this->assertNull($entity->getReferenceId());
        $this->assertNull($entity->getIpAddress());
        $this->assertNull($entity->getAdminId());
        $this->assertNull($entity->getMemo());
        $this->assertNull($entity->getIdempotencyKey());
    }

    /**
     * 참조 정보 헬퍼 메서드 테스트
     */
    public function testHasReferenceMethod(): void
    {
        $data = $this->getSampleData();
        $entity = BalanceLog::fromArray($data);

        $this->assertTrue($entity->hasReference());

        $data['reference_type'] = null;
        $data['reference_id'] = null;
        $entity = BalanceLog::fromArray($data);

        $this->assertFalse($entity->hasReference());
    }

    /**
     * 잔액 변동 계산 메서드 테스트
     */
    public function testGetBalanceChange(): void
    {
        $data = $this->getSampleData();
        $entity = BalanceLog::fromArray($data);

        // amount와 동일해야 함 (balance_after - balance_before)
        $this->assertSame(500, $entity->getAmount());
        $this->assertSame(500, $entity->getBalanceAfter() - $entity->getBalanceBefore());
    }
}
