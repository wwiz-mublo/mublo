<?php

namespace Tests\Unit\Service\Menu;

use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Code\CodeGenerator;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Repository\Menu\MenuTreeRepository;
use Mublo\Service\Menu\MenuService;
use PHPUnit\Framework\TestCase;

class MenuServiceSeedTest extends TestCase
{
    public function testDefaultMenuSeedFailsWhenHomeTreeInsertionFails(): void
    {
        $service = $this->getMockBuilder(MenuService::class)
            ->setConstructorArgs([
                $this->createMock(MenuItemRepository::class),
                $this->createMock(MenuTreeRepository::class),
                $this->createMock(CodeGenerator::class),
            ])
            ->onlyMethods(['createItem', 'addToTree'])
            ->getMock();

        $service->method('createItem')
            ->willReturn(Result::success('', ['menu_code' => 'HOME0001']));
        $service->expects($this->once())
            ->method('addToTree')
            ->with(17, 'HOME0001')
            ->willReturn(Result::failure('트리 추가에 실패했습니다.'));

        $result = $service->seedDefaultMenus(17);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('트리', $result->getMessage());
    }
}
