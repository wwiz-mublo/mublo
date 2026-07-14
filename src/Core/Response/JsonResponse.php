<?php
namespace Mublo\Core\Response;

/**
 * Class JsonResponse
 *
 * JSON API 응답
 * - MubloRequest.js와 호환되는 형식으로 응답
 */
class JsonResponse extends AbstractResponse
{
    protected array $data;
    protected bool $success;
    protected ?string $message;

    /**
     * Constructor
     *
     * @param mixed $data 응답 데이터
     * @param bool $success 성공 여부
     * @param string|null $message 메시지
     * @param int $statusCode HTTP 상태 코드
     */
    public function __construct(
        mixed $data = null,
        bool $success = true,
        ?string $message = null,
        int $statusCode = 200
    ) {
        $this->data = $this->buildResponseData($data, $success, $message);
        $this->success = $success;
        $this->message = $message;
        $this->statusCode = $statusCode;
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';
    }

    /**
     * 성공 응답 생성
     *
     * @param mixed $data 응답 데이터
     * @param string|null $message 메시지
     * @param int $statusCode HTTP 상태 코드
     * @return self
     */
    public static function success(mixed $data = null, ?string $message = null, int $statusCode = 200): self
    {
        return new self($data, true, $message, $statusCode);
    }

    /**
     * 실패 응답 생성
     *
     * @param string $message 에러 메시지
     * @param mixed $data 추가 데이터
     * @param int $statusCode HTTP 상태 코드
     * @return self
     */
    public static function error(string $message, mixed $data = null, int $statusCode = 400): self
    {
        return new self($data, false, $message, $statusCode);
    }

    /**
     * 유효성 검증 실패 응답
     *
     * @param array $errors 에러 목록
     * @param string $message 에러 메시지
     * @return self
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): self
    {
        return new self(['errors' => $errors], false, $message, 422);
    }

    /**
     * 인증 실패 응답
     *
     * @param string $message 에러 메시지
     * @return self
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(null, false, $message, 401);
    }

    /**
     * 권한 없음 응답
     *
     * @param string $message 에러 메시지
     * @return self
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(null, false, $message, 403);
    }

    /**
     * 리소스 없음 응답
     *
     * @param string $message 에러 메시지
     * @return self
     */
    public static function notFound(string $message = 'Not found'): self
    {
        return new self(null, false, $message, 404);
    }

    /**
     * 서버 에러 응답
     *
     * @param string $message 에러 메시지
     * @return self
     */
    public static function serverError(string $message = 'Internal server error'): self
    {
        return new self(null, false, $message, 500);
    }

    /**
     * MubloRequest.js 호환 응답 데이터 구조 생성
     *
     * MubloRequest.js는 { result: 'success' | 'error', message, data } 형식을 기대함
     *
     * @param mixed $data
     * @param bool $success
     * @param string|null $message
     * @return array
     */
    protected function buildResponseData(mixed $data, bool $success, ?string $message): array
    {
        $response = [
            'result' => $success ? 'success' : 'error',
            'success' => $success,  // 하위 호환성
            'message' => $message ?? '',
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        return $response;
    }

    /**
     * 응답 데이터 반환
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * JSON 문자열 반환
     *
     * json_encode 실패 시 (UTF-8 오류 등) 최소한의 에러 JSON 반환
     */
    public function toJson(): string
    {
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $errorMsg = json_last_error_msg();
            //error_log("JsonResponse encode failed: {$errorMsg}");

            return json_encode([
                'result' => 'error',
                'success' => false,
                'message' => 'JSON 인코딩에 실패했습니다.',
            ], JSON_UNESCAPED_UNICODE);
        }

        return $json;
    }

    /**
     * 성공 여부 반환
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * 메시지 반환
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }
}
