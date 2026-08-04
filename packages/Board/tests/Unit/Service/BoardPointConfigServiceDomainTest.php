<?php

namespace Tests\Board\Unit\Service;

use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Repository\BoardPointConfigRepository;
use Mublo\Packages\Board\Service\BoardPointConfigService;
use PHPUnit\Framework\TestCase;

class BoardPointConfigServiceDomainTest extends TestCase
{
    public function testSaveRejectsBoardOutsideCurrentDomain(): void
    {
        $points = $this->createMock(BoardPointConfigRepository::class);
        $boards = $this->createMock(BoardConfigRepository::class);
        $groups = $this->createMock(BoardGroupRepository::class);
        $boards->expects($this->once())
            ->method('findAccessibleById')
            ->with(7, 99)
            ->willReturn(null);
        $points->expects($this->never())->method('saveScopeConfig');

        $service = new BoardPointConfigService($points, $boards, $groups);
        $result = $service->saveScopeConfig(7, 'board', 99, []);

        $this->assertTrue($result->isFailure());
    }

    public function testDeleteRejectsGroupOutsideCurrentDomain(): void
    {
        $points = $this->createMock(BoardPointConfigRepository::class);
        $boards = $this->createMock(BoardConfigRepository::class);
        $groups = $this->createMock(BoardGroupRepository::class);
        $groups->expects($this->once())
            ->method('findByIdForDomain')
            ->with(7, 88)
            ->willReturn(null);
        $points->expects($this->never())->method('deleteScopeConfig');

        $service = new BoardPointConfigService($points, $boards, $groups);
        $result = $service->deleteScopeConfig(7, 'group', 88);

        $this->assertTrue($result->isFailure());
    }
}
