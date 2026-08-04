<?php
declare(strict_types=1);
namespace Mublo\Contract\Notification;

/** 외부 알림 게이트웨이의 표준 발송 결과. */
final readonly class NotificationSendResult
{
    public function __construct(
        public bool $success,
        public string $message = '',
    ) {
    }
}
