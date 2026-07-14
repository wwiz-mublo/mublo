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

class BoardArticleGuestServiceTest extends TestCase
{
    private BoardArticleRepository $articles;
    private BoardPermissionService $permissions;
    private BoardArticleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->articles = $this->createMock(BoardArticleRepository::class);
        $boards = $this->createMock(BoardConfigRepository::class);
        $boards->method('find')->willReturn(BoardConfig::fromArray([
            'board_id' => 20,
            'domain_id' => 1,
            'group_id' => 1,
            'board_slug' => 'guest',
            'board_name' => 'Guest',
        ]));
        $this->permissions = $this->createMock(BoardPermissionService::class);

        $this->service = new BoardArticleService(
            $this->articles,
            $boards,
            $this->createMock(MemberRepository::class),
            $this->permissions,
            null,
            $this->createMock(AuthContextInterface::class),
        );
    }

    public function testGuestArticleCannotBeUpdatedWithoutSessionAuthorization(): void
    {
        $this->articles->method('find')->willReturn($this->guestArticle());
        $this->permissions->method('canModify')->willReturn(false);
        $this->articles->expects($this->never())->method('update');

        $result = $this->service->update(10, ['title' => 'Changed'], $this->context());

        $this->assertTrue($result->isFailure());
    }

    public function testAuthorizedGuestArticleCanBeUpdated(): void
    {
        $this->articles->method('find')->willReturn($this->guestArticle());
        $this->permissions->method('canModify')->willReturn(false);
        $this->articles->expects($this->once())->method('update')->willReturn(1);

        $result = $this->service->update(
            10,
            ['title' => 'Changed'],
            $this->context(),
            true,
        );

        $this->assertTrue($result->isSuccess());
    }

    public function testAuthorizedGuestArticleCanBeDeleted(): void
    {
        $this->articles->method('find')->willReturn($this->guestArticle());
        $this->permissions->method('canDelete')->willReturn(false);
        $this->articles->expects($this->once())
            ->method('updateStatus')
            ->with(10, 'deleted')
            ->willReturn(true);

        $result = $this->service->delete(10, $this->context(), true);

        $this->assertTrue($result->isSuccess());
    }

    public function testGuestAuthorizationNeverAllowsMemberArticleMutation(): void
    {
        $this->articles->method('find')->willReturn(BoardArticle::fromArray(
            $this->makeArticleData(['article_id' => 10, 'board_id' => 20, 'member_id' => 42])
        ));
        $this->permissions->method('canModify')->willReturn(false);
        $this->articles->expects($this->never())->method('update');

        $result = $this->service->update(10, ['title' => 'Changed'], $this->context(), true);

        $this->assertTrue($result->isFailure());
    }

    private function guestArticle(): BoardArticle
    {
        return BoardArticle::fromArray($this->makeArticleData([
            'article_id' => 10,
            'domain_id' => 1,
            'board_id' => 20,
            'member_id' => null,
            'author_password' => password_hash('secret', PASSWORD_DEFAULT),
        ]));
    }

    private function context(): Context
    {
        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn(1);
        return $context;
    }
}
