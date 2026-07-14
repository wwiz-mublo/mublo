<?php
namespace Mublo\Exception;

use Exception;

/**
 * ApplicationException
 *
 * 모든 애플리케이션 예외의 기본 클래스
 *
 * 책임:
 * - 에러 코드 관리
 * - 컨텍스트 정보 저장
 * - 로깅 지원
 */
class ApplicationException extends Exception
{
    protected string $errorCode;
    protected array $context = [];
    protected int $httpStatusCode = 500;

    public function __construct(
        string $message = '',
        string $errorCode = 'ERR_UNKNOWN',
        int $httpStatusCode = 500,
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
        $this->context = $context;
    }

    /**
     * 에러 코드 반환
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * HTTP 상태 코드 반환
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * 컨텍스트 정보 반환
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * 에러 정보를 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
            'status_code' => $this->httpStatusCode,
            'context' => $this->context,
        ];
    }
}
