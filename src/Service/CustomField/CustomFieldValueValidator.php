<?php
declare(strict_types=1);

namespace Mublo\Service\CustomField;

use Mublo\Contract\CustomField\CustomFieldValueValidatorInterface;
use Mublo\Core\Result\Result;

final class CustomFieldValueValidator implements CustomFieldValueValidatorInterface
{
    public function isFileType(string $fieldType): bool
    {
        return CustomFieldValidator::isFileType($fieldType);
    }

    public function isEmpty(string $fieldType, mixed $value): bool
    {
        return CustomFieldValidator::isEmpty($fieldType, $value);
    }

    public function validateType(string $fieldType, mixed $value, string $fieldLabel = '필드'): Result
    {
        return CustomFieldValidator::validateByType($fieldType, $value, $fieldLabel);
    }

    public function validatePattern(mixed $value, ?string $pattern, string $fieldLabel = '필드'): Result
    {
        return CustomFieldValidator::validateRegex($value, $pattern, $fieldLabel);
    }

    public function normalizeForStorage(string $fieldType, mixed $value): mixed
    {
        return CustomFieldValidator::normalizeForStorage($fieldType, $value);
    }
}
