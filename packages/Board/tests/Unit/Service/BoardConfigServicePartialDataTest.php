<?php

namespace Tests\Board\Unit\Service;

use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardCategoryMappingRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Service\BoardConfigService;
use Tests\Board\TestCase;

/**
 * 서비스 계층 부분 데이터 계약
 *
 * "빈 체크박스 = 해제(0)" 채움은 관리자 컨트롤러(getFormSchema) 경계의 일이다.
 * 서비스가 그 폼 의미론을 상속하면, 프로그램 호출(확장·CLI·시더)의 부분
 * 업데이트가 미지정 bool 을 전부 0으로 꺼버린다 — is_active 포함.
 * (실제 사례: 커뮤니티 세팅 스크립트가 updateBoard(['read_level' => 1]) 을
 * 호출하자 게시판이 비활성화되어 전면 404가 났다.)
 */
class BoardConfigServicePartialDataTest extends TestCase
{
    private BoardConfigRepository $repository;
    private BoardGroupRepository $groups;
    private BoardCategoryMappingRepository $mappings;
    private BoardConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(BoardConfigRepository::class);
        $this->groups = $this->createMock(BoardGroupRepository::class);
        $this->mappings = $this->createMock(BoardCategoryMappingRepository::class);

        $this->service = new BoardConfigService(
            $this->repository,
            $this->groups,
            $this->mappings,
            $this->createMock(BoardArticleRepository::class),
            null,
            null
        );
    }

    public function testUpdateWithPartialDataDoesNotTouchAbsentBooleans(): void
    {
        $this->repository->method('find')->willReturn($this->existingBoard());

        $captured = null;
        $this->repository->method('update')
            ->willReturnCallback(function ($id, $data) use (&$captured) {
                $captured = $data;
                return 1;
            });

        $result = $this->service->updateBoard(20, ['read_level' => 1]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame(1, $captured['read_level']);

        foreach (['is_active', 'use_comment', 'use_reaction', 'use_file', 'use_link', 'use_category'] as $flag) {
            $this->assertArrayNotHasKey($flag, $captured, "미지정 bool({$flag})은 건드리면 안 된다");
        }
    }

    public function testUpdateRespectsExplicitBooleanValues(): void
    {
        $this->repository->method('find')->willReturn($this->existingBoard());

        $captured = null;
        $this->repository->method('update')
            ->willReturnCallback(function ($id, $data) use (&$captured) {
                $captured = $data;
                return 1;
            });

        $result = $this->service->updateBoard(20, ['use_comment' => 0, 'is_active' => 1]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame(0, $captured['use_comment'], '명시한 해제(0)는 반영되어야 한다');
        $this->assertSame(1, $captured['is_active']);
    }

    public function testCreateWithPartialDataLeavesAbsentBooleansToDbDefaults(): void
    {
        $this->groups->method('find')->willReturn(BoardGroup::fromArray([
            'group_id' => 1,
            'domain_id' => 1,
            'group_slug' => 'default',
            'group_name' => '기본 그룹',
        ]));
        $this->repository->method('existsBySlug')->willReturn(false);
        $this->repository->method('getNextSortOrder')->willReturn(1);
        $this->repository->method('find')->willReturn($this->existingBoard());

        $captured = null;
        $this->repository->method('create')
            ->willReturnCallback(function ($data) use (&$captured) {
                $captured = $data;
                return 20;
            });

        $result = $this->service->createBoard(1, [
            'board_slug' => 'partial',
            'board_name' => '부분 생성',
            'group_id' => 1,
            'write_level' => 1,
        ]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame(1, $captured['write_level']);

        foreach (['is_active', 'use_comment', 'use_file', 'use_link', 'use_category'] as $flag) {
            $this->assertArrayNotHasKey($flag, $captured, "미지정 bool({$flag})은 DB 기본값에 맡긴다");
        }
    }

    private function existingBoard(): BoardConfig
    {
        return BoardConfig::fromArray([
            'board_id' => 20,
            'domain_id' => 1,
            'group_id' => 1,
            'board_slug' => 'free',
            'board_name' => '자유게시판',
        ]);
    }
}
