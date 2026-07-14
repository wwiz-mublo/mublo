<?php
namespace Mublo\Plugin\Survey\Entity;

use Mublo\Plugin\Survey\Enum\QuestionType;

class SurveyQuestion
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

    public function getQuestionId(): int
    {
        return (int) ($this->attributes['question_id'] ?? 0);
    }

    public function getSurveyId(): int
    {
        return (int) ($this->attributes['survey_id'] ?? 0);
    }

    public function getType(): QuestionType
    {
        return QuestionType::from($this->attributes['type'] ?? 'text');
    }

    public function getTitle(): string
    {
        return (string) ($this->attributes['title'] ?? '');
    }

    public function getDescription(): string
    {
        return (string) ($this->attributes['description'] ?? '');
    }

    /** radio/checkbox/select의 선택지 배열 반환 */
    public function getOptions(): array
    {
        $raw = $this->attributes['options'] ?? null;
        if (is_string($raw)) {
            return json_decode($raw, true) ?? [];
        }
        return is_array($raw) ? $raw : [];
    }

    public function isRequired(): bool
    {
        return (bool) ($this->attributes['required'] ?? true);
    }

    public function getSortOrder(): int
    {
        return (int) ($this->attributes['sort_order'] ?? 0);
    }

    public function hasOptions(): bool
    {
        return $this->getType()->hasOptions();
    }
}
