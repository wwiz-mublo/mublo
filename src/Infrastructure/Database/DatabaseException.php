<?php
declare(strict_types=1);
namespace Mublo\Infrastructure\Database;

/**
 * Database Exception
 *
 * 데이터베이스 관련 예외 처리
 */
class DatabaseException extends \RuntimeException
{
    /**
     * 연결 실패 예외 생성
     */
    public static function connectionFailed(string $message, ?\Throwable $previous = null): self
    {
        return new self("Database connection failed: {$message}", 0, $previous);
    }

    /**
     * 쿼리 실행 실패 예외 생성
     */
    public static function queryFailed(string $query, ?\Throwable $previous = null): self
    {
        $message = "Query execution failed: {$query}";
        if ($previous !== null) {
            $message .= ' | Error: ' . $previous->getMessage();
        }
        return new self($message, 0, $previous);
    }

    /**
     * 설정 오류 예외 생성
     */
    public static function configError(string $message): self
    {
        return new self("Database configuration error: {$message}");
    }
}
