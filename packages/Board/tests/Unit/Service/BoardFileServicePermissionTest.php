<?php

namespace Tests\Board\Unit\Service;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Http\Request;
use Mublo\Entity\Domain\Domain;
use Mublo\Infrastructure\Image\ImageProcessor;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardAttachment;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardAttachmentRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardLinkRepository;
use Mublo\Packages\Board\Service\BoardFileService;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Service\Auth\AuthService;
use PHPUnit\Framework\TestCase;

class BoardFileServicePermissionTest extends TestCase
{
    /** 다운로드 URL 에 실리는 공개 식별자 (hex 22자). */
    private const PUBLIC_ID = 'a1b2c3d4e5f60718293a4b';

    public function testUploadFileRequiresModifyPermissionByDefault(): void
    {
        $article = $this->article();
        $board = $this->board();
        $service = $this->makeService(
            article: $article,
            board: $board,
            permissionExpectations: function (BoardPermissionService $permission) use ($board, $article): void {
                $permission->expects($this->once())
                    ->method('canModify')
                    ->with($board, $article, $this->isInstanceOf(Context::class))
                    ->willReturn(false);
            },
            fileUploaderExpectations: function (FileUploader $fileUploader): void {
                $fileUploader->expects($this->never())->method('upload');
            },
        );

        $result = $service->uploadFile(10, ['name' => 'test.pdf'], $this->context());

        $this->assertTrue($result->isFailure());
        $this->assertSame('파일 첨부 권한이 없습니다.', $result->getMessage());
    }

    public function testUploadFileCanSkipModifyPermissionForNewArticleFlow(): void
    {
        $service = $this->makeService(
            article: $this->article(),
            board: $this->board(),
            permissionExpectations: function (BoardPermissionService $permission): void {
                $permission->expects($this->never())->method('canModify');
            },
        );

        $result = $service->uploadFile(
            10,
            ['name' => 'test.pdf', 'error' => UPLOAD_ERR_NO_FILE],
            $this->context(),
            ['require_modify_permission' => false],
        );

        $this->assertTrue($result->isFailure());
        $this->assertNotSame('파일 첨부 권한이 없습니다.', $result->getMessage());
    }

    public function testGuestUploadAuthorizationIsScopedToExactArticleId(): void
    {
        $service = $this->makeService(
            article: $this->guestArticle(),
            board: $this->board(),
            permissionExpectations: function (BoardPermissionService $permission): void {
                $permission->method('canModify')->willReturn(false);
            },
        );

        $wrongArticle = $service->uploadFile(
            10,
            ['name' => 'test.pdf', 'error' => UPLOAD_ERR_NO_FILE],
            $this->context(),
            ['guest_article_ids' => [999]],
        );
        $ownedArticle = $service->uploadFile(
            10,
            ['name' => 'test.pdf', 'error' => UPLOAD_ERR_NO_FILE],
            $this->context(),
            ['guest_article_ids' => [10]],
        );

        $this->assertSame('파일 첨부 권한이 없습니다.', $wrongArticle->getMessage());
        $this->assertNotSame('파일 첨부 권한이 없습니다.', $ownedArticle->getMessage());
    }

    public function testDeleteFileRequiresDeletePermissionBeforeRemovingFileOrRow(): void
    {
        $article = $this->article();
        $board = $this->board();
        $attachment = $this->attachment();

        $attachmentRepository = $this->createMock(BoardAttachmentRepository::class);
        $attachmentRepository->method('find')->with(50)->willReturn($attachment);
        $attachmentRepository->expects($this->never())->method('delete');

        $fileUploader = $this->createMock(FileUploader::class);
        $fileUploader->expects($this->never())->method('delete');
        $fileUploader->expects($this->never())->method('deleteByFullPath');

        $service = $this->makeService(
            attachmentRepository: $attachmentRepository,
            article: $article,
            board: $board,
            permissionExpectations: function (BoardPermissionService $permission) use ($board, $article): void {
                $permission->expects($this->once())
                    ->method('canDelete')
                    ->with($board, $article, $this->isInstanceOf(Context::class))
                    ->willReturn(false);
            },
            fileUploader: $fileUploader,
        );

        $result = $service->deleteFile(50, $this->context());

        $this->assertTrue($result->isFailure());
        $this->assertSame('파일 삭제 권한이 없습니다.', $result->getMessage());
    }

    public function testDownloadBlocksCrossDomainArticle(): void
    {
        $attachmentRepository = $this->createMock(BoardAttachmentRepository::class);
        $attachmentRepository->method('findWithArticleByPublicId')
            ->with(self::PUBLIC_ID)
            ->willReturn(['attachment' => $this->attachment()]);

        $otherDomainArticle = BoardArticle::fromArray([
            'article_id' => 10,
            'domain_id'  => 2, // 현재 컨텍스트(1)와 다른 도메인
            'board_id'   => 20,
            'member_id'  => 100,
            'title'      => 'Other domain article',
        ]);

        $service = $this->makeService(
            attachmentRepository: $attachmentRepository,
            article: $otherDomainArticle,
            board: $this->board(),
            permissionExpectations: function (BoardPermissionService $permission): void {
                $permission->expects($this->never())->method('canDownload');
            },
        );

        $result = $service->download(self::PUBLIC_ID, $this->context());

        $this->assertTrue($result->isFailure());
        $this->assertSame('권한이 없는 게시글입니다.', $result->getMessage());
    }

    public function testDownloadAllowsCrossDomainArticleOnGlobalBoard(): void
    {
        $attachmentRepository = $this->createMock(BoardAttachmentRepository::class);
        $attachmentRepository->method('findWithArticleByPublicId')
            ->with(self::PUBLIC_ID)
            ->willReturn(['attachment' => $this->attachment()]);

        $service = $this->makeService(
            attachmentRepository: $attachmentRepository,
            article: BoardArticle::fromArray([
                'article_id' => 10,
                'domain_id' => 2,
                'board_id' => 20,
                'member_id' => 100,
                'title' => 'Global article',
            ]),
            board: $this->board(true),
            permissionExpectations: function (BoardPermissionService $permission): void {
                $permission->expects($this->once())->method('canRead')->willReturn(true);
                $permission->expects($this->once())->method('canDownload')->willReturn(true);
            },
        );

        $result = $service->download(self::PUBLIC_ID, $this->context());

        $this->assertTrue($result->isFailure());
        $this->assertNotSame('권한이 없는 게시글입니다.', $result->getMessage());
    }

    public function testDownloadBlocksSecretArticleWithoutReadPermission(): void
    {
        $attachmentRepository = $this->createMock(BoardAttachmentRepository::class);
        $attachmentRepository->method('findWithArticleByPublicId')
            ->with(self::PUBLIC_ID)
            ->willReturn(['attachment' => $this->attachment()]);

        $secretArticle = BoardArticle::fromArray([
            'article_id' => 10,
            'domain_id'  => 1,
            'board_id'   => 20,
            'member_id'  => 100,
            'title'      => 'Secret article',
            'is_secret'  => 1,
        ]);

        $service = $this->makeService(
            attachmentRepository: $attachmentRepository,
            article: $secretArticle,
            board: $this->board(),
            permissionExpectations: function (BoardPermissionService $permission): void {
                $permission->method('canDownload')->willReturn(true);
                $permission->expects($this->once())
                    ->method('canRead')
                    ->willReturn(false);
            },
        );

        $result = $service->download(self::PUBLIC_ID, $this->context());

        $this->assertTrue($result->isFailure());
        $this->assertSame('다운로드 권한이 없습니다.', $result->getMessage());
    }

    public function testGetAttachmentsByArticleHidesStoredNameAndPath(): void
    {
        $attachmentRepository = $this->createMock(BoardAttachmentRepository::class);
        $attachmentRepository->method('findByArticle')
            ->with(10)
            ->willReturn([$this->attachment()]);

        $service = $this->makeService(attachmentRepository: $attachmentRepository);

        $result = $service->getAttachmentsByArticle(10);

        $this->assertCount(1, $result);
        $this->assertArrayNotHasKey('stored_name', $result[0]);
        $this->assertArrayNotHasKey('file_path', $result[0]);
        // thumbnail_path는 이미지 표시용으로 유지
        $this->assertArrayHasKey('thumbnail_path', $result[0]);
        $this->assertSame('test.pdf', $result[0]['original_name']);
        $this->assertSame(50, $result[0]['attachment_id']);
    }

    private function makeService(
        ?BoardAttachmentRepository $attachmentRepository = null,
        ?BoardArticle $article = null,
        ?BoardConfig $board = null,
        ?BoardAttachment $attachment = null,
        ?callable $permissionExpectations = null,
        ?callable $fileUploaderExpectations = null,
        ?FileUploader $fileUploader = null,
    ): BoardFileService {
        $attachmentRepository ??= $this->createMock(BoardAttachmentRepository::class);
        if ($attachment !== null) {
            $attachmentRepository->method('find')->willReturn($attachment);
        }
        $attachmentRepository->method('countByArticle')->willReturn(0);

        $articleRepository = $this->createMock(BoardArticleRepository::class);
        $articleRepository->method('find')->willReturn($article);

        $boardRepository = $this->createMock(BoardConfigRepository::class);
        $boardRepository->method('find')->willReturn($board);

        $permissionService = $this->createMock(BoardPermissionService::class);
        if ($permissionExpectations !== null) {
            $permissionExpectations($permissionService);
        }

        $fileUploader ??= $this->createMock(FileUploader::class);
        if ($fileUploaderExpectations !== null) {
            $fileUploaderExpectations($fileUploader);
        }

        return new BoardFileService(
            $attachmentRepository,
            $this->createMock(BoardLinkRepository::class),
            $articleRepository,
            $boardRepository,
            $this->createMock(MemberQueryInterface::class),
            $permissionService,
            $this->createMock(EventDispatcher::class),
            $this->createMock(AuthService::class),
            $fileUploader,
            $this->createMock(ImageProcessor::class),
        );
    }

    private function context(): Context
    {
        $context = new Context(new Request('POST', '/board/notice/file/upload'));
        $context->setDomainInfo(new Domain(1, 'example.test'));

        return $context;
    }

    private function article(): BoardArticle
    {
        return BoardArticle::fromArray([
            'article_id' => 10,
            'domain_id' => 1,
            'board_id' => 20,
            'member_id' => 100,
            'title' => 'Test article',
        ]);
    }

    private function guestArticle(): BoardArticle
    {
        return BoardArticle::fromArray([
            'article_id' => 10,
            'domain_id' => 1,
            'board_id' => 20,
            'member_id' => null,
            'author_name' => 'Guest',
            'author_password' => password_hash('secret', PASSWORD_DEFAULT),
            'title' => 'Guest article',
        ]);
    }

    private function board(bool $global = false): BoardConfig
    {
        return BoardConfig::fromArray([
            'board_id' => 20,
            'domain_id' => 1,
            'board_slug' => 'notice',
            'board_name' => 'Notice',
            'use_file' => 1,
            'is_global' => $global ? 1 : 0,
        ]);
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
            'file_path' => 'D1/board/2026/06',
            'file_size' => 123,
            'file_extension' => 'pdf',
            'mime_type' => 'application/pdf',
        ]);
    }
}
