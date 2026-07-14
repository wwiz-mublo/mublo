<?php
/**
 * packages/Board/tests/Unit/Service/BoardFileServiceLinkTest.php
 *
 * BoardFileService 링크 IDOR 보안 회귀 테스트
 *
 * 배경: addLink/deleteLink는 링크가 속한 게시글 기준으로 도메인 경계와 수정
 * 권한(canModify)을 검증해야 한다. 없으면 아무나 id 추측으로 남의 글에 링크를
 * 첨부하거나 남의 링크를 삭제할 수 있다(IDOR, #145에서 수정).
 *
 * 이 테스트는 다음을 고정한다:
 * - 타 도메인 게시글 → 링크 추가/삭제 거부, 저장/삭제 없음
 * - canModify=false → 거부, 저장/삭제 없음
 * - deleteLink: 링크의 소속 게시글이 사라진 고아 링크 → 거부, 삭제 없음
 */

namespace Tests\Board\Unit\Service;

use Tests\Board\TestCase;
use Mublo\Packages\Board\Service\BoardFileService;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Packages\Board\Repository\BoardAttachmentRepository;
use Mublo\Packages\Board\Repository\BoardLinkRepository;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardLink;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Image\ImageProcessor;
use Mublo\Core\Context\Context;

class BoardFileServiceLinkTest extends TestCase
{
    private BoardAttachmentRepository $attachmentRepo;
    private BoardLinkRepository $linkRepo;
    private BoardArticleRepository $articleRepo;
    private BoardConfigRepository $boardRepo;
    private BoardPermissionService $permission;
    private BoardFileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attachmentRepo = $this->createMock(BoardAttachmentRepository::class);
        $this->linkRepo       = $this->createMock(BoardLinkRepository::class);
        $this->articleRepo    = $this->createMock(BoardArticleRepository::class);
        $this->boardRepo      = $this->createMock(BoardConfigRepository::class);
        $this->permission     = $this->createMock(BoardPermissionService::class);

        $this->service = new BoardFileService(
            $this->attachmentRepo,
            $this->linkRepo,
            $this->articleRepo,
            $this->boardRepo,
            $this->createMock(MemberRepository::class),
            $this->permission,
            null,
            $this->createMock(AuthContextInterface::class),
            $this->createMock(FileUploader::class),
            $this->createMock(ImageProcessor::class)
        );
    }

    private function makeArticle(int $domainId): BoardArticle
    {
        return BoardArticle::fromArray($this->makeArticleData(['domain_id' => $domainId, 'board_id' => 1]));
    }

    private function makeLink(int $articleId, string $url = 'https://example.test'): BoardLink
    {
        return BoardLink::fromArray([
            'link_id' => 500,
            'article_id' => $articleId,
            'domain_id' => 1,
            'link_url' => $url,
        ]);
    }

    private function contextWithDomain(int $domainId): Context
    {
        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn($domainId);
        return $context;
    }

    // ─── addLink ───

    public function testAddLinkRejectedForForeignDomain(): void
    {
        // 글은 도메인 1, 요청은 도메인 2 → 경계 위반
        $this->articleRepo->method('find')->willReturn($this->makeArticle(1));
        $this->boardRepo->method('find')->willReturn($this->createMock(BoardConfig::class));
        $this->linkRepo->expects($this->never())->method('create');

        $result = $this->service->addLink(1, ['link_url' => 'https://evil.example'], $this->contextWithDomain(2));

        $this->assertFalse($result->isSuccess());
    }

    public function testAddLinkRejectedWithoutModifyPermission(): void
    {
        $this->articleRepo->method('find')->willReturn($this->makeArticle(1));
        $this->boardRepo->method('find')->willReturn($this->createMock(BoardConfig::class));
        $this->permission->method('canModify')->willReturn(false);
        $this->linkRepo->expects($this->never())->method('create');

        $result = $this->service->addLink(1, ['link_url' => 'https://evil.example'], $this->contextWithDomain(1));

        $this->assertFalse($result->isSuccess());
    }

    public function testAddLinkRejectsNonHttpSchemesEvenWhenPhpAcceptsTheUrl(): void
    {
        $this->articleRepo->method('find')->willReturn($this->makeArticle(1));
        $board = $this->createMock(BoardConfig::class);
        $board->method('getDomainId')->willReturn(1);
        $board->method('isUseLink')->willReturn(true);
        $this->boardRepo->method('find')->willReturn($board);
        $this->permission->method('canModify')->willReturn(true);
        $this->linkRepo->expects($this->never())->method('create');

        $urls = [
            'javascript://host/%0Aalert(document.domain)',
            'data://text/html,<script>alert(1)</script>',
            'file:///etc/passwd',
        ];
        foreach ($urls as $url) {
            $result = $this->service->addLink(1, ['link_url' => $url], $this->contextWithDomain(1));
            $this->assertTrue($result->isFailure(), $url);
            $this->assertSame('http 또는 https URL을 입력해주세요.', $result->getMessage());
        }
    }

    public function testAddLinkAcceptsHttpsAndNormalizesPlainTextTitle(): void
    {
        $this->articleRepo->method('find')->willReturn($this->makeArticle(1));
        $board = $this->createMock(BoardConfig::class);
        $board->method('getDomainId')->willReturn(1);
        $board->method('isUseLink')->willReturn(true);
        $this->boardRepo->method('find')->willReturn($board);
        $this->permission->method('canModify')->willReturn(true);
        $this->linkRepo->method('existsByUrl')->willReturn(false);
        $this->linkRepo->method('create')->willReturnCallback(function (array $data): int {
            $this->assertSame('https://example.test/path?q=1', $data['link_url']);
            $this->assertSame('예제', $data['link_title']);
            return 500;
        });
        $this->linkRepo->method('find')->willReturn($this->makeLink(1));

        $result = $this->service->addLink(1, [
            'link_url' => 'https://example.test/path?q=1',
            'link_title' => ' <b>예제</b> ',
        ], $this->contextWithDomain(1));

        $this->assertTrue($result->isSuccess());
    }

    // ─── deleteLink ───

    public function testDeleteLinkRejectedForForeignDomain(): void
    {
        $this->linkRepo->method('find')->willReturn($this->makeLink(1));
        $this->articleRepo->method('find')->willReturn($this->makeArticle(1)); // 글 도메인 1
        $this->boardRepo->method('find')->willReturn($this->createMock(BoardConfig::class));
        $this->linkRepo->expects($this->never())->method('delete');

        $result = $this->service->deleteLink(500, $this->contextWithDomain(2)); // 요청 도메인 2

        $this->assertFalse($result->isSuccess());
    }

    public function testDeleteLinkRejectedWithoutModifyPermission(): void
    {
        $this->linkRepo->method('find')->willReturn($this->makeLink(1));
        $this->articleRepo->method('find')->willReturn($this->makeArticle(1));
        $this->boardRepo->method('find')->willReturn($this->createMock(BoardConfig::class));
        $this->permission->method('canModify')->willReturn(false);
        $this->linkRepo->expects($this->never())->method('delete');

        $result = $this->service->deleteLink(500, $this->contextWithDomain(1));

        $this->assertFalse($result->isSuccess());
    }

    public function testDeleteLinkRejectedForOrphanLink(): void
    {
        // 링크는 있으나 소속 게시글이 사라진 경우 → 삭제 거부
        $this->linkRepo->method('find')->willReturn($this->makeLink(999));
        $this->articleRepo->method('find')->willReturn(null);
        $this->linkRepo->expects($this->never())->method('delete');

        $result = $this->service->deleteLink(500, $this->contextWithDomain(1));

        $this->assertFalse($result->isSuccess());
    }

    public function testDeleteLinkRejectedWhenLinkMissing(): void
    {
        $this->linkRepo->method('find')->willReturn(null);
        $this->linkRepo->expects($this->never())->method('delete');

        $result = $this->service->deleteLink(404, $this->contextWithDomain(1));

        $this->assertFalse($result->isSuccess());
    }

    public function testClickLinkRejectsUnsafeLegacyUrl(): void
    {
        $this->linkRepo->method('find')->willReturn(
            $this->makeLink(1, 'javascript://host/%0Aalert(document.domain)')
        );
        $this->linkRepo->expects($this->never())->method('incrementClickCount');

        $result = $this->service->clickLink(500);

        $this->assertTrue($result->isFailure());
        $this->assertSame('안전하지 않은 링크입니다.', $result->getMessage());
    }

    public function testGetLinksOmitsUnsafeLegacyUrl(): void
    {
        $this->linkRepo->method('findByArticle')->willReturn([
            $this->makeLink(1, 'javascript://host/%0Aalert(document.domain)'),
            $this->makeLink(1, 'https://safe.example/path'),
        ]);

        $links = $this->service->getLinksByArticle(1);

        $this->assertCount(1, $links);
        $this->assertSame('https://safe.example/path', $links[0]['link_url']);
    }
}
