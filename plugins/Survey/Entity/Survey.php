<?php
namespace Mublo\Plugin\Survey\Entity;

use Mublo\Plugin\Survey\Enum\SurveyStatus;

class Survey
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

    public function getSurveyId(): int
    {
        return (int) ($this->attributes['survey_id'] ?? 0);
    }

    public function getDomainId(): int
    {
        return (int) ($this->attributes['domain_id'] ?? 0);
    }

    public function getTitle(): string
    {
        return (string) ($this->attributes['title'] ?? '');
    }

    public function getDescription(): string
    {
        return (string) ($this->attributes['description'] ?? '');
    }

    public function getStatus(): SurveyStatus
    {
        return SurveyStatus::from($this->attributes['status'] ?? 'draft');
    }

    public function allowsAnonymous(): bool
    {
        return (bool) ($this->attributes['allow_anonymous'] ?? true);
    }

    public function allowsDuplicate(): bool
    {
        return (bool) ($this->attributes['allow_duplicate'] ?? false);
    }

    public function getResponseLimit(): int
    {
        return (int) ($this->attributes['response_limit'] ?? 0);
    }

    public function getStartAt(): ?string
    {
        return $this->attributes['start_at'] ?? null;
    }

    public function getEndAt(): ?string
    {
        return $this->attributes['end_at'] ?? null;
    }

    public function isActive(): bool
    {
        return $this->getStatus() === SurveyStatus::Active;
    }

    public function isClosed(): bool
    {
        return $this->getStatus() === SurveyStatus::Closed;
    }

    /** 현재 시각 기준으로 참여 가능한 기간인지 확인 */
    public function isWithinPeriod(): bool
    {
        $now = time();
        $start = $this->getStartAt();
        $end   = $this->getEndAt();

        if ($start !== null && strtotime($start) > $now) {
            return false;
        }
        if ($end !== null && strtotime($end) < $now) {
            return false;
        }
        return true;
    }

    /** 응답 한도 초과 여부 (0=무제한) */
    public function isResponseLimitReached(int $currentCount): bool
    {
        $limit = $this->getResponseLimit();
        return $limit > 0 && $currentCount >= $limit;
    }

    /** 종료 후 결과를 공개할 수 있는지 (closed 상태이거나 종료일이 지난 경우) */
    public function isResultVisible(): bool
    {
        if ($this->isClosed()) {
            return true;
        }
        $end = $this->getEndAt();
        return $end !== null && strtotime($end) < time();
    }
}
