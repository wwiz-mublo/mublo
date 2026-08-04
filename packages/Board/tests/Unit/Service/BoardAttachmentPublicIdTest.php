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
