<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Extension;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Contract\Site\SiteMutationReceipt;
use Mublo\Contract\Site\SiteMutationRecorderInterface;
use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Extension\ExtensionCompatibility;
use Mublo\Service\Extension\ExtensionService;
use PHPUnit\Framework\TestCase;

/** 확장 설정 저장도 사이트 편집기 guard에 드러나도록 같은 트랜잭션에서 `config:extension` revision을 올린다. */
final class ExtensionConfigRevisionTest extends TestCase
{
    private bool $inTransaction = false;

    public function testChangedConfigIsRecordedInTheWriteTransactionAndUnchangedConfigIsNot(): void
    {
        $recorded = [];
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->method('record')->willReturnCallback(function (SiteMutationContext $context) use (&$recorded): SiteMutationReceipt {
            self::assertTrue($this->inTransaction, '저장과 같은 트랜잭션에서 기록한다');
            $recorded[] = [$context->source, array_column($context->resources(), 'resourceId')];
            return new SiteMutationReceipt(1, 7, 1, $context->source, null, []);
        });
        $repository = $this->createMock(DomainRepository::class);
        $repository->method('updateExtensionConfig')->willReturnCallback(function (): bool {
            self::assertTrue($this->inTransaction);
            return true;
        });
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(function (callable $work): mixed {
            $this->inTransaction = true;
            try {
                return $work();
            } finally {
                $this->inTransaction = false;
            }
        });
        $service = new ExtensionService(
            $repository,
            $this->createStub(DomainCache::class),
            $db,
            $this->createStub(ExtensionCompatibility::class),
            null,
            $recorder
        );
        $write = new \ReflectionMethod($service, 'writeExtensionConfig');

        $this->assertTrue($write->invoke($service, 7, ['plugins' => []], ['plugins' => ['Faq']], SiteMutationContext::SOURCE_ADMIN));
        $this->assertTrue($write->invoke($service, 7, ['plugins' => ['Faq']], ['plugins' => ['Faq']], SiteMutationContext::SOURCE_AUTOMATION));

        $this->assertSame([[SiteMutationContext::SOURCE_ADMIN, ['config:extension']]], $recorded);
    }
}
