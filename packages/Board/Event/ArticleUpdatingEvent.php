<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;
use Mublo\Packages\Board\Entity\BoardArticle;

/**
 * 게시글 수정 전 이벤트 (차단 가능)
 *
 * 발행 시점: 수정 요청 검증 후, DB 업데이트 전
 * 용도: 수정 권한 검증, 이력 백업
 */
class ArticleUpdatingEvent extends AbstractEvent implements FailFastEventInterface
{
    public const NAME = 'board.article.updating';

    private bool $blocked = false;
    private ?string $blockReason = null;

    public function __construct(
        private readonly BoardArticle $article,
        private array $newData,
        private readonly ?int $memberId
    ) {}

    public function getArticle(): BoardArticle
    {
        return $this->article;
    }

    public function getArticleId(): int
    {
        return $this->article->getArticleId();
    }

    /**
     * 새 데이터 조회 (수정 가능)
     */
    public function getNewData(): array
    {
        return $this->newData;
    }

    /**
     * 새 데이터 설정
     */
    public function setNewData(array $data): void
    {
        $this->newData = $data;
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    public function setBlocked(bool $blocked): void
    {
        $this->blocked = $blocked;
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function setBlockReason(?string $reason): void
    {
        $this->blockReason = $reason;
    }

    public function getBlockReason(): ?string
    {
        return $this->blockReason;
    }
}
