<?php
namespace Mublo\Plugin\Survey\Enum;

enum QuestionType: string
{
    case Radio    = 'radio';
    case Checkbox = 'checkbox';
    case Select   = 'select';
    case Text     = 'text';
    case Textarea = 'textarea';
    case Rating   = 'rating';

    public function label(): string
    {
        return match($this) {
            self::Radio    => '단일 선택',
            self::Checkbox => '복수 선택',
            self::Select   => '드롭다운',
            self::Text     => '단답형',
            self::Textarea => '장문형',
            self::Rating   => '별점',
        };
    }

    /** 선택지(options) JSON 배열이 필요한 타입인지 여부 */
    public function hasOptions(): bool
    {
        return in_array($this, [self::Radio, self::Checkbox, self::Select], true);
    }

    /** 집계 시 텍스트 답변으로 처리하는 타입인지 여부 */
    public function isTextBased(): bool
    {
        return in_array($this, [self::Text, self::Textarea, self::Rating], true);
    }

    public static function options(): array
    {
        return array_map(
            fn(self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
