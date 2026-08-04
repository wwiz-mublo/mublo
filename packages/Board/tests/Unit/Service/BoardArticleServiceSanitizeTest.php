<?php

namespace Tests\Board\Unit\Service;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Packages\Board\Event\ArticleUpdatingEvent;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Service\BoardArticleService;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Auth\AuthContextInterface;
use Tests\Board\TestCase;

/**
 * 본문 정화의 최종 경계가 서비스에 있는지 검증 (회귀).
 *
 * 컨트롤러 정화를 우회하는 경로 — 확장의 서비스 직접 호출,
 * Creating/Updating 이벤트 구독자의 본문 변조, 향후 CLI/가져오기 — 에서도
 * 저장 직전에 script 등 액티브 콘텐츠가 제거되어야 한다.
 */
class BoardArticleServiceSanitizeTest extends TestCase
{
    private BoardArticleRepository $articles;
    private BoardConfigRepository $boards;
    private MemberQueryInterface $members;
    private BoardPermissionService $permissions;
    private AuthContextInterface $auth;
    private BoardArticleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->articles = $this->createMock(BoardArticleRepository::class);
        $this->boards = $this->createMock(BoardConfigRepository::class);
        $this->boards->method('find')->willReturn(BoardConfig::fromArray([
            'board_id' => 20,
            'domain_id' => 1,
            'group_id' => 1,
            'board_slug' => 'general',
            'board_name' => 'General',
        ]));
        $this->members = $this->createMock(MemberQueryInterface::class);
        $this->permissions = $this->createMock(BoardPermissionService::class);
        $this->auth = $this->createMock(AuthContextInterface::class);

        $this->service = new BoardArticleService(
            $this->articles,
            $this->boards,
            $this->members,
            $this->permissions,
            null,
            $this->auth,
        );
    }

    public function testUpdateSanitizesContentAtServiceBoundary(): void
    {
        $this->articles->method('find')->willReturn($this->guestArticle());
        $this->permissions->method('canModify')->willReturn(false);

        $savedContent = null;
        $this->articles->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$savedContent): int {
                $savedContent = $data['content'] ?? null;
                return 1;
            });

        // 컨트롤러 정화를 거치지 않은 서비스 직접 호출 시나리오
        $result = $this->service->update(
            10,
            [
                'title' => 'Changed',
                'content' => '<p>정상 본문</p><script>alert("xss")</script><img src="x" onerror="alert(1)">',
            ],
            $this->context(),
            true,
        );

        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($savedContent);
        $this->assertStringContainsString('정상 본문', $savedContent);
        $this->assertStringNotContainsString('<script', $savedContent);
        $this->assertStringNotContainsString('onerror', $savedContent);
    }

    public function testUpdateAllowsEmptyContentAndClearsThumbnail(): void
    {
        $this->articles->method('find')->willReturn($this->guestArticle());
        $this->permissions->method('canModify')->willReturn(false);

        $saved = null;
        $this->articles->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$saved): int {
                $saved = $data;
                return 1;
            });

        $result = $this->service->update(
            10,
            ['title' => 'Changed', 'content' => ''],
            $this->context(),
            true,
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('', $saved['content']);
        $this->assertArrayHasKey('thumbnail', $saved);
        $this->assertNull($saved['thumbnail']);
    }

    public function testUpdateRejectsTitleBeyondDatabaseLimit(): void
    {
        $this->articles->method('find')->willReturn($this->guestArticle());
        $this->permissions->method('canModify')->willReturn(false);
        $this->articles->expects($this->never())->method('update');

        $result = $this->service->update(
            10,
            ['title' => str_repeat('가', 256)],
            $this->context(),
            true,
        );

        $this->assertTrue($result->isFailure());
        $this->assertSame('제목은 255자 이하로 입력해주세요.', $result->getMessage());
    }

    public function testUpdateRevalidatesDataChangedBySubscriber(): void
    {
        $this->articles->method('find')->willReturn($this->guestArticle());
        $this->permissions->method('canModify')->willReturn(false);
        $this->articles->expects($this->never())->method('update');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ArticleUpdatingEvent::class, static function (ArticleUpdatingEvent $event): void {
            $data = $event->getNewData();
            $data['title'] = '<script></script>';
            $event->setNewData($data);
        });
        $this->service = new BoardArticleService(
            $this->articles,
            $this->boards,
            $this->members,
            $this->permissions,
            $dispatcher,
            $this->auth,
        );

        $result = $this->service->update(
            10,
            ['title' => '정상 제목'],
            $this->context(),
            true,
        );

        $this->assertTrue($result->isFailure());
        $this->assertSame('제목을 입력해주세요.', $result->getMessage());
    }

    private function guestArticle(): BoardArticle
    {
        return BoardArticle::fromArray([
            'article_id' => 10,
            'domain_id' => 1,
            'board_id' => 20,
            'member_id' => null,
            'author_name' => '손님',
            'author_password' => password_hash('pw', PASSWORD_DEFAULT),
            'title' => 'Original',
            'content' => '<p>old</p>',
            'status' => 'published',
            'created_at' => '2026-07-24 00:00:00',
            'updated_at' => '2026-07-24 00:00:00',
        ]);
    }

    private function context(): Context
    {
        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn(1);
        return $context;
    }
}
