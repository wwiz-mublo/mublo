<?php
namespace Mublo\Infrastructure\Log;

/**
 * LogLevel
 *
 * PSR-3 호환 로그 레벨 상수
 */
class LogLevel
{
    public const EMERGENCY = 'emergency';
    public const ALERT = 'alert';
    public const CRITICAL = 'critical';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';
    public const INFO = 'info';
    public const DEBUG = 'debug';

    /**
     * 레벨 우선순위 (숫자가 낮을수록 심각)
     */
    public const PRIORITY = [
        self::EMERGENCY => 0,
        self::ALERT => 1,
        self::CRITICAL => 2,
        self::ERROR => 3,
        self::WARNING => 4,
        self::NOTICE => 5,
        self::INFO => 6,
        self::DEBUG => 7,
    ];

    /**
     * 유효한 레벨인지 확인
     */
    public static function isValid(string $level): bool
    {
        return isset(self::PRIORITY[$level]);
    }

    /**
     * 레벨 우선순위 반환
     */
    public static function getPriority(string $level): int
    {
        return self::PRIORITY[$level] ?? 999;
    }
}
