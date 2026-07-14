<?php
namespace Mublo\Exception;

/**
 * ValidationException
 *
 * 데이터 검증 실패
 */
class ValidationException extends ApplicationException
{
    protected array $errors = [];

    public function __construct(
        string $message = 'Validation failed',
        array $errors = [],
        array $context = []
    ) {
        $this->errors = $errors;
        parent::__construct(
            $message,
            'ERR_VALIDATION',
            400,
            $context
        );
    }

    /**
     * 검증 에러 목록 반환
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 에러 정보를 배열로 변환
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'errors' => $this->errors,
        ]);
    }
}
