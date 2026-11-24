<?php
declare(strict_types=1);

namespace Mublo\Contract\SiteEditor;

use InvalidArgumentException;

/** 콘텐츠 편집 규격이 요청을 거절할 때 쓰는 예외. 오류 코드는 사이트 편집 프로토콜 코드를 쓴다. */
final class SiteEditorContentRejectedException extends InvalidArgumentException
{
    public const FIELD_NOT_EDITABLE = 'PLAN_OPERATION_UNSUPPORTED';
    public const INVALID_VALUE = 'PLAN_SCHEMA_INVALID';
    public const VARIANT_UNAVAILABLE = 'BLOCK_VARIANT_UNAVAILABLE';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $field = ''
    ) {
        if (!in_array($errorCode, [self::FIELD_NOT_EDITABLE, self::INVALID_VALUE, self::VARIANT_UNAVAILABLE], true)) {
            throw new \InvalidArgumentException('Unsupported content rejection code.');
        }
        parent::__construct($message);
    }
}
