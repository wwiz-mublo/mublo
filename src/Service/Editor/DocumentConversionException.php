<?php
declare(strict_types=1);

namespace Mublo\Service\Editor;

use RuntimeException;

/**
 * 문서 변환 실패
 *
 * 실패 사유를 코드로 들고 다닌다 — 응답 메시지는 사람이 읽고, 코드는 호출부가
 * 분기한다. 두 값이 한 예외 안에 있어야 컨트롤러에서 문자열 비교로 사유를
 * 되짚는 일이 생기지 않는다.
 */
final class DocumentConversionException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
