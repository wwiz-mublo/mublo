<?php
declare(strict_types=1);

namespace Tests\VisitorStats\Unit;

use Mublo\Contract\Tracking\ConversionRecordedEvent;
use Mublo\Contract\Tracking\ConversionSourceTypes;
use Mublo\Plugin\VisitorStats\Repository\ConversionEventRepository;
use Mublo\Plugin\VisitorStats\Subscriber\ConversionRecorderSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * ConversionRecordedEvent 수신 검증.
 *
 * 이 계약은 발행자(렌탈 주문·상담)만 있고 구독자가 없어 전환이 통째로 버려지던
 * 이력이 있다. 배선과 정규화 규칙을 테스트로 고정한다.
 */
class ConversionRecorderSubscriberTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $recorded = [];

    private function subscriber(): ConversionRecorderSubscriber
    {
        $repo = $this->createMock(ConversionEventRepository::class);
        $repo->method('record')->willReturnCallback(function (array $row): void {
            $this->recorded[] = $row;
        });

        return new ConversionRecorderSubscriber($repo);
    }

    public function testSubscribesToTheContractEvent(): void
    {
        $this->assertSame(
            [ConversionRecordedEvent::class => 'onConversionRecorded'],
            ConversionRecorderSubscriber::getSubscribedEvents()
        );
    }

    public function testRecordsConversionWithGivenValues(): void
    {
        $this->subscriber()->onConversionRecorded(new ConversionRecordedEvent(
            domainId: 3,
            sourceType: ConversionSourceTypes::MEMBER_SIGNUP,
            sourceId: '2026-0001',
            campaignKey: 'summer2026',
            status: 'success',
            memberId: 42,
            valueAmount: 129000.0,
            currency: 'KRW',
            sourceLabel: null,
            occurredAt: '2026-08-01 10:20:30',
        ));

        $row = $this->recorded[0];
        $this->assertSame(3, $row['domain_id']);
        $this->assertSame('member_signup', $row['source_type']);
        $this->assertSame('2026-0001', $row['source_id']);
        $this->assertSame('summer2026', $row['campaign_key']);
        $this->assertSame(42, $row['member_id']);
        $this->assertSame(129000.0, $row['value_amount']);
        $this->assertSame('2026-08-01 10:20:30', $row['occurred_at']);
        // 라벨을 안 실어 보내면 코어 상수의 한글 라벨로 채운다.
        $this->assertSame('회원가입', $row['source_label']);
    }

    /**
     * 소스를 특정할 수 없으면 멱등키가 성립하지 않는다. 익명 행을 쌓지 않는다.
     */
    public function testDropsConversionsWithoutIdentity(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onConversionRecorded(new ConversionRecordedEvent(
            domainId: 1, sourceType: '', sourceId: 'X-1', campaignKey: null
        ));
        $subscriber->onConversionRecorded(new ConversionRecordedEvent(
            domainId: 1, sourceType: 'store_order', sourceId: '  ', campaignKey: null
        ));
        $subscriber->onConversionRecorded(new ConversionRecordedEvent(
            domainId: 0, sourceType: 'store_order', sourceId: 'X-1', campaignKey: null
        ));

        $this->assertSame([], $this->recorded);
    }

    /**
     * 빈 캠페인 키는 빈 문자열이 아니라 NULL 로 남긴다 — 집계에서 ''와 NULL 이
     * 다른 그룹으로 갈라지지 않게 한다.
     */
    public function testBlankCampaignKeyBecomesNull(): void
    {
        $this->subscriber()->onConversionRecorded(new ConversionRecordedEvent(
            domainId: 1, sourceType: 'member_signup', sourceId: '7', campaignKey: '   '
        ));

        $this->assertNull($this->recorded[0]['campaign_key']);
    }

    /**
     * 시각을 안 실어 보냈거나 형식이 깨져도 버리지 않는다 — 수신 시각으로 갈음한다.
     */
    public function testFallsBackToReceiptTimeWhenOccurredAtIsUnusable(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onConversionRecorded(new ConversionRecordedEvent(
            domainId: 1, sourceType: 'member_signup', sourceId: '8', campaignKey: null, occurredAt: null
        ));
        $subscriber->onConversionRecorded(new ConversionRecordedEvent(
            domainId: 1, sourceType: 'member_signup', sourceId: '9', campaignKey: null, occurredAt: 'not-a-date'
        ));

        foreach ($this->recorded as $row) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['occurred_at']);
        }
    }

    /**
     * 통계 실패가 주문·상담 트랜잭션을 깨뜨리면 안 된다.
     */
    public function testStorageFailureIsSwallowed(): void
    {
        $repo = $this->createMock(ConversionEventRepository::class);
        $repo->method('record')->willThrowException(new \RuntimeException('db down'));

        (new ConversionRecorderSubscriber($repo))->onConversionRecorded(new ConversionRecordedEvent(
            domainId: 1, sourceType: 'store_order', sourceId: 'S-1', campaignKey: null
        ));

        $this->addToAssertionCount(1);
    }
}
