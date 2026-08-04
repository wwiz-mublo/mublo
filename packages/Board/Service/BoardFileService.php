<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Service;

use Mublo\Packages\Board\Entity\BoardAttachment;
use Mublo\Packages\Board\Entity\BoardLink;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Repository\BoardAttachmentRepository;
use Mublo\Packages\Board\Repository\BoardLinkRepository;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Contract\Member\MemberIdentity;
use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Core\Context\Context;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Packages\Board\Event\FileUploadedEvent;
use Mublo\Packages\Board\Event\FileDownloadingEvent;
use Mublo\Packages\Board\Event\FileDownloadedEvent;
use Mublo\Packages\Board\Helper\BoardLinkUrlPolicy;
use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Infrastructure\Image\ImageProcessor;

/**
 * BoardFileService
 *
 * 첨부파일/링크 비즈니스 로직 + 이벤트 발행
 *
 * Infrastructure 활용:
 * - FileUploader: 파일 업로드/삭제
 * - ImageProcessor: 썸네일 생성
 */
class BoardFileService
{
    private BoardAttachmentRepository $attachmentRepository;
    private BoardLinkRepository $linkRepository;
    private BoardArticleRepository $articleRepository;
    private BoardConfigRepository $boardRepository;
    private MemberQueryInterface $memberRepository;
    private BoardPermissionService $permissionService;
    private ?EventDispatcher $eventDispatcher;
    private AuthContextInterface $authService;
    private FileUploader $fileUploader;
    private ImageProcessor $imageProcessor;

    private const SUBDIRECTORY = 'board';
    private const THUMBNAIL_SIZE = 200;

    public function __construct(
        BoardAttachmentRepository $attachmentRepository,
        BoardLinkRepository $linkRepository,
        BoardArticleRepository $articleRepository,
        BoardConfigRepository $boardRepository,
        MemberQueryInterface $memberRepository,
        BoardPermissionService $permissionService,
        ?EventDispatcher $eventDispatcher,
        AuthContextInterface $authService,
        FileUploader $fileUploader,
        ImageProcessor $imageProcessor
    ) {
        $this->attachmentRepository = $attachmentRepository;
        $this->linkRepository = $linkRepository;
        $this->articleRepository = $articleRepository;
        $this->boardRepository = $boardRepository;
        $this->memberRepository = $memberRepository;
        $this->permissionService = $permissionService;
        $this->eventDispatcher = $eventDispatcher;
        $this->authService = $authService;
        $this->fileUploader = $fileUploader;
        $this->imageProcessor = $imageProcessor;
    }

    /**
     * 현재 로그인 사용자 ID 조회
     */
    private function getCurrentUserId(): ?int
    {
        return $this->authService->id();
    }

    /**
     * 이벤트 발행 헬퍼
     *
     * @template T of EventInterface
     * @param T $event
     * @return T
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    // === Attachment Methods ===

    /**
     * 파일 업로드
     *
     * @param int $articleId 게시글 ID
     * @param array|UploadedFile $file $_FILES 배열 또는 UploadedFile 객체
     * @param Context $context 컨텍스트
     * @param array $options 옵션 [
     *   'require_modify_permission' => true, // 기존 글 첨부는 작성자/관리자만 허용
     * ]
     * @return Result
     */
    public function uploadFile(
        int $articleId,
        array|UploadedFile $file,
        Context $context,
        array $options = []
    ): Result {
        // 게시글 조회
        $article = $this->articleRepository->find($articleId);
        if (!$article) {
            return Result::failure('게시글을 찾을 수 없습니다.');
        }

        // 게시판 조회
        $board = $this->boardRepository->find($article->getBoardId());
        if (!$board) {
            return Result::failure('게시판을 찾을 수 없습니다.');
        }

        if (!$this->isDomainAccessible($article, $board, $context)) {
            return Result::failure('권한이 없는 게시글입니다.');
        }

        $requireModifyPermission = $options['require_modify_permission'] ?? true;
        $guestArticleIds = $options['guest_article_ids'] ?? [];
        if (
            $requireModifyPermission
            && !$this->canManageArticle($board, $article, $context, $guestArticleIds, false)
        ) {
            return Result::failure('파일 첨부 권한이 없습니다.');
        }

        // 파일 기능 체크
        if (!$board->isUseFile()) {
            return Result::failure('파일 첨부가 허용되지 않습니다.');
        }

        // 파일 개수 제한 체크
        $currentCount = $this->attachmentRepository->countByArticle($articleId);
        if ($currentCount >= $board->getFileCountLimit()) {
            return Result::failure('파일 첨부 개수를 초과했습니다.');
        }

        // UploadedFile 객체로 변환 (배열인 경우)
        $uploadedFile = ($file instanceof UploadedFile) ? $file : new UploadedFile($file);

        // 파일 유효성 검사
        if (!$uploadedFile->isValid()) {
            return Result::failure($uploadedFile->getErrorMessage());
        }

        // 허용 확장자 목록 (코어 UploadPolicy가 svg 등 위험 타입을 최종 차단·캡)
        $allowedExtensions = array_map('trim', explode(',', $board->getFileExtensionAllowed()));

        // FileUploader로 업로드
        $domainId = $article->getDomainId();
        $uploadResult = $this->fileUploader->upload($uploadedFile, $domainId, [
            'subdirectory' => self::SUBDIRECTORY,
            'allowed_extensions' => $allowedExtensions,
            'max_size' => $board->getFileSizeLimit(),
        ]);

        if ($uploadResult->isFailure()) {
            return Result::failure($uploadResult->getMessage());
        }

        // 썸네일 생성 (이미지인 경우)
        $thumbnailPath = null;
        if ($uploadResult->isImage()) {
            $thumbnailPath = $this->createThumbnail(
                $uploadResult->getFullPath(),
                $uploadResult->getRelativePath(),
                $uploadResult->getStoredName()
            );
        }

        // DB 저장
        $insertData = [
            'domain_id' => $domainId,
            'board_id' => $article->getBoardId(),
            'article_id' => $articleId,
            // 다운로드 URL 에 실리는 식별자. attachment_id 를 쓰면 전체 첨부 건수와
            // 증가 속도가 URL 만으로 드러나므로, 순번과 무관한 난수를 따로 둔다.
            'public_id' => $this->generatePublicId(),
            'original_name' => $uploadResult->getOriginalName(),
            'stored_name' => $uploadResult->getStoredName(),
            'file_path' => $uploadResult->getRelativePath(),
            'file_size' => $uploadResult->getSize(),
            'file_extension' => $uploadResult->getExtension(),
            'mime_type' => $uploadResult->getMimeType(),
            'is_image' => $uploadResult->isImage() ? 1 : 0,
            'image_width' => $uploadResult->getImageWidth(),
            'image_height' => $uploadResult->getImageHeight(),
            'thumbnail_path' => $thumbnailPath,
        ];

        $attachmentId = $this->attachmentRepository->create($insertData);
        if (!$attachmentId) {
            // 실패 시 업로드된 파일 삭제
            $this->fileUploader->delete($uploadResult->getRelativePath(), $uploadResult->getStoredName());
            if ($thumbnailPath) {
                $this->fileUploader->deleteByFullPath($this->getThumbnailFullPath($thumbnailPath));
            }
            return Result::failure('파일 정보 저장에 실패했습니다.');
        }

        // 이벤트 발행
        $attachment = $this->attachmentRepository->find($attachmentId);
        $memberId = $this->getCurrentUserId();
        $this->dispatch(new FileUploadedEvent($attachment, $memberId));

        return Result::success('파일이 업로드되었습니다.', ['attachment' => $attachment->toArray()]);
    }

    /**
     * 썸네일 생성
     *
     * @param string $sourcePath 원본 파일 전체 경로
     * @param string $relativePath 상대 경로
     * @param string $storedName 저장된 파일명
     * @return string|null 썸네일 상대 경로 (실패 시 null)
     */
    private function createThumbnail(string $sourcePath, string $relativePath, string $storedName): ?string
    {
        $thumbnailName = 'thumb_' . $storedName;
        $thumbnailRelativePath = $relativePath . '/' . $thumbnailName;
        $thumbnailFullPath = $this->getThumbnailFullPath($thumbnailRelativePath);

        $success = $this->imageProcessor->thumbnail(
            $sourcePath,
            $thumbnailFullPath,
            self::THUMBNAIL_SIZE
        );

        return $success ? $thumbnailRelativePath : null;
    }

    /**
     * 썸네일 전체 경로 반환
     */
    private function getThumbnailFullPath(string $relativePath): string
    {
        $basePath = defined('MUBLO_PUBLIC_STORAGE_PATH') ? MUBLO_PUBLIC_STORAGE_PATH : 'public/storage';
        return $basePath . '/' . $relativePath;
    }

    /**
     * 파일 다운로드 처리
     */
    public function download(string $publicId, Context $context): Result
    {
        // 첨부파일 조회 — 공개 식별자로만 받는다. attachment_id 를 URL 에 실으면
        // 전체 첨부 건수와 증가 속도가 드러난다.
        $attachmentData = $this->attachmentRepository->findWithArticleByPublicId($publicId);
        if (!$attachmentData) {
            return Result::failure('파일을 찾을 수 없습니다.');
        }

        $attachment = $attachmentData['attachment'];

        // 게시글 조회
        $article = $this->articleRepository->find($attachment->getArticleId());
        if (!$article) {
            return Result::failure('게시글을 찾을 수 없습니다.');
        }

        // 게시판 조회
        $board = $this->boardRepository->find($article->getBoardId());
        if (!$board) {
            return Result::failure('게시판을 찾을 수 없습니다.');
        }

        if (!$this->isDomainAccessible($article, $board, $context)) {
            return Result::failure('권한이 없는 게시글입니다.');
        }

        // 읽기 권한 필수: 글을 읽을 수 없으면 첨부도 받을 수 없다.
        // canDownload(다운로드 레벨)만 확인하면 read_level > download_level 인 게시판/글의
        // 첨부를, 글을 읽지도 못하는(또는 목록에도 안 뜨는) 유저가 받는 우회가 생긴다.
        // 비밀글뿐 아니라 모든 글에 적용한다.
        if (!$this->permissionService->canRead($board, $article, $context)) {
            return Result::failure('다운로드 권한이 없습니다.');
        }

        // 다운로드 레벨 체크
        if (!$this->permissionService->canDownload($board, $article, $context)) {
            return Result::failure('다운로드 권한이 없습니다.');
        }

        // 파일 존재 확인
        $fullPath = $this->fileUploader->getFullPath(
            $attachment->getFilePath(),
            $attachment->getStoredName()
        );

        if (!file_exists($fullPath)) {
            return Result::failure('파일이 존재하지 않습니다.');
        }

        // 다운로드 차단 pre-event (포인트 소비 등)
        $memberId = $this->getCurrentUserId();
        $clientIp = $context->getRequest()->getClientIp();

        $downloadingEvent = $this->dispatch(new FileDownloadingEvent(
            $attachment,
            $memberId,
            $clientIp,
            $context->getDomainId()
        ));
        if ($downloadingEvent->isBlocked()) {
            return Result::failure($downloadingEvent->getBlockReason() ?? '파일을 다운로드할 수 없습니다.');
        }

        // 이벤트 발행
        $downloader = $memberId ? $this->identity($this->memberRepository->findProfile($memberId)) : null;
        $this->dispatch(new FileDownloadedEvent($attachment, $downloader, $clientIp));

        // 다운로드 수 증가 — 집계는 내부 PK 로 한다
        $this->attachmentRepository->incrementDownloadCount($attachment->getAttachmentId());

        return Result::success('', [
            'file_path' => $fullPath,
            'original_name' => $attachment->getOriginalName(),
            'mime_type' => $attachment->getMimeType(),
        ]);
    }

    /**
     * 파일 삭제
     */
    public function deleteFile(int $attachmentId, Context $context, array $guestArticleIds = []): Result
    {
        // 첨부파일 조회
        $attachment = $this->attachmentRepository->find($attachmentId);
        if (!$attachment) {
            return Result::failure('파일을 찾을 수 없습니다.');
        }

        $article = $this->articleRepository->find($attachment->getArticleId());
        if (!$article) {
            return Result::failure('게시글을 찾을 수 없습니다.');
        }

        $board = $this->boardRepository->find($article->getBoardId());
        if (!$board) {
            return Result::failure('게시판을 찾을 수 없습니다.');
        }

        if (!$this->isDomainAccessible($article, $board, $context)) {
            return Result::failure('권한이 없는 게시글입니다.');
        }

        if (!$this->canManageArticle($board, $article, $context, $guestArticleIds, true)) {
            return Result::failure('파일 삭제 권한이 없습니다.');
        }

        // 실제 파일 삭제
        $this->fileUploader->delete($attachment->getFilePath(), $attachment->getStoredName());

        // 썸네일 삭제
        if ($attachment->hasThumbnail()) {
            $this->fileUploader->deleteByFullPath(
                $this->getThumbnailFullPath($attachment->getThumbnailPath())
            );
        }

        // DB 삭제
        $this->attachmentRepository->delete($attachmentId);

        return Result::success('파일이 삭제되었습니다.');
    }

    /**
     * 글의 도메인이 현재 요청 도메인과 같은지 확인 (크로스도메인 IDOR 차단)
     *
     * 로그인은 도메인별로 격리되므로(attempt()가 domain_id로 회원 조회) 정상 사용자/관리자는
     * 항상 자기 도메인 글만 다룬다. 이 체크는 파라미터 위조로 들어오는 크로스도메인 접근만 거른다.
     *
     * 도메인 미해석(null) 시에는 허용(fail-open) — 프론트 HTTP 요청은 항상 도메인이 잡히고,
     * CLI/내부 호출 등 도메인 없는 컨텍스트를 막지 않기 위함.
     */
    private function isDomainAccessible(
        BoardArticle $article,
        BoardConfig $board,
        Context $context
    ): bool
    {
        $currentDomainId = $context->getDomainId();
        return $currentDomainId === null
            || $board->isGlobal()
            || ($article->getDomainId() === $currentDomainId && $board->getDomainId() === $currentDomainId);
    }

    /**
     * 게시글별 첨부파일 목록 조회
     */
    public function getAttachmentsByArticle(int $articleId): array
    {
        $attachments = $this->attachmentRepository->findByArticle($articleId);
        return array_map(fn($a) => $this->toSafeAttachmentArray($a), $attachments);
    }

    /**
     * 첨부 엔티티를 스킨 노출용 배열로 변환.
     *
     * 저장 해시(stored_name)·내부 경로(file_path)는 노출하지 않는다. 다운로드는
     * public_id 기반 게이트 엔드포인트로만 하고, 스킨은 이 필드들이 필요 없다.
     */
    private function toSafeAttachmentArray(BoardAttachment $attachment): array
    {
        return array_intersect_key($attachment->toArray(), array_flip([
            'attachment_id', 'public_id', 'original_name', 'file_size', 'file_extension', 'mime_type',
            'is_image', 'image_width', 'image_height', 'thumbnail_path',
            'download_count', 'created_at',
        ]));
    }

    /**
     * 게시글별 이미지 첨부파일 목록 조회
     */
    public function getImagesByArticle(int $articleId): array
    {
        $images = $this->attachmentRepository->findImagesByArticle($articleId);
        return array_map(fn($i) => $i->toArray(), $images);
    }

    /**
     * 첨부파일 URL 반환
     */
    public function getFileUrl(BoardAttachment $attachment): string
    {
        return $this->fileUploader->getUrl($attachment->getFilePath(), $attachment->getStoredName());
    }

    /**
     * 썸네일 URL 반환
     */
    public function getThumbnailUrl(BoardAttachment $attachment): ?string
    {
        if (!$attachment->hasThumbnail()) {
            return null;
        }
        return '/storage/' . $attachment->getThumbnailPath();
    }

    // === Link Methods ===

    /**
     * 링크 추가
     */
    public function addLink(
        int $articleId,
        array $data,
        Context $context,
        array $guestArticleIds = []
    ): Result
    {
        // 게시글 조회
        $article = $this->articleRepository->find($articleId);
        if (!$article) {
            return Result::failure('게시글을 찾을 수 없습니다.');
        }

        // 게시판 조회
        $board = $this->boardRepository->find($article->getBoardId());
        if (!$board) {
            return Result::failure('게시판을 찾을 수 없습니다.');
        }

        // 도메인 경계 + 수정 권한 검증 (파일 첨부와 동일 정책) — 없으면 아무나 남의 글에 링크 첨부(IDOR)
        if (!$this->isDomainAccessible($article, $board, $context)) {
            return Result::failure('권한이 없는 게시글입니다.');
        }
        if (!$this->canManageArticle($board, $article, $context, $guestArticleIds, false)) {
            return Result::failure('링크 추가 권한이 없습니다.');
        }

        // 링크 기능 체크
        if (!$board->isUseLink()) {
            return Result::failure('링크 기능이 허용되지 않습니다.');
        }

        // 저장 경계에서 외부 웹 링크(http/https)만 허용한다.
        $url = BoardLinkUrlPolicy::normalize($data['link_url'] ?? null);
        if ($url === null) {
            return Result::failure('http 또는 https URL을 입력해주세요.');
        }

        $linkTitle = $data['link_title'] ?? null;
        if ($linkTitle !== null) {
            if (!is_string($linkTitle)) {
                return Result::failure('링크 제목을 올바른 형식으로 입력해주세요.');
            }
            $linkTitle = trim(strip_tags($linkTitle));
            if (mb_strlen($linkTitle, 'UTF-8') > 255) {
                return Result::failure('링크 제목은 255자 이하로 입력해주세요.');
            }
            $linkTitle = $linkTitle !== '' ? $linkTitle : null;
        }

        $linkDescription = $data['link_description'] ?? null;
        if ($linkDescription !== null && (!is_string($linkDescription) || strlen($linkDescription) > 65535)) {
            return Result::failure('링크 설명이 너무 길거나 올바른 형식이 아닙니다.');
        }

        $linkImage = $data['link_image'] ?? null;
        if ($linkImage !== null && (!is_string($linkImage) || strlen($linkImage) > 500)) {
            return Result::failure('링크 이미지 경로가 너무 길거나 올바른 형식이 아닙니다.');
        }

        // 중복 체크
        if ($this->linkRepository->existsByUrl($articleId, $url)) {
            return Result::failure('이미 등록된 링크입니다.');
        }

        // DB 저장
        $insertData = [
            'domain_id' => $article->getDomainId(),
            'board_id' => $article->getBoardId(),
            'article_id' => $articleId,
            'link_url' => $url,
            'link_title' => $linkTitle,
            'link_description' => $linkDescription,
            'link_image' => $linkImage,
        ];

        $linkId = $this->linkRepository->create($insertData);
        if (!$linkId) {
            return Result::failure('링크 저장에 실패했습니다.');
        }

        $link = $this->linkRepository->find($linkId);

        return Result::success('링크가 추가되었습니다.', ['link' => $link->toArray()]);
    }

    /**
     * 링크 삭제
     */
    public function deleteLink(int $linkId, Context $context, array $guestArticleIds = []): Result
    {
        $link = $this->linkRepository->find($linkId);
        if (!$link) {
            return Result::failure('링크를 찾을 수 없습니다.');
        }

        // 소유권/도메인/권한 검증 — 링크가 속한 게시글 기준. 없으면 아무나 남의 링크를
        // id 추측으로 삭제(IDOR). 파일 삭제와 동일 정책(canModify)을 적용한다.
        $article = $this->articleRepository->find($link->getArticleId());
        $board = $article ? $this->boardRepository->find($article->getBoardId()) : null;
        if (!$article || !$board
            || !$this->isDomainAccessible($article, $board, $context)
            || !$this->canManageArticle($board, $article, $context, $guestArticleIds, false)) {
            return Result::failure('링크 삭제 권한이 없습니다.');
        }

        $this->linkRepository->delete($linkId);

        return Result::success('링크가 삭제되었습니다.');
    }

    private function canManageArticle(
        BoardConfig $board,
        BoardArticle $article,
        Context $context,
        array $guestArticleIds,
        bool $delete
    ): bool {
        $permitted = $delete
            ? $this->permissionService->canDelete($board, $article, $context)
            : $this->permissionService->canModify($board, $article, $context);

        if ($permitted) {
            return true;
        }

        $ids = array_map('intval', $guestArticleIds);
        return !$article->isMemberArticle()
            && in_array($article->getArticleId(), $ids, true);
    }

    /**
     * 링크 클릭 처리
     */
    public function clickLink(int $linkId): Result
    {
        $link = $this->linkRepository->find($linkId);
        if (!$link) {
            return Result::failure('링크를 찾을 수 없습니다.');
        }

        $url = BoardLinkUrlPolicy::normalize($link->getLinkUrl());
        if ($url === null) {
            return Result::failure('안전하지 않은 링크입니다.');
        }

        $this->linkRepository->incrementClickCount($linkId);

        return Result::success('', ['url' => $url]);
    }

    /**
     * 게시글별 링크 목록 조회
     */
    public function getLinksByArticle(int $articleId): array
    {
        $links = $this->linkRepository->findByArticle($articleId);
        $allowed = array_flip([
            'link_id', 'link_url', 'link_title', 'link_description', 'link_image',
            'click_count', 'created_at',
        ]);

        $safeLinks = [];
        foreach ($links as $link) {
            $data = $link->toArray();
            $url = BoardLinkUrlPolicy::normalize($data['link_url'] ?? null);
            if ($url === null) {
                continue;
            }
            $data['link_url'] = $url;
            $safeLinks[] = array_intersect_key($data, $allowed);
        }

        return $safeLinks;
    }

    /**
     * OG 정보 업데이트
     */
    public function updateLinkOgInfo(int $linkId, ?string $title, ?string $description, ?string $image): bool
    {
        return $this->linkRepository->updateOgInfo($linkId, $title, $description, $image);
    }

    // === Statistics ===

    /**
     * 게시판별 파일 통계
     */
    public function getFileStatsByBoard(int $boardId): array
    {
        return $this->attachmentRepository->getStatsByBoard($boardId);
    }

    /**
     * 도메인별 총 파일 크기 (DB 기준)
     */
    public function getTotalSizeByDomain(int $domainId): int
    {
        return $this->attachmentRepository->getTotalSizeByDomain($domainId);
    }

    /**
     * 도메인별 실제 디스크 사용량
     */
    public function getDiskUsageByDomain(int $domainId): int
    {
        return $this->fileUploader->getDomainUsage($domainId);
    }

    private function identity(?MemberProfile $profile): ?MemberIdentity
    {
        return $profile === null ? null : new MemberIdentity(
            $profile->memberId,
            $profile->domainId,
            $profile->userId,
            $profile->displayName()
        );
    }

    /**
     * 다운로드 URL 에 노출할 공개 식별자.
     *
     * hex 22자 = 88bit. 순번을 감추는 것이 목적이고 권한 검사는 그대로 유지되므로
     * 이 값 자체가 비밀은 아니지만, 열거로 존재를 훑을 수 없을 만큼은 넓어야 한다.
     */
    private function generatePublicId(): string
    {
        return substr(bin2hex(random_bytes(16)), 0, 22);
    }
}
