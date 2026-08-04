<?php

namespace Tests\Unit\Service;

use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Member\AdminPermissionRepository;
use Mublo\Repository\Member\MemberLevelRepository;
use Mublo\Service\Admin\AdminPermissionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminPermissionServiceTest extends TestCase
{
    private AdminPermissionService $service;

    protected function setUp(): void
    {
        $this->service = new AdminPermissionService(
            $this->createMock(Database::class),
            $this->createMock(AdminPermissionRepository::class),
            $this->createMock(MemberLevelRepository::class)
        );
    }

    #[DataProvider('adminRouteProvider')]
    public function testDetectActionUsesMethodAsSafeFallback(
        string $method,
        string $path,
        string $expected
    ): void {
        self::assertSame($expected, $this->service->detectAction($path, $method));
    }

    /**
     * 감사 당시 URL 이름만으로 list 권한으로 분류되던 핵심 관리자 작업 경로를 고정한다.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function adminRouteProvider(): iterable
    {
        yield 'kit apply' => ['POST', '/admin/block-kit/apply', 'edit'];
        yield 'kit rollback' => ['POST', '/admin/block-kit/rollback', 'edit'];
        yield 'row order' => ['POST', '/admin/block-row/order-set', 'edit'];
        yield 'row toggle' => ['POST', '/admin/block-row/toggle-active', 'edit'];
        yield 'frame publish' => ['POST', '/admin/block-editor/frame-publish', 'edit'];
        yield 'frame ai' => ['POST', '/admin/block-editor/frame-ai', 'edit'];
        yield 'dashboard layout reset' => ['POST', '/admin/dashboard/layout/reset', 'edit'];
        yield 'domain proxy login' => ['POST', '/admin/domains/proxy-login', 'edit'];
        yield 'system migration' => ['POST', '/admin/system/run-migration', 'edit'];
        yield 'unknown post action' => ['POST', '/admin/example/execute', 'edit'];
        yield 'unknown patch action' => ['PATCH', '/admin/example/42', 'edit'];
        yield 'HTTP delete without URL keyword' => ['DELETE', '/admin/example/42', 'delete'];
        yield 'camel-case bulk delete' => ['POST', '/admin/member/listDelete', 'delete'];
        yield 'camel-case bulk modify' => ['POST', '/admin/member/listModify', 'edit'];
        yield 'last action segment wins over resource name' => ['POST', '/admin/import/listModify', 'edit'];
        yield 'store remains write' => ['POST', '/admin/member/store', 'write'];
        yield 'upload is write' => ['POST', '/admin/extensions/upload', 'write'];
        yield 'post preview remains read-only' => ['POST', '/admin/block/preview', 'read'];
        yield 'post search remains read-only' => ['POST', '/admin/member/search', 'read'];
        yield 'post list remains list' => ['POST', '/admin/member/list', 'list'];
        yield 'get numeric resource is read' => ['GET', '/admin/member/42', 'read'];
        yield 'get index is list' => ['GET', '/admin/member/index', 'list'];
        yield 'resource containing edit text is list' => ['GET', '/admin/editors', 'list'];
    }

    public function testDetectActionWithoutMethodKeepsLegacyUrlOnlyFallback(): void
    {
        self::assertSame('list', $this->service->detectAction('/admin/example/execute'));
        self::assertSame('read', $this->service->detectAction('/admin/example/42'));
    }
}
