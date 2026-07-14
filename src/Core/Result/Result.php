<?php
namespace Mublo\Core\Result;

/**
 * Result - Service 계층 결과 객체
 *
 * Service 메서드의 성공/실패 envelope를 표준화합니다.
 * payload 계약은 각 Service 반환 PHPDoc의 제네릭 배열 shape로 선언합니다.
 *
 * 사용 예시:
 * ```php
 * // Service에서
 * public function createBoard(...): Result
 * {
 *     if ($error) {
 *         return Result::failure('생성에 실패했습니다.');
 *     }
 *     return Result::success('생성되었습니다.', ['board_id' => $id]);
 * }
 *
 * // Controller에서
 * $result = $this->boardService->createBoard(...);
 * if ($result->isSuccess()) {
 *     return JsonResponse::success($result->getData(), $result->getMessage());
 * }
 * return JsonResponse::error($result->getMessage());
 * ```
 *
 * 주의:
 * - Result는 Service → Controller 내부 통신용입니다.
 * - HTTP 응답은 Response 클래스(JsonResponse, ViewResponse 등)를 사용하세요.
 * @template TData of array<string, mixed>
 */
class Result
{
    private bool $success;
    private string $message;
    /** @var TData */
    private array $data;

    /**
     * @param bool $success 성공 여부
     * @param string $message 결과 메시지
     * @param TData $data 추가 데이터
     */
    private function __construct(bool $success, string $message = '', array $data = [])
    {
        $this->success = $success;
        $this->message = $message;
        $this->data = $data;
    }

    /**
     * 성공 Result 생성
     *
     * @param string $message 성공 메시지
     * @template TSuccessData of array<string, mixed>
     * @param TSuccessData $data 추가 데이터 (예: ['board_id' => 1, 'redirect' => '/admin/board'])
     * @return self<TSuccessData>
     */
    public static function success(string $message = '', array $data = []): self
    {
        return new self(true, $message, $data);
    }

    /**
     * 실패 Result 생성
     *
     * @param string $message 실패 메시지
     * @template TFailureData of array<string, mixed>
     * @param TFailureData $data 추가 데이터 (예: ['errors' => [...], 'field' => 'email'])
     * @return self<TFailureData>
     */
    public static function failure(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    /**
     * 성공 여부 확인
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * 실패 여부 확인
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }

    /**
     * 결과 메시지 반환
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * 전체 데이터 반환
     */
    /** @return TData */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * 특정 키의 데이터 반환
     *
     * @param string $key 데이터 키
     * @param mixed $default 기본값
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * 데이터 키 존재 여부 확인
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * 추가 데이터 병합 (새 Result 반환)
     *
     * @param array<string, mixed> $data 병합할 데이터
     * @return self<array<string, mixed>>
     */
    public function withData(array $data): self
    {
        return new self($this->success, $this->message, array_merge($this->data, $data));
    }

    /**
     * 메시지 변경 (새 Result 반환)
     *
     * @param string $message 새 메시지
     * @return self<TData>
     */
    public function withMessage(string $message): self
    {
        return new self($this->success, $message, $this->data);
    }

    /**
     * 배열로 변환 (하위 호환성)
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(
            ['success' => $this->success, 'message' => $this->message],
            $this->data
        );
    }
}
