<?php
namespace Mublo\Plugin\Qna\Service;

use Mublo\Core\Result\Result;
use Mublo\Helper\Editor\EditorHelper;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Plugin\Qna\Entity\QnaPost;
use Mublo\Plugin\Qna\Enum\AuthorType;
use Mublo\Plugin\Qna\Enum\InquiryStatus;
use Mublo\Plugin\Qna\Repository\QnaConfigRepository;
use Mublo\Plugin\Qna\Repository\QnaPostRepository;

/**
 * QnaService
 *
 * 질문과 답변 비즈니스 로직. 질문/답변은 qna_posts 단일 테이블에 저장된다
 * (parent_id 로 구분). 비밀글 열람 게이트는 이 계층에서 강제한다.
 */
class QnaService
{
    public function __construct(
        private QnaPostRepository     $postRepo,
        private QnaConfigRepository   $configRepo,
        private QnaAttachmentService  $attachments,
    ) {
    }

    // ─────────────────────────────────────────
    // 첨부 (임시 업로드)
    // ─────────────────────────────────────────

    /**
     * 첨부 임시 업로드. 도메인 정책(허용 확장자·용량)을 적용한다.
     *
     * @return Result data: {temp_path, filename, size, extension, mime_type}
     */
    public function uploadTempAttachment(int $domainId, UploadedFile $file): Result
    {
        if (!$file->isValid()) {
            return Result::failure($file->getErrorMessage() ?: '파일이 업로드되지 않았습니다.');
        }

        $config = $this->configRepo->getConfig($domainId);
        $result = $this->attachments->uploadTemp($file, $domainId, [
            'max_mb'      => (int) $config['file_max_mb'],
            'allowed_ext' => (string) $config['file_allowed_ext'],
        ]);

        if (!$result->isSuccess()) {
            return Result::failure($result->getMessage());
        }

        return Result::success('파일이 업로드되었습니다.', $this->attachments->tempResponse($result));
    }

    // ─────────────────────────────────────────
    // 프론트 (문의자)
    // ─────────────────────────────────────────

    /** 내 질문 목록 */
    public function getMyInquiries(int $domainId, int $memberId, int $page, int $perPage): array
    {
        return $this->postRepo->findQuestionsByMember($domainId, $memberId, $page, $perPage);
    }

    /**
     * 질문 단건 열람 (비밀글 게이트 적용).
     *
     * @return Result data: {inquiry: array, replies: array}
     */
    public function viewInquiry(int $domainId, int $questionId, ?int $viewerId, bool $isAdmin): Result
    {
        $row = $this->postRepo->findQuestion($questionId, $domainId);
        if (!$row) {
            return Result::failure('문의를 찾을 수 없습니다.');
        }

        $question = QnaPost::fromArray($row);
        if (!$question->canBeViewedBy($viewerId, $isAdmin)) {
            return Result::failure('열람 권한이 없습니다.');
        }

        // 운영자만 내부 비노출 메모까지 조회
        $replies = $this->postRepo->findAnswers($questionId, $domainId, includePrivate: $isAdmin);

        if (!$isAdmin) {
            $this->postRepo->incrementView($questionId, $domainId);
        }

        // 열람이 허용된 뒤에만 첨부 다운로드 URL(서명 토큰)을 발급한다.
        $row['attachments'] = $this->attachments->withDownloadUrls($row['meta'] ?? null);
        foreach ($replies as &$reply) {
            $reply['attachments'] = $this->attachments->withDownloadUrls($reply['meta'] ?? null);
        }
        unset($reply);

        return Result::success('', [
            'inquiry' => $row,
            'replies' => $replies,
        ]);
    }

    /**
     * 신규 질문 등록.
     *
     * @param array $data title, content, category_id?, author_*?
     */
    public function createInquiry(int $domainId, int $memberId, array $data, string $ip = ''): Result
    {
        $config = $this->configRepo->getConfig($domainId);

        $title   = trim($data['title'] ?? '');
        $content = trim($data['content'] ?? '');

        if ($title === '') {
            return Result::failure('제목을 입력해 주세요.');
        }
        if ($content === '') {
            return Result::failure('문의 내용을 입력해 주세요.');
        }

        // 프론트 문의는 무조건 비밀글
        $isSecret = 1;

        $questionId = $this->postRepo->insert([
            'domain_id'    => $domainId,
            'parent_id'    => null,               // 질문(스레드 헤드)
            'category_id'  => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'member_id'    => $memberId,
            'author_type'  => AuthorType::Member->value,
            'author_name'  => $data['author_name'] ?? null,
            'author_email' => $data['author_email'] ?? null,
            'author_phone' => $data['author_phone'] ?? null,
            'title'        => $title,
            'content'      => $content,
            'is_secret'    => $isSecret,
            'status'       => InquiryStatus::Open->value,
            'ip_address'   => $ip,
        ]);

        if (!$questionId) {
            return Result::failure('문의 등록에 실패했습니다.');
        }

        // 에디터 임시 이미지 → 최종 경로 이동
        $processed = EditorHelper::processImages($content, 'qna/' . $questionId);
        if ($processed !== $content) {
            $this->postRepo->update($questionId, $domainId, ['content' => $processed]);
        }

        // 첨부 파일: 임시 → 최종 이동 후 meta 에 기록 (entityId = 질문 헤드)
        $this->storeAttachments($domainId, $questionId, $questionId, $data['attachments'] ?? [], (int) $config['allow_file'], (int) $config['file_max_count']);

        return Result::success('문의가 등록되었습니다.', ['inquiry_id' => $questionId]);
    }

    // ─────────────────────────────────────────
    // 답변 스레드 (문의자·운영자 공용)
    // ─────────────────────────────────────────

    /**
     * 답변/추가문의 작성. 문의자 추가글과 운영자 답변을 모두 처리한다.
     * 작성 후 질문 상태와 스레드 집계를 갱신한다.
     */
    public function addReply(
        int $domainId,
        int $questionId,
        AuthorType $authorType,
        ?int $authorId,
        array $data,
        string $ip = ''
    ): Result {
        $question = $this->postRepo->findQuestion($questionId, $domainId);
        if (!$question) {
            return Result::failure('문의를 찾을 수 없습니다.');
        }

        // 문의자는 본인 문의에만 글을 달 수 있다.
        if ($authorType === AuthorType::Member
            && (int) $question['member_id'] !== (int) $authorId) {
            return Result::failure('권한이 없습니다.');
        }

        $content = trim($data['content'] ?? '');
        if ($content === '') {
            return Result::failure('내용을 입력해 주세요.');
        }

        // 내부 비노출 메모는 운영자만
        $isPrivate = $authorType === AuthorType::Admin
            ? (int) (bool) ($data['is_private'] ?? 0)
            : 0;

        $replyId = $this->postRepo->insert([
            'domain_id'   => $domainId,
            'parent_id'   => $questionId,          // 이 질문에 달린 답변
            'member_id'   => $authorId ?? 0,
            'author_type' => $authorType->value,
            'author_name' => $data['author_name'] ?? null,
            'content'     => $content,
            'is_private'  => $isPrivate,
            'ip_address'  => $ip,
        ]);

        if (!$replyId) {
            return Result::failure('등록에 실패했습니다.');
        }

        $processed = EditorHelper::processImages($content, 'qna/' . $questionId);
        if ($processed !== $content) {
            $this->postRepo->update($replyId, $domainId, ['content' => $processed]);
        }

        // 첨부: 스레드 헤드(questionId) 폴더에 저장하되 meta 는 이 답변 행($replyId)에 기록.
        // 첨부 정책은 전역 config 를 따르며, 운영자 답변은 allow_file 과 무관하게 허용.
        $config    = $this->configRepo->getConfig($domainId);
        $allowFile = $authorType === AuthorType::Admin ? 1 : (int) $config['allow_file'];
        $this->storeAttachments($domainId, $replyId, $questionId, $data['attachments'] ?? [], $allowFile, (int) $config['file_max_count']);

        // 상태 전이: 운영자 답변 → answered / 문의자 재질문 → open
        $statusUpdate = [];
        if ($authorType === AuthorType::Admin && $isPrivate === 0) {
            $statusUpdate['status'] = InquiryStatus::Answered->value;
            if (empty($question['answered_at'])) {
                $statusUpdate['answered_at'] = date('Y-m-d H:i:s');
            }
        } elseif ($authorType === AuthorType::Member) {
            $statusUpdate['status'] = InquiryStatus::Open->value;
        }
        if ($statusUpdate) {
            $this->postRepo->update($questionId, $domainId, $statusUpdate);
        }

        $this->postRepo->refreshThreadStats($questionId, $domainId);

        return Result::success('등록되었습니다.', ['reply_id' => $replyId]);
    }

    // ─────────────────────────────────────────
    // 운영자
    // ─────────────────────────────────────────

    /**
     * 관리자 질문 목록 + 페이지네이션.
     *
     * @return array{items: array, pagination: array{totalItems:int, perPage:int, currentPage:int, totalPages:int}}
     */
    public function getInquiriesForAdmin(
        int $domainId,
        int $page,
        int $perPage,
        string $status = '',
        string $keyword = ''
    ): array {
        $result     = $this->postRepo->findQuestionsForAdmin($domainId, $page, $perPage, $status, $keyword);
        $totalPages = max(1, (int) ceil($result['total'] / max(1, $perPage)));

        return [
            'items'      => $result['items'],
            'pagination' => [
                'totalItems'  => $result['total'],
                'perPage'     => $perPage,
                'currentPage' => $page,
                'totalPages'  => $totalPages,
            ],
        ];
    }

    /**
     * 상태 일괄 변경.
     *
     * @param array<int|string, array{status?: string}> $items {post_id: {status}}
     */
    public function batchUpdateStatus(int $domainId, array $items): Result
    {
        $updated = 0;
        foreach ($items as $postId => $fields) {
            $postId = (int) $postId;
            $enum   = InquiryStatus::tryFrom((string) ($fields['status'] ?? ''));
            if ($postId <= 0 || $enum === null) {
                continue;
            }
            if (!$this->postRepo->findQuestion($postId, $domainId)) {
                continue;
            }

            $update = ['status' => $enum->value];
            if ($enum === InquiryStatus::Closed) {
                $update['closed_at'] = date('Y-m-d H:i:s');
            }
            $this->postRepo->update($postId, $domainId, $update);
            $updated++;
        }

        return Result::success("{$updated}건의 상태를 변경했습니다.", ['updated' => $updated]);
    }

    /**
     * 질문 일괄 삭제 (답변은 FK CASCADE 로 함께 삭제).
     *
     * @param array<int|string> $ids
     */
    public function batchDelete(int $domainId, array $ids): Result
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($v) => $v > 0));
        if (!$ids) {
            return Result::failure('삭제할 항목이 없습니다.');
        }

        // 보안 첨부 파일은 DB CASCADE 대상이 아니므로 스레드별로 먼저 정리한다.
        foreach ($ids as $questionId) {
            $this->purgeThreadFiles($domainId, $questionId);
        }

        $count = $this->postRepo->deleteManyQuestions($ids, $domainId);

        return Result::success("{$count}건을 삭제했습니다.", ['deleted' => $count]);
    }

    public function updateStatus(int $domainId, int $questionId, string $status): Result
    {
        $enum = InquiryStatus::tryFrom($status);
        if ($enum === null) {
            return Result::failure('알 수 없는 상태입니다.');
        }
        if (!$this->postRepo->findQuestion($questionId, $domainId)) {
            return Result::failure('문의를 찾을 수 없습니다.');
        }

        $update = ['status' => $enum->value];
        if ($enum === InquiryStatus::Closed) {
            $update['closed_at'] = date('Y-m-d H:i:s');
        }
        $this->postRepo->update($questionId, $domainId, $update);

        return Result::success('상태가 변경되었습니다.');
    }

    public function deleteInquiry(int $domainId, int $questionId): Result
    {
        if (!$this->postRepo->findQuestion($questionId, $domainId)) {
            return Result::failure('문의를 찾을 수 없습니다.');
        }
        // 답변은 FK ON DELETE CASCADE 로 함께 삭제되지만, 보안 첨부 파일은
        // DB 밖(storage/files/)이라 삭제 전에 디스크에서 직접 정리한다.
        $this->purgeThreadFiles($domainId, $questionId);
        $this->postRepo->delete($questionId, $domainId);

        return Result::success('문의가 삭제되었습니다.');
    }

    /** 문의자 본인 삭제 — 소유자 검증 후 스레드 전체(답변·첨부 포함) 삭제. */
    public function deleteOwnInquiry(int $domainId, int $questionId, int $memberId): Result
    {
        $question = $this->postRepo->findQuestion($questionId, $domainId);
        if (!$question) {
            return Result::failure('문의를 찾을 수 없습니다.');
        }
        if ((int) $question['member_id'] !== $memberId) {
            return Result::failure('권한이 없습니다.');
        }

        return $this->deleteInquiry($domainId, $questionId);
    }

    /** 질문 스레드(질문+답변)의 보안 첨부 파일을 디스크에서 제거. */
    private function purgeThreadFiles(int $domainId, int $questionId): void
    {
        $question = $this->postRepo->findQuestion($questionId, $domainId);
        if ($question) {
            $this->attachments->deletePostFiles($question['meta'] ?? null);
        }
        foreach ($this->postRepo->findAnswers($questionId, $domainId, includePrivate: true) as $reply) {
            $this->attachments->deletePostFiles($reply['meta'] ?? null);
        }
    }

    /**
     * 임시 첨부 목록을 최종 이동하고 대상 글의 meta 에 기록한다.
     *
     * @param int   $postId     첨부 메타를 기록할 글(질문 또는 답변) post_id
     * @param int   $questionId 저장 폴더/권한 단위가 되는 스레드 헤드 post_id
     * @param mixed $tempList   클라이언트가 보낸 임시 첨부 배열
     */
    private function storeAttachments(int $domainId, int $postId, int $questionId, mixed $tempList, int $allowFile, int $maxCount): void
    {
        if ($allowFile !== 1 || !is_array($tempList) || $tempList === []) {
            return;
        }

        $metas = $this->attachments->finalize($tempList, $domainId, $questionId, $maxCount);
        if ($metas === []) {
            return;
        }

        $current = $this->postRepo->findPost($postId, $domainId);
        $metaJson = $this->attachments->buildMetaColumn($metas, $current['meta'] ?? null);
        $this->postRepo->update($postId, $domainId, ['meta' => $metaJson]);
    }

    public function getConfig(int $domainId): array
    {
        return $this->configRepo->getConfig($domainId);
    }
}
