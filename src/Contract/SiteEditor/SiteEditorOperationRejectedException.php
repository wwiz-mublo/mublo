<?php
declare(strict_types=1);

namespace Mublo\Contract\SiteEditor;

use InvalidArgumentException;

/**
 * 제품 패키지의 operation·restore handler가 요청을 거절할 때 쓰는 예외.
 *
 * 오류 코드는 사이트 편집 프로토콜 오류 코드(예: PLAN_SCHEMA_INVALID, RESTORE_RESOURCE_CHANGED)를 쓴다.
 * 실행기는 이 예외를 프로토콜 오류로 바꿔 앱에 돌려준다. 제품 패키지가 실행기 구현 클래스에 의존하지 않게 한다.
 */
final class SiteEditorOperationRejectedException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $resourceId = ''
    ) {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException('Site editor rejection code must be a protocol error code.');
        }
        parent::__construct($message);
    }
}
