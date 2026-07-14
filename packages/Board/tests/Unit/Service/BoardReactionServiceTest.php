<?php

namespace Tests\Board\Unit\Service;

use Mublo\Core\Context\Context;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardReactionRepository;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Packages\Board\Service\BoardReactionService;
use Mublo\Contract\Auth\AuthContextInterface;
use Tests\Board\TestCase;

class BoardReactionServiceTest extends TestCase
{
    private BoardReactionRepository $reactions;
    private BoardArticleRepository $articles;
    private BoardConfigRepository $boards;
    private BoardPermissionService $permissions;
    private AuthContextInterface $auth;
    private BoardReactionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reactions = $this->createMock(BoardReactionRepository::class);
        $this->articles = $this->createMock(BoardArticleRepository::class);
        $this->boards = $this->createMock(BoardConfigRepository::class);
        $this->permissions = $this->createMock(BoardPermissionService::class);
        $this->auth = $this->createMock(AuthContextInterface::class);
        $this->auth->method('id')->willReturn(7);

        $this->service = new BoardReactionService(
            $this->reactions,
            $this->articles,
            $this->createMock(BoardCommentRepository::class),
            $this->boards,
            $this->permissions,
            null,
            $this->auth,
        );
    }

    public function testRejectsReactionToForeignDomainArticle(): void
    {
        $this->articles->method('find')->willReturn($this->article(2));
        $this->boards->method('find')->willReturn($this->board(2));
        $this->permissions->method('canReact')->willReturn(true);
        $this->permissions->expects($this->never())->method('canRead');
        $this->reactions->expects($this->never())->method('toggle');

        $result = $this->service->toggle('article', 10, 'like', $this->context(1));

        $this->assertFalse($result['success']);
    }

    public function testRejectsReactionToUnreadableArticle(): void
    {
        $this->articles->method('find')->willReturn($this->article(1));
        $this->boards->method('find')->willReturn($this->board(1));
        $this->permissions->method('canReact')->willReturn(true);
        $this->permissions->method('canRead')->willReturn(false);
        $this->reactions->expects($this->never())->method('toggle');

        $result = $this->service->toggle('article', 10, 'like', $this->context(1));

        $this->assertFalse($result['success']);
    }

    public function testRejectsReactionTypeNotEnabledByBoard(): void
    {
        $this->articles->method('find')->willReturn($this->article(1));
        $this->boards->method('find')->willReturn($this->board(1));
        $this->permissions->method('canReact')->willReturn(true);
        $this->permissions->method('canRead')->willReturn(true);
        $this->reactions->expects($this->never())->method('toggle');

        $result = $this->service->toggle('article', 10, 'surprise', $this->context(1));

        $this->assertFalse($result['success']);
        $this->assertSame('허용되지 않은 반응 타입입니다.', $result['message']);
    }

    public function testGlobalBoardAllowsForeignDomainToReachReactionValidation(): void
    {
        $this->articles->method('find')->willReturn($this->article(2));
        $this->boards->method('find')->willReturn($this->board(2, true));
        $this->permissions->method('canReact')->willReturn(true);
        $this->permissions->expects($this->once())->method('canRead')->willReturn(true);
        $this->reactions->expects($this->never())->method('toggle');

        $result = $this->service->toggle('article', 10, 'surprise', $this->context(1));

        $this->assertFalse($result['success']);
        $this->assertSame('허용되지 않은 반응 타입입니다.', $result['message']);
    }

    public function testGlobalBoardStoresReactionInAccessDomain(): void
    {
        $this->articles->method('find')->willReturn($this->article(2));
        $this->boards->method('find')->willReturn($this->board(2, true));
        $this->permissions->method('canReact')->willReturn(true);
        $this->permissions->method('canRead')->willReturn(true);
        $this->reactions->expects($this->once())
            ->method('toggle')
            ->with(1, 20, 'article', 10, 7, 'like')
            ->willReturn(['action' => 'added', 'old_type' => null]);
        $this->reactions->method('findByTargetAndMember')->willReturn(null);
        $this->reactions->method('countByTargetGroupByType')->willReturn(['like' => 1]);

        $result = $this->service->toggle('article', 10, 'like', $this->context(1));

        $this->assertTrue($result['success']);
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
            'status' => 'published',
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
            'use_reaction' => 1,
            'is_global' => $global ? 1 : 0,
            'reaction_config' => [
                'like' => ['enabled' => true, 'label' => '좋아요', 'icon' => '👍'],
            ],
        ]);
    }
}
