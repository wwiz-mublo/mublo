<?php

namespace Tests\Unit\Controller\Admin;

use Mublo\Controller\Admin\BlockEditorController;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Service\Auth\AuthService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class BlockEditorControllerAccessTest extends TestCase
{
    protected function setUp(): void
    {
        DependencyContainer::resetInstance();
    }

    protected function tearDown(): void
    {
        DependencyContainer::resetInstance();
    }

    public function testIndexRejectsAdminWithoutDomainOperatorPermission(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->expects(self::once())
            ->method('canOperateDomain')
            ->willReturn(false);

        $container = DependencyContainer::getInstance();
        $container->set(AuthService::class, $auth);

        $reflection = new ReflectionClass(BlockEditorController::class);
        /** @var BlockEditorController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('container')->setValue($controller, $container);

        $response = $controller->index([], $this->createMock(Context::class));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Error/403', $response->getViewPath());
        self::assertSame(
            '블록 에디터는 도메인 운영자 이상만 사용할 수 있습니다.',
            $response->getViewData()['message']
        );
    }

    #[DataProvider('safeAdminReturnUrlProvider')]
    public function testReturnUrlOnlyAcceptsSafeAdminLocations(
        string $candidate,
        string $host,
        string $expected
    ): void {
        $method = new ReflectionMethod(BlockEditorController::class, 'safeAdminReturnUrl');

        self::assertSame($expected, $method->invoke(null, $candidate, $host));
    }

    public static function safeAdminReturnUrlProvider(): array
    {
        $fallback = '/admin/block-page?activeCode=004_002';

        return [
            'relative admin path' => ['/admin/block-row?page=2', 'example.com', '/admin/block-row?page=2'],
            'same host absolute referer' => ['https://example.com/admin?tab=site#blocks', 'example.com:443', '/admin?tab=site#blocks'],
            'external referer' => ['https://evil.example/admin', 'example.com', $fallback],
            'protocol relative URL' => ['//evil.example/admin', 'example.com', $fallback],
            'editor itself' => ['/admin/block-editor?context=page:1', 'example.com', $fallback],
            'similar non-admin prefix' => ['/administrator', 'example.com', $fallback],
            'empty candidate' => ['', 'example.com', $fallback],
        ];
    }
}
