<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Service;

/**
 * 결제 복귀 처리 결과 — 이동할 곳이 있으면 redirect, 없으면 안내 문구만 보여준다.
 */
final readonly class InnopayReturnOutcome
{
    private function __construct(
        public bool $success,
        public ?string $redirect,
        public string $message,
    ) {
    }

    public static function redirect(bool $success, string $url, string $message = ''): self
    {
        return new self($success, $url, $message);
    }

    public static function message(bool $success, string $message): self
    {
        return new self($success, null, $message);
    }
}
