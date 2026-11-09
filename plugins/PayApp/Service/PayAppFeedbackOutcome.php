<?php
declare(strict_types=1);

namespace Mublo\Plugin\PayApp\Service;

/**
 * 결제 통보(feedbackurl)에 돌려줄 응답.
 *
 * 페이앱은 HTTP 200 + 본문 'SUCCESS' 만 수신 완료로 본다. 그 밖의 응답은
 * checkretry=y 로 요청한 결제에 한해 재전송한다(문서: 최대 10회).
 *
 *   ok()     — 처리했거나, 다시 보내도 결과가 같은 경우(재전송 불필요)
 *   retry()  — 일시 오류. 다시 보내면 처리될 수 있다
 *   reject() — 인증 실패. 위조로 보고 거부한다
 */
final class PayAppFeedbackOutcome
{
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $note,
    ) {
    }

    public static function ok(string $note = ''): self
    {
        return new self(200, 'SUCCESS', $note);
    }

    public static function retry(string $note = ''): self
    {
        return new self(500, 'FAIL', $note);
    }

    public static function reject(string $note = ''): self
    {
        return new self(403, 'FAIL', $note);
    }

    public function isOk(): bool
    {
        return $this->body === 'SUCCESS';
    }
}
