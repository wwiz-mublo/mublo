<?php

namespace Tests\Unit\Service\Block;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Service\Block\BlockColumnWriteContext;
use Mublo\Service\Block\BlockColumnWriteContextFactory;
use Mublo\Service\Block\BlockColumnWriteGuard;
use PHPUnit\Framework\TestCase;

class BlockColumnWriteBoundaryTest extends TestCase
{
    public function testFactoryAllowsRawJsForAllTrustedEditors(): void
    {
        // 편집 자율성 정책(2026-07-24 재확정): 블록 편집 신뢰를 받은 편집자는
        // 전원 raw JS 사용 가능. super 전용으로 좁히지 말 것.
        $auth = $this->createMock(AuthContextInterface::class);
        $auth->method('canOperateDomain')->willReturn(false);
        $auth->method('isSuper')->willReturn(false);

        $context = (new BlockColumnWriteContextFactory($auth))->create(
            9,
            BlockColumnWriteContext::SOURCE_INTERACTIVE
        );

        $this->assertSame(9, $context->domainId);
        $this->assertSame(BlockColumnWriteContext::SOURCE_INTERACTIVE, $context->source);
        $this->assertTrue($context->allowRawJs);
        // Include는 서버사이드 실행이라 편집 신뢰와 별개로 super 전용.
        $this->assertFalse($context->allowInclude);
    }

    public function testFactoryGatesIncludeToSuperOnly(): void
    {
        $auth = $this->createMock(AuthContextInterface::class);
        $auth->method('canOperateDomain')->willReturn(true);
        $auth->method('isSuper')->willReturn(true);

        $context = (new BlockColumnWriteContextFactory($auth))->create(
            1,
            BlockColumnWriteContext::SOURCE_PREVIEW
        );

        $this->assertTrue($context->allowRawJs);
        $this->assertTrue($context->allowInclude);
    }

    public function testGuardPreservesIncludeBeforeRawJsErrorPriority(): void
    {
        $guard = new BlockColumnWriteGuard();
        $columns = [
            ['content_type' => 'html', 'content_config' => ['js' => 'alert(1)']],
            ['content_type' => 'include'],
        ];

        $message = $guard->firstViolation($columns, BlockColumnWriteContext::interactive(1));

        $this->assertSame('Include 블록은 최고관리자만 사용할 수 있습니다.', $message);
    }

    public function testGuardAllowsCapabilitiesIndependently(): void
    {
        $guard = new BlockColumnWriteGuard();
        $columns = [
            ['content_type' => 'html', 'content_config' => ['js' => 'alert(1)']],
            ['content_type' => 'include'],
        ];

        $rawOnly = new BlockColumnWriteContext(1, BlockColumnWriteContext::SOURCE_INTERACTIVE, true, false);
        $fullyTrusted = new BlockColumnWriteContext(1, BlockColumnWriteContext::SOURCE_INTERACTIVE, true, true);

        $this->assertSame(
            'Include 블록은 최고관리자만 사용할 수 있습니다.',
            $guard->firstViolation($columns, $rawOnly)
        );
        $this->assertNull($guard->firstViolation($columns, $fullyTrusted));
    }

    public function testRevisionRestoreFollowsEditorTrustPolicy(): void
    {
        $regular = BlockColumnWriteContext::revisionRestore(7);
        $super = BlockColumnWriteContext::revisionRestore(7, true);

        $this->assertTrue($regular->allowUnresolvedExtension);
        // raw JS 는 편집 신뢰 전원 허용 — 복구 경로도 동일 정책
        $this->assertTrue($regular->allowRawJs);
        // Include 만 super 게이트
        $this->assertFalse($regular->allowInclude);
        $this->assertTrue($super->allowRawJs);
        $this->assertTrue($super->allowInclude);
    }
}
