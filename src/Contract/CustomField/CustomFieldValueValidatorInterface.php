<?php
declare(strict_types=1);

namespace Mublo\Contract\CustomField;

use Mublo\Core\Result\Result;

interface CustomFieldValueValidatorInterface
{
    public function isFileType(string $fieldType): bool;

    public function isEmpty(string $fieldType, mixed $value): bool;

    public function validateType(string $fieldType, mixed $value, string $fieldLabel = '필드'): Result;

    public function validatePattern(mixed $value, ?string $pattern, string $fieldLabel = '필드'): Result;

    public function normalizeForStorage(string $fieldType, mixed $value): mixed;
}
