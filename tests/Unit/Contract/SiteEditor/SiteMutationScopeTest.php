<?php
declare(strict_types=1);

namespace Tests\Unit\Contract\SiteEditor;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Contract\Site\SiteMutationScope;
use PHPUnit\Framework\TestCase;

/** 서비스가 스스로 만드는 사이트 변경 문맥의 주체는 지금 실행 중인 범위를 따른다. 범위가 없으면 관리자 변경이다. */
final class SiteMutationScopeTest extends TestCase
{
    public function testContextsFollowTheInnermostScopeAndFallBackToAdmin(): void
    {
        self::assertSame(SiteMutationContext::SOURCE_ADMIN, SiteMutationScope::context(7)->source);

        SiteMutationScope::run(SiteMutationContext::SOURCE_EXTENSION, 'extension:package:Board:install', function (): void {
            $context = SiteMutationScope::context(7);
            self::assertSame(SiteMutationContext::SOURCE_EXTENSION, $context->source);
            self::assertSame('extension:package:Board:install', $context->correlationId);

            SiteMutationScope::run(SiteMutationContext::SOURCE_AUTOMATION, null, function (): void {
                self::assertSame(SiteMutationContext::SOURCE_AUTOMATION, SiteMutationScope::source());
            });
            self::assertSame(SiteMutationContext::SOURCE_EXTENSION, SiteMutationScope::source(), '안쪽 범위가 끝나면 바깥 범위로 돌아온다');
        });

        self::assertSame(SiteMutationContext::SOURCE_ADMIN, SiteMutationScope::source());
    }

    public function testScopesCloseOnErrorsAndRejectUnknownSources(): void
    {
        try {
            SiteMutationScope::run(SiteMutationContext::SOURCE_API, null, static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(SiteMutationContext::SOURCE_ADMIN, SiteMutationScope::source(), '예외가 나도 범위가 닫힌다');

        $this->expectException(\InvalidArgumentException::class);
        SiteMutationScope::run('robot', null, static fn () => null);
    }

    public function testInheritPassesAnEarlierChangesSourceToFollowUpWork(): void
    {
        $origin = SiteMutationContext::create(7, SiteMutationContext::SOURCE_EXTENSION, 'change-set-1');

        $followUp = SiteMutationScope::inherit($origin, static fn (): SiteMutationContext => SiteMutationScope::context(7));

        self::assertSame(SiteMutationContext::SOURCE_EXTENSION, $followUp->source);
        self::assertSame('change-set-1', $followUp->correlationId);
        self::assertSame(SiteMutationContext::SOURCE_ADMIN, SiteMutationScope::inherit(null, static fn (): string => SiteMutationScope::source()));
    }
}
