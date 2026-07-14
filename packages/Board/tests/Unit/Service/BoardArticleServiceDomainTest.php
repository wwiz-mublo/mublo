<?php

namespace Tests\Board\Unit\Service;

use Mublo\Core\Context\Context;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Service\BoardArticleService;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Contract\Auth\AuthContextInterface;
use Tests\Board\TestCase;

class BoardArticleServiceDomainTest extends TestCase
{
    private BoardArticleRepository $articles;
    private BoardConfigRepository $boards;
    private BoardPermissionService $permissions;
    private BoardArticleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articles = $this->createMock(BoardArticleRepository::class);
        $this->boards = $this->createMock(BoardConfigRepository::class);
        $this->permissions = $this->createMock(BoardPermissionService::class);
        $this->service = new BoardArticleService(
            $this->articles,
            $this->boards,
            $this->createMock(MemberRepository::class),
            $this->permissions,
            null,
            $this->createMock(AuthContextInterface::class),
        );
    }

    public function testGetArticleRejectsForeignDomainBeforePermissionCheck(): void
    {
        $article = $this->article(2);
        $this->articles->method('findWithAuthor')->willReturn(['article' => $article]);
        $this->boards->method('find')->willReturn($this->board(2));
        $this->permissions->expects($this->never())->method('canRead');

        $result = $this->service->getArticle(10, $this->context(1), false);

        $this->assertTrue($result->isFailure());
        $this->assertSame('게시글을 찾을 수 없습니다.', $result->getMessage());
    }

    public function testGetArticleAllowsForeignDomainArticleOnGlobalBoard(): void
    {
        $article = $this->article(2);
        $this->articles->method('findWithAuthor')->willReturn(['article' => $article]);
        $this->articles->method('getAdjacentArticles')->willReturn(['prev' => null, 'next' => null]);
        $this->boards->method('find')->willReturn($this->board(2, true));
        $this->permissions->method('canRead')->willReturn(true);

        $result = $this->service->getArticle(10, $this->context(1), false);

        $this->assertTrue($result->isSuccess());
    }

    public function testCreateRejectsBoardFromAnotherDomain(): void
    {
        $this->boards->method('find')->willReturn($this->board(2));
        $this->permissions->expects($this->never())->method('canWrite');
        $this->articles->expects($this->never())->method('create');

        $result = $this->service->create(1, 20, ['title' => 'blocked'], $this->context(1));

        $this->assertTrue($result->isFailure());
    }

    public function testUpdateAndDeleteAllowForeignDomainForGlobalBoardWhenPermitted(): void
    {
        $article = $this->article(2);
        $this->articles->method('find')->willReturn($article);
        $this->boards->method('find')->willReturn($this->board(2, true));
        $this->permissions->method('canModify')->willReturn(true);
        $this->permissions->method('canDelete')->willReturn(true);
        $this->articles->expects($this->once())->method('update')->willReturn(1);
        $this->articles->expects($this->once())->method('updateStatus')->willReturn(true);

        $update = $this->service->update(10, ['title' => 'allowed'], $this->context(1));
        $delete = $this->service->delete(10, $this->context(1));

        $this->assertTrue($update->isSuccess());
        $this->assertTrue($delete->isSuccess());
    }

    public function testUpdateRejectsForeignDomainForNonGlobalBoard(): void
    {
        $this->articles->method('find')->willReturn($this->article(2));
        $this->boards->method('find')->willReturn($this->board(2));
        $this->permissions->expects($this->never())->method('canModify');
        $this->articles->expects($this->never())->method('update');

        $result = $this->service->update(10, ['title' => 'blocked'], $this->context(1));

        $this->assertTrue($result->isFailure());
    }

    private function context(int $domainId): Context
    {
        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn($domainId);
        return $context;
    }

    private function article(int $domainId): BoardArticle
    {
        return BoardArticle::fromArray($this->makeArticleData([
            'article_id' => 10,
            'domain_id' => $domainId,
            'board_id' => 20,
        ]));
    }

    private function board(int $domainId, bool $global = false): BoardConfig
    {
        return BoardConfig::fromArray([
            'board_id' => 20,
            'domain_id' => $domainId,
            'group_id' => 1,
            'board_slug' => 'notice',
            'board_name' => 'Notice',
            'is_global' => $global ? 1 : 0,
        ]);
    }
}
