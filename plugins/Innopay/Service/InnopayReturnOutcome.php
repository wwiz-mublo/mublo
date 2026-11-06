<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Service;

/**
 * 결제 복귀 처리 결과 — 이동할 곳이 있으면 redirect, 없으면 안내 문구만 보여준다.
 * 가상계좌가 발급되면 계좌 안내 화면(virtualAccount)을 보여주고 continueUrl 로 이어간다.
 */
final readonly class InnopayReturnOutcome
{
    /**
     * @param array{bank: string, account: string, amount: int, due_date: string}|null $virtualAccount
     */
    private function __construct(
        public bool $success,
        public ?string $redirect,
        public string $message,
        public ?array $virtualAccount = null,
        public string $continueUrl = '',
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

    /**
     * @param array{bank: string, account: string, amount: int, due_date: string} $account
     */
    public static function virtualAccount(array $account, string $continueUrl): self
    {
        return new self(true, null, '가상계좌가 발급되었습니다.', $account, $continueUrl);
    }
}
