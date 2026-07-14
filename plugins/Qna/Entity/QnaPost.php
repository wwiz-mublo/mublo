<?php
namespace Mublo\Plugin\Qna\Entity;

use Mublo\Plugin\Qna\Enum\AuthorType;
use Mublo\Plugin\Qna\Enum\InquiryStatus;

/**
 * QnA 글 엔티티 (질문 + 답변 통합).
 *
 * parent_id 로 역할을 구분한다.
 *   NULL → 질문(스레드 헤드)
 *   값   → 답변/추가문의
 */
class QnaPost
{
    public function __construct(private array $attributes) {}

    public static function fromArray(array $row): self
    {
        return new self($row);
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function getPostId(): int
    {
        return (int) ($this->attributes['post_id'] ?? 0);
    }

    public function getDomainId(): int
    {
        return (int) ($this->attributes['domain_id'] ?? 0);
    }

    public function getParentId(): ?int
    {
        $p = $this->attributes['parent_id'] ?? null;
        return $p !== null ? (int) $p : null;
    }

    /** 질문(스레드 헤드)인가 */
    public function isQuestion(): bool
    {
        return $this->getParentId() === null;
    }

    /** 답변/추가문의인가 */
    public function isAnswer(): bool
    {
        return !$this->isQuestion();
    }

    public function getMemberId(): int
    {
        return (int) ($this->attributes['member_id'] ?? 0);
    }

    public function getAuthorType(): AuthorType
    {
        return AuthorType::from($this->attributes['author_type'] ?? 'member');
    }

    public function getAuthorName(): string
    {
        return (string) ($this->attributes['author_name'] ?? '');
    }

    public function getCategoryId(): ?int
    {
        $id = $this->attributes['category_id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    public function getTitle(): string
    {
        return (string) ($this->attributes['title'] ?? '');
    }

    public function getContent(): string
    {
        return (string) ($this->attributes['content'] ?? '');
    }

    public function isSecret(): bool
    {
        return (bool) ($this->attributes['is_secret'] ?? true);
    }

    public function isPrivate(): bool
    {
        return (bool) ($this->attributes['is_private'] ?? false);
    }

    public function getStatus(): InquiryStatus
    {
        return InquiryStatus::from($this->attributes['status'] ?? 'open');
    }

    public function getReplyCount(): int
    {
        return (int) ($this->attributes['reply_count'] ?? 0);
    }

    /**
     * 질문 열람 권한 판정: 작성 회원 본인 또는 운영자만.
     * is_secret 값과 무관하게 적용한다 — 문의는 무조건 비공개 정책.
     */
    public function canBeViewedBy(?int $memberId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        return $memberId !== null
            && $memberId > 0
            && $memberId === $this->getMemberId();
    }

    /** 운영자 내부 비노출 메모는 문의자에게 보이지 않는다. */
    public function isVisibleTo(bool $isAdmin): bool
    {
        return $isAdmin || !$this->isPrivate();
    }
}
