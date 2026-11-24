<?php
declare(strict_types=1);

namespace Tests\VisitorStats\Unit;

use Mublo\Contract\Tracking\CampaignKeyLabelsEvent;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignKeyRepository;
use Mublo\Plugin\VisitorStats\Subscriber\CampaignKeyLabelSubscriber;
use PHPUnit\Framework\TestCase;

class CampaignKeyLabelSubscriberTest extends TestCase
{
    public function testSubscribesToTheContractEvent(): void
    {
        $this->assertSame(
            [CampaignKeyLabelsEvent::class => 'onCampaignKeyLabels'],
            CampaignKeyLabelSubscriber::getSubscribedEvents()
        );
    }

    public function testLabelsRegisteredKeysPerDomain(): void
    {
        $repo = $this->createMock(VisitorCampaignKeyRepository::class);
        $repo->method('findByKeys')->willReturnCallback(fn(int $domainId, array $keys) => match ($domainId) {
            3 => [
                ['campaign_key' => 'nv01', 'group_name' => '네이버', 'memo' => '가을 행사'],
                ['campaign_key' => 'blank', 'group_name' => '', 'memo' => null],
            ],
            default => [],
        });

        $event = new CampaignKeyLabelsEvent([3 => ['nv01', 'blank', 'unknown'], 4 => ['nv01'], 0 => ['nv01']]);
        (new CampaignKeyLabelSubscriber($repo))->onCampaignKeyLabels($event);

        $this->assertSame('네이버 · 가을 행사', $event->getLabel(3, 'nv01'));
        $this->assertSame('', $event->getLabel(3, 'blank'));
        $this->assertSame('', $event->getLabel(3, 'unknown'));
        $this->assertSame('', $event->getLabel(4, 'nv01'));
        $this->assertSame([3 => ['nv01' => '네이버 · 가을 행사']], $event->getLabels());
    }

    public function testBuildLabelTrimsAndShortensMemo(): void
    {
        $this->assertSame('그룹', CampaignKeyLabelSubscriber::buildLabel(' 그룹 ', '  '));
        $this->assertSame('메모', CampaignKeyLabelSubscriber::buildLabel('', '메모'));
        $this->assertSame('', CampaignKeyLabelSubscriber::buildLabel('', ''));
        $this->assertSame('G · ' . str_repeat('가', 40) . '…', CampaignKeyLabelSubscriber::buildLabel('G', str_repeat('가', 41)));
    }

    public function testRepositoryFailureSkipsOnlyThatDomain(): void
    {
        $repo = $this->createMock(VisitorCampaignKeyRepository::class);
        $repo->method('findByKeys')->willReturnCallback(function (int $domainId): array {
            if ($domainId === 1) {
                throw new \RuntimeException('no table');
            }
            return [['campaign_key' => 'k', 'group_name' => 'G', 'memo' => '']];
        });

        $event = new CampaignKeyLabelsEvent([1 => ['k'], 2 => ['k']]);
        (new CampaignKeyLabelSubscriber($repo))->onCampaignKeyLabels($event);

        $this->assertSame('', $event->getLabel(1, 'k'));
        $this->assertSame('G', $event->getLabel(2, 'k'));
    }
}
