<?php

namespace Tests\Board\Unit\Service;

use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Http\Request;
use Mublo\Entity\Domain\Domain;
use Mublo\Infrastructure\Image\ImageProcessor;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Packages\Board\Entity\BoardAttachment;
use Mublo\Packages\Board\Helper\ArticlePresenter;
use Mublo\Packages\Board\Repository\BoardAttachmentRepository;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardLinkRepository;
use Mublo\Packages\Board\Service\BoardFileService;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Service\Auth\AuthService;
use PHPUnit\Framework\TestCase;

/**
 * 첨부 다운로드 식별자 — 정수 PK 를 URL 에 싣지 않는다.
 *
 * 배경: 다운로드 URL 이 /board/{board_id}/file/download/{attachment_id} 였다.
 * attachment_id 는 AUTO_INCREMENT 라 URL 만으로 전체 첨부 건수와 증가 속도가
 * 드러나고, 번호를 훑어 존재 여부를 확인할 수 있었다. 권한 검사가 막고 있었으므로
 * 취약점은 아니지만 알려줄 필요가 없는 정보였다.
 *
 * public_id(hex 22자)로 교체했고, 여기서 그 계약을 고정한다.
 */
final class BoardAttachmentPublicIdTest extends TestCase
{
    private const PUBLIC_ID = 'a1b2c3d4e5f60718293a4b';

    public function testDownloadLooksUpByPublicIdNotThePrimaryKey(): void
    {
        $attachments = $this->createMock(BoardAttachmentRepository::class);
        // 정수 PK 조회 경로는 다운로드에서 쓰이면 안 된다
        $attachments->expects($this->never())->method('findWithArticle');
        $attachments->expects($this->once())
            ->method('findWithArticleByPublicId')
            ->with(self::PUBLIC_ID)
            ->willReturn(null);

        $result = $this->service($attachments)->download(self::PUBLIC_ID, $this->context());

        $this->assertTrue($result->isFailure());
    }

    public function testUnknownPublicIdIsRejected(): void
    {
        $attachments = $this->createMock(BoardAttachmentRepository::class);
        $attachments->method('findWithArticleByPublicId')->willReturn(null);

        $result = $this->service($attachments)->download('ffffffffffffffffffffff', $this->context());

        $this->assertTrue($result->isFailure());
        $this->assertSame('파일을 찾을 수 없습니다.', $result->getMessage());
    }

    public function testSkinArrayCarriesPublicIdSoLinksCanBeBuilt(): void
    {
        $attachments = $this->createMock(BoardAttachmentRepository::class);
        $attachments->method('findByArticle')->willReturn([$this->attachment()]);

        $list = $this->service($attachments)->getAttachmentsByArticle(10);

        $this->assertSame(self::PUBLIC_ID, $list[0]['public_id']);
    }

    public function testSkinArrayStillHidesStoredNameAndPath(): void
    {
        $attachments = $this->createMock(BoardAttachmentRepository::class);
        $attachments->method('findByArticle')->willReturn([$this->attachment()]);

        $list = $this->service($attachments)->getAttachmentsByArticle(10);

        // public_id 를 추가하면서 기존 비노출 계약이 헐거워지지 않았는지 함께 고정한다
        $this->assertArrayNotHasKey('stored_name', $list[0]);
        $this->assertArrayNotHasKey('file_path', $list[0]);
    }

    public function testEntityRoundTripsPublicId(): void
    {
        $entity = BoardAttachment::fromArray([
            'attachment_id' => 50,
            'public_id' => self::PUBLIC_ID,
        ]);

        $this->assertSame(self::PUBLIC_ID, $entity->toArray()['public_id']);
    }

    /**
     * 프론트 컨트롤러의 조립 경로 전체를 태운다.
     *
     * Service 와 Presenter 는 각각 필드 allowlist 를 갖고 있고 둘 다 개별 테스트가
     * 있었지만, 이어붙인 지점은 아무도 보지 않았다. Presenter 쪽 목록에서
     * public_id 가 빠져 있어 다운로드 링크가 `/board/{slug}/file/download/` 로
     * 렌더됐고, 라우트가 hex 22자를 요구하므로 매칭조차 되지 않았다.
     *
     * BoardController::view() 가 하는 것과 같은 순서로 호출한다.
     */
    public function testAssembledSkinArrayKeepsPublicIdForDownloadLink(): void
    {
        $attachments = $this->createMock(BoardAttachmentRepository::class);
        $attachments->method('findByArticle')->willReturn([$this->attachment()]);

        $assembled = (new ArticlePresenter())->decorateAttachments(
            $this->service($attachments)->getAttachmentsByArticle(10)
        );

        $this->assertSame(self::PUBLIC_ID, $assembled[0]['public_id'] ?? null);
    }

    /**
     * 조립 후에도 비노출 계약은 그대로여야 한다 — public_id 를 살리려고
     * allowlist 를 넓힌 것이 아님을 함께 고정한다.
     */
    public function testAssembledSkinArrayStillHidesStoredNameAndPath(): void
    {
        $attachments = $this->createMock(BoardAttachmentRepository::class);
        $attachments->method('findByArticle')->willReturn([$this->attachment()]);

        $assembled = (new ArticlePresenter())->decorateAttachments(
            $this->service($attachments)->getAttachmentsByArticle(10)
        );

        $this->assertArrayNotHasKey('stored_name', $assembled[0]);
        $this->assertArrayNotHasKey('file_path', $assembled[0]);
        $this->assertArrayNotHasKey('domain_id', $assembled[0]);
    }

    /**
     * 다운로드 횟수는 스킨이 "다운로드 N" 라벨을 그릴 때 쓴다. 조립 과정에서
     * 사라지면 라벨이 통째로 안 나온다(뷰가 > 0 조건으로 감싼다).
     */
    public function testAssembledSkinArrayKeepsDownloadCount(): void
    {
        $attachment = BoardAttachment::fromArray([
            'attachment_id' => 50,
            'public_id' => self::PUBLIC_ID,
            'article_id' => 10,
            'original_name' => 'test.pdf',
            'file_extension' => 'pdf',
            'download_count' => 7,
        ]);

        $attachments = $this->createMock(BoardAttachmentRepository::class);
        $attachments->method('findByArticle')->willReturn([$attachment]);

        $assembled = (new ArticlePresenter())->decorateAttachments(
            $this->service($attachments)->getAttachmentsByArticle(10)
        );

        $this->assertSame(7, $assembled[0]['download_count'] ?? null);
    }

    private function attachment(): BoardAttachment
    {
        return BoardAttachment::fromArray([
            'attachment_id' => 50,
            'public_id' => self::PUBLIC_ID,
            'domain_id' => 1,
            'board_id' => 20,
            'article_id' => 10,
            'original_name' => 'test.pdf',
            'stored_name' => 'stored.pdf',
            'file_path' => 'D1/board/2026/08',
            'file_size' => 123,
            'file_extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'is_image' => 0,
        ]);
    }

    private function service(BoardAttachmentRepository $attachments): BoardFileService
    {
        return new BoardFileService(
            $attachments,
            $this->createMock(BoardLinkRepository::class),
            $this->createMock(BoardArticleRepository::class),
            $this->createMock(BoardConfigRepository::class),
            $this->createMock(MemberQueryInterface::class),
            $this->createMock(BoardPermissionService::class),
            $this->createMock(EventDispatcher::class),
            $this->createMock(AuthService::class),
            $this->createMock(FileUploader::class),
            $this->createMock(ImageProcessor::class),
        );
    }

    private function context(): Context
    {
        $context = new Context(new Request('GET', '/board/notice/file/download/' . self::PUBLIC_ID));
        $context->setDomainInfo(new Domain(1, 'example.test'));

        return $context;
    }
}
