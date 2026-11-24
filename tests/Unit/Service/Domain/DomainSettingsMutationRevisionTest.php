<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Domain;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Contract\Site\SiteMutationReceipt;
use Mublo\Contract\Site\SiteMutationRecorderInterface;
use Mublo\Entity\Domain\Domain;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Service\Domain\DomainSettingsService;
use PHPUnit\Framework\TestCase;

final class DomainSettingsMutationRevisionTest extends TestCase
{
    /** @param list<callable> $afterCommit */
    private function db(array &$afterCommit): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(static fn (callable $callback) => $callback());
        $db->method('afterCommit')->willReturnCallback(
            static function (callable $callback) use (&$afterCommit): void {
                $afterCommit[] = $callback;
            }
        );
        return $db;
    }

    private function domain(): Domain
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getDomain')->willReturn('shop.example');
        $domain->method('getSiteConfig')->willReturn(['site_title' => '이전 제목']);
        $domain->method('getCompanyConfig')->willReturn([]);
        $domain->method('getSeoConfig')->willReturn([]);
        $domain->method('getThemeConfig')->willReturn([]);
        return $domain;
    }

    private function receipt(string $source = SiteMutationContext::SOURCE_ADMIN): SiteMutationReceipt
    {
        return new SiteMutationReceipt(1, 1, 1, $source, null, []);
    }

    public function testStandaloneSiteUpdateRecordsConfigResourceAndInvalidatesAfterCommit(): void
    {
        $afterCommit = [];
        $repository = $this->createMock(DomainRepository::class);
        $repository->method('getDb')->willReturn($this->db($afterCommit));
        $repository->method('find')->with(1)->willReturn($this->domain());
        $repository->expects($this->once())->method('updateSiteConfig')->with(1, [
            'site_title' => '새 제목',
            'language' => 'ko',
        ])->willReturn(true);
        $resolver = $this->createMock(DomainResolver::class);
        $resolver->expects($this->once())->method('invalidate')->with('shop.example');
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->once())->method('record')->with(
            $this->callback(static fn (SiteMutationContext $context): bool =>
                $context->source === SiteMutationContext::SOURCE_ADMIN
                && array_column($context->resources(), 'resourceId') === ['config:site'])
        )->willReturn($this->receipt());

        $service = new DomainSettingsService($repository, $resolver, null, $recorder);
        $result = $service->updateSiteConfig(1, ['site_title' => '새 제목', 'language' => 'ko']);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertCount(1, $afterCommit);
        foreach ($afterCommit as $callback) {
            $callback();
        }
    }

    public function testSharedContextCollectsSeoWithoutFlushing(): void
    {
        $afterCommit = [];
        $repository = $this->createMock(DomainRepository::class);
        $repository->method('getDb')->willReturn($this->db($afterCommit));
        $repository->method('find')->with(1)->willReturn($this->domain());
        $repository->method('updateSeoConfig')->willReturn(true);
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->never())->method('record');
        $context = SiteMutationContext::create(1, SiteMutationContext::SOURCE_EXTENSION, 'change-set-7');

        $service = new DomainSettingsService(
            $repository,
            $this->createMock(DomainResolver::class),
            null,
            $recorder
        );
        $result = $service->updateSeoConfig(1, ['meta_title' => '검색 제목'], $context);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame(['config:seo'], array_column($context->resources(), 'resourceId'));
    }

    public function testIntegratedSaveRecordsOnlySubmittedValidSections(): void
    {
        $afterCommit = [];
        $repository = $this->createMock(DomainRepository::class);
        $repository->method('getDb')->willReturn($this->db($afterCommit));
        $repository->method('find')->with(1)->willReturn($this->domain());
        $repository->method('updateSiteConfig')->willReturn(true);
        $repository->method('updateSeoConfig')->willReturn(true);
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->once())->method('record')->with(
            $this->callback(static fn (SiteMutationContext $context): bool =>
                array_column($context->resources(), 'resourceId') === ['config:seo', 'config:site'])
        )->willReturn($this->receipt());

        $service = new DomainSettingsService(
            $repository,
            $this->createMock(DomainResolver::class),
            null,
            $recorder
        );
        $result = $service->saveSettings(1, [
            'site' => ['site_title' => '새 상점', 'admin_email' => 'admin@example.com'],
            'seo' => ['meta_title' => '검색 제목'],
        ]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
    }

    public function testNoOpUpdateDoesNotAdvanceRevisionOrInvalidateCache(): void
    {
        $afterCommit = [];
        $repository = $this->createMock(DomainRepository::class);
        $repository->method('getDb')->willReturn($this->db($afterCommit));
        $repository->method('find')->with(1)->willReturn($this->domain());
        $repository->expects($this->never())->method('updateSiteConfig');
        $resolver = $this->createMock(DomainResolver::class);
        $resolver->expects($this->never())->method('invalidate');
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->never())->method('record');

        $service = new DomainSettingsService($repository, $resolver, null, $recorder);
        $result = $service->updateSiteConfig(1, ['site_title' => '이전 제목']);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame([], $afterCommit);
    }
}
