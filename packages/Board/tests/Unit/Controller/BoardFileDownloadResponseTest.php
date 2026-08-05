<?php

namespace Tests\Board\Unit\Controller;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Result\Result;
use Mublo\Core\Session\SessionInterface;
use Mublo\Entity\Domain\Domain;
use Mublo\Packages\Board\Controller\Front\BoardController;
use Mublo\Packages\Board\Service\BoardArticleService;
use Mublo\Packages\Board\Service\BoardCategoryService;
use Mublo\Packages\Board\Service\BoardCommentService;
use Mublo\Packages\Board\Service\BoardConfigService;
use Mublo\Packages\Board\Service\BoardFileService;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Packages\Board\Service\BoardReactionService;
use PHPUnit\Framework\TestCase;

/**
 * 첨부 다운로드 실패 응답 계약.
 *
 * 다운로드는 스킨의 <a> 클릭, 즉 브라우저 내비게이션으로 들어온다. 실패를 JSON 으로
 * 돌려주면 방문자는 페이지 대신 {"result":"error",...} 원문을 본다. 그래서 사유별로
 * 로그인 유도 / 403 / 404 를 고르고, JSON 은 이를 요청한 쪽에만 준다.
 */
class BoardFileDownloadResponseTest extends TestCase
{
    private const PUBLIC_ID = 'a1b2c3d4e5f60718293a4b';

    public function testGuestNavigationIsSentToLoginWithReturnUrl(): void
    {
        $response = $this->download(Result::failure('로그인 후 다운로드 가능합니다.', [
            'reason'      => BoardFileService::REASON_LOGIN_REQUIRED,
            'article_url' => '/board/notice/view/10',
        ]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(
            '/login?redirect=' . rawurlencode('/board/notice/view/10'),
            $response->getLocation()
        );
    }

    public function testMemberWithoutLevelGetsForbiddenPage(): void
    {
        $response = $this->download(Result::failure('다운로드 권한이 없습니다.', [
            'reason' => BoardFileService::REASON_FORBIDDEN,
        ]));

        $this->assertInstanceOf(ViewResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('forbidden', $response->getViewPath());
        $this->assertSame('다운로드 권한이 없습니다.', $response->getViewData()['message']);
    }

    public function testMissingFileGetsNotfoundPage(): void
    {
        $response = $this->download(Result::failure('파일을 찾을 수 없습니다.', [
            'reason' => BoardFileService::REASON_NOT_FOUND,
        ]));

        $this->assertInstanceOf(ViewResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('notfound', $response->getViewPath());
    }

    public function testScriptedRequestStillGetsJson(): void
    {
        $response = $this->download(
            Result::failure('로그인 후 다운로드 가능합니다.', [
                'reason' => BoardFileService::REASON_LOGIN_REQUIRED,
            ]),
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('로그인 후 다운로드 가능합니다.', $response->getMessage());
        $this->assertSame(
            BoardFileService::REASON_LOGIN_REQUIRED,
            $response->getData()['data']['reason']
        );
    }

    /** 라우트 패턴을 우회해 들어온 잘못된 식별자도 JSON 원문이 아니라 404 페이지. */
    public function testMalformedPublicIdGetsNotfoundPage(): void
    {
        $fileService = $this->createMock(BoardFileService::class);
        $fileService->expects($this->never())->method('download');

        $response = $this->makeController($fileService)
            ->fileDownload(['public_id' => 'not-a-public-id'], $this->context());

        $this->assertInstanceOf(ViewResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
    }

    private function download(Result $failure, array $server = []): mixed
    {
        $fileService = $this->createMock(BoardFileService::class);
        $fileService->method('download')->willReturn($failure);

        return $this->makeController($fileService)
            ->fileDownload(['public_id' => self::PUBLIC_ID], $this->context($server));
    }

    private function makeController(BoardFileService $fileService): BoardController
    {
        return new BoardController(
            $this->createMock(BoardArticleService::class),
            $this->createMock(BoardCategoryService::class),
            $this->createMock(BoardCommentService::class),
            $this->createMock(BoardConfigService::class),
            $fileService,
            $this->createMock(BoardPermissionService::class),
            $this->createMock(BoardReactionService::class),
            $this->createMock(AuthContextInterface::class),
            $this->createMock(SessionInterface::class),
            $this->createMock(EventDispatcher::class),
        );
    }

    private function context(array $server = []): Context
    {
        $request = new Request(
            'GET',
            '/board/notice/file/download/' . self::PUBLIC_ID,
            [],
            [],
            $server + ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,*/*'],
        );

        $context = new Context($request);
        $context->setDomainInfo(new Domain(1, 'example.test'));

        return $context;
    }
}
