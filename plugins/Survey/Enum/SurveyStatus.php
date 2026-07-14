<?php
namespace Mublo\Plugin\Survey\Enum;

enum SurveyStatus: string
{
    case Draft  = 'draft';
    case Active = 'active';
    case Closed = 'closed';

    public function label(): string
    {
        return match($this) {
            self::Draft  => '초안',
            self::Active => '진행중',
            self::Closed => '종료',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::Draft  => 'secondary',
            self::Active => 'success',
            self::Closed => 'dark',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn(self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }

    public static function fromValue(string $value): self
    {
        return self::from($value);
    }
}
