<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;

/**
 * 게시글 작성 전 이벤트 (차단 가능)
 *
 * 발행 시점: 작성 요청 검증 후, DB 저장 전
 * 용도: 스팸 필터링, 내용 검증, 자동 태그 추가
 *
 * 차단 가능: stopPropagation() 호출 시 게시글 작성 중단
 */
class ArticleCreatingEvent extends AbstractEvent implements FailFastEventInterface
{
    public const NAME = 'board.article.creating';

    private bool $blocked = false;
    private ?string $blockReason = null;

    public function __construct(
        private readonly int $domainId,
        private readonly int $boardId,
        private array $data,
        private readonly ?int $memberId
    ) {}

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getBoardId(): int
    {
        return $this->boardId;
    }

    /**
     * 데이터 조회 (수정 가능)
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * 데이터 수정
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * 특정 필드 수정
     */
    public function setField(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    /**
     * 차단 설정
     */
    public function setBlocked(bool $blocked): void
    {
        $this->blocked = $blocked;
    }

    /**
     * 차단 여부 확인
     */
    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    /**
     * 차단 사유 설정
     */
    public function setBlockReason(?string $reason): void
    {
        $this->blockReason = $reason;
    }

    /**
     * 차단 사유 조회
     */
    public function getBlockReason(): ?string
    {
        return $this->blockReason;
    }
}
