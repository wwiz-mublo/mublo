<?php
namespace Mublo\Packages\Board\Plugins\BoardReport\Service;

use Mublo\Core\Result\Result;
use Mublo\Packages\Board\Contract\Extension\BoardExtensionApiInterface;
use Mublo\Packages\Board\Plugins\BoardReport\Repository\BoardReportRepository;

/**
 * BoardReportService
 *
 * 게시글 신고 접수·처리·블라인드.
 *
 * Board 데이터 접근은 부모 패키지의 공개 Extension API만 사용한다.
 * Board 내부 Service, Repository, Entity는 이 플러그인의 계약이 아니다.
 */
class BoardReportService
{
    public const REASONS = [
        'spam'    => '스팸/광고',
        'abuse'   => '욕설/비방',
        'adult'   => '음란물',
        'privacy' => '개인정보 노출',
        'etc'     => '기타',
    ];

    public function __construct(
        private BoardReportRepository $repository,
        private BoardExtensionApiInterface $board
    ) {}

    /**
     * 신고 접수
     */
    public function submit(
        int $domainId,
        int $articleId,
        string $reason,
        string $detail,
        ?int $reporterId,
        ?string $reporterIp
    ): Result {
        if (!isset(self::REASONS[$reason])) {
            return Result::failure('신고 사유를 선택해주세요.');
        }

        $article = $this->board->articles()->findAccessibleById($articleId, $domainId);
        if ($article === null) {
            return Result::failure('게시글을 찾을 수 없습니다.');
        }

        if ($this->repository->hasReported($domainId, $articleId, $reporterId, $reporterIp)) {
            return Result::failure('이미 신고한 게시글입니다.');
        }

        $this->repository->insertReport([
            'domain_id'     => $domainId,
            'article_id'    => $articleId,
            'board_id'      => $article->getBoardId(),
            'article_title' => mb_substr($article->getTitle(), 0, 255),
            'reason'        => $reason,
            'detail'        => mb_substr($detail, 0, 2000),
            'reporter_id'   => $reporterId,
            'reporter_ip'   => $reporterIp,
        ]);

        return Result::success('신고가 접수되었습니다.');
    }

    /** @return array{items: array, total: int} */
    public function paginate(int $domainId, string $status, int $page, int $perPage = 20): array
    {
        $result = $this->repository->paginate($domainId, $status, $page, $perPage);

        foreach ($result['items'] as &$item) {
            $item['reason_label'] = self::REASONS[$item['reason']] ?? $item['reason'];
            $item['is_blinded'] = $this->repository->findBlind($domainId, (int) $item['article_id']) !== null;
            $item['report_count'] = $this->repository->countByArticle($domainId, (int) $item['article_id']);
        }

        return $result;
    }

    public function find(int $domainId, int $reportId): ?array
    {
        return $this->repository->findById($domainId, $reportId);
    }

    public function setStatus(int $domainId, int $reportId, string $status): Result
    {
        if (!in_array($status, ['pending', 'resolved', 'dismissed'], true)) {
            return Result::failure('잘못된 상태입니다.');
        }
        if ($this->repository->findById($domainId, $reportId) === null) {
            return Result::failure('신고를 찾을 수 없습니다.');
        }

        $this->repository->updateStatus($domainId, $reportId, $status);
        return Result::success('처리되었습니다.');
    }

    /**
     * 블라인드 = 방문자에게 글을 숨기는 조치. 조치를 취했으므로 그 글의
     * 대기 신고는 자동으로 "인용"(resolved)이 된다. 해제는 상태를 되돌리지 않는다
     * (조치 이력은 이력대로 남는다).
     */
    public function setBlind(int $domainId, int $articleId, bool $blind, string $reason = ''): Result
    {
        // 현재 도메인 소유 글과 전역 게시판 글만 허용한다. 전역 글의 블라인드는
        // 현재 domainId에 귀속되므로 다른 사이트의 블라인드 상태에는 영향을 주지 않는다.
        $article = $this->board->articles()->findAccessibleById($articleId, $domainId);
        if ($article === null) {
            return Result::failure('게시글을 찾을 수 없습니다.');
        }

        if ($blind) {
            $this->repository->insertBlind(
                $domainId,
                $articleId,
                $reason !== '' ? $reason : '신고 누적으로 블라인드 처리된 게시글입니다.'
            );
            $resolved = $this->repository->resolvePendingByArticle($domainId, $articleId);
            return Result::success(
                '블라인드 처리했습니다.' . ($resolved > 0 ? " (대기 신고 {$resolved}건 인용)" : '')
            );
        }

        $this->repository->deleteBlind($domainId, $articleId);
        return Result::success('블라인드를 해제했습니다.');
    }

    /** 블라인드 여부 (ArticleViewingEvent 게이트) */
    public function getBlindReason(int $domainId, int $articleId): ?string
    {
        $blind = $this->repository->findBlind($domainId, $articleId);
        return $blind !== null ? (string) $blind['reason'] : null;
    }

    /**
     * 글 삭제 시 후처리 (ArticleDeletedEvent)
     *
     * 신고 이력은 지우지 않는다 — 목록에 "삭제된 글"로 남아 감사 추적이
     * 된다. 삭제도 조치이므로 대기 신고는 인용으로 전이하고,
     * 블라인드 표시만 걷어낸다.
     */
    public function cleanupArticle(int $domainId, int $articleId): void
    {
        $this->repository->resolvePendingByArticle($domainId, $articleId);
        $this->repository->deleteBlind($domainId, $articleId);
    }
}
