<?php
namespace Mublo\Core\Response;

/**
 * Class AbstractResponse
 *
 * 모든 Response의 공통 타입
 */
abstract class AbstractResponse
{
    protected int $statusCode = 200;
    protected array $headers = [];

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * HTTP 상태 코드 설정
     *
     * @return static
     */
    public function withStatusCode(int $code): static
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * HTTP 헤더 추가
     *
     * @return static
     */
    public function withHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }
}
