<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\HtmlResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Plugin\Innopay\Repository\InnopayAttemptRepository;
use Mublo\Plugin\Innopay\Repository\InnopayLogRepository;
use Mublo\Plugin\Innopay\Service\InnopayLogger;
use Mublo\Plugin\Innopay\Service\InnopayNotiService;
use Mublo\Plugin\Innopay\Service\InnopayReturnService;

class CallbackController
{
    /** 브라우저가 보낼 수 있는 로그 단계 */
    private const CLIENT_EVENTS = ['CLIENT_OPEN', 'CLIENT_CLOSE', 'CLIENT_ERROR'];

    /** 결제 시도 한 건당 받을 브라우저 로그 상한 */
    private const CLIENT_LOG_LIMIT = 30;

    public function __construct(
        private InnopayReturnService $returnService,
        private InnopayAttemptRepository $attempts,
        private InnopayLogRepository $logRepository,
        private InnopayLogger $logger,
        private InnopayNotiService $notiService,
    ) {
    }

    /**
     * 결제결과 통보(Noti) 수신 — 이노페이 서버 → 가맹점 서버
     * POST|GET /innopay/callback/noti
     *
     * 본문에 "0000" 을 돌려줘야 수신 완료로 본다. 그 밖의 값이면 1분 간격으로 10번까지 재전송한다.
     */
    public function noti(Request $request, Context $context): HtmlResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $params = array_merge($request->getQuery(), $request->getBody());
        if ($params === []) {
            $raw = (string) file_get_contents('php://input');
            if ($raw !== '') {
                parse_str($raw, $params);
            }
        }

        try {
            $reply = $this->notiService->handle($domainId, $params, $request->getClientIp());
        } catch (\Throwable $e) {
            error_log('[Innopay] 결제결과 통보 처리 예외: ' . $e->getMessage());
            $this->logger->log($domainId, 'NOTI_RESULT', [
                'moid' => (string) ($params['moid'] ?? ''),
                'tid' => (string) ($params['transSeq'] ?? ''),
                'level' => 'error',
                'result_code' => 'EXCEPTION',
                'result_msg' => $e->getMessage() . ' [응답 ' . InnopayNotiService::RETRY . ']',
            ]);
            $reply = InnopayNotiService::RETRY;
        }

        return new HtmlResponse($reply);
    }

    /**
     * 결제창 복귀 지점
     * GET|POST /innopay/callback/return
     */
    public function returnUrl(Request $request, Context $context): RedirectResponse|HtmlResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $params = array_merge($request->getBody(), $request->getQuery());

        try {
            $outcome = $this->returnService->handle(
                $domainId,
                $params,
                $request->getClientIp(),
                (string) $request->header('User-Agent', '')
            );
        } catch (\Throwable $e) {
            error_log('[Innopay] 복귀 처리 예외: ' . $e->getMessage());
            $this->logger->log($domainId, 'RETURN_EXCEPTION', [
                'moid' => (string) ($params['moid'] ?? ''),
                'tid' => (string) ($params['tid'] ?? ''),
                'level' => 'error',
                'result_code' => 'EXCEPTION',
                'result_msg' => $e->getMessage(),
            ]);
            return $this->messagePage('결제 처리 중 오류가 발생했습니다. 주문 내역을 확인해주세요.');
        }

        if ($outcome->redirect !== null) {
            return RedirectResponse::to($outcome->redirect);
        }
        if ($outcome->virtualAccount !== null) {
            return $this->virtualAccountPage($outcome->virtualAccount, $outcome->continueUrl);
        }

        return $this->messagePage($outcome->message);
    }

    /**
     * 브라우저 로그 수신 (결제창 열림·닫힘·오류)
     * POST /innopay/callback/log
     */
    public function clientLog(Request $request, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $payload = $request->json() ?? $request->getBody();
        $payload = is_array($payload) ? $payload : [];

        $moid = trim((string) ($payload['moid'] ?? ''));
        $event = strtoupper(trim((string) ($payload['event'] ?? '')));

        if ($moid === '' || !in_array($event, self::CLIENT_EVENTS, true)) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        // 결제 준비 기록이 있는 주문번호만 받는다 — 아무 값이나 로그로 쌓이지 않게
        if ($this->attempts->find($domainId, $moid) === null
            || $this->logRepository->countClientLogs($domainId, $moid) >= self::CLIENT_LOG_LIMIT) {
            return JsonResponse::success(null, 'ignored');
        }

        $this->logger->log($domainId, $event, [
            'moid' => $moid,
            'level' => $event === 'CLIENT_ERROR' ? 'warning' : 'info',
            'result_msg' => mb_substr(trim((string) ($payload['message'] ?? '')), 0, 200),
            'ip' => $request->getClientIp(),
            'user_agent' => (string) $request->header('User-Agent', ''),
        ]);

        return JsonResponse::success(null, 'ok');
    }

    private function messagePage(string $message): HtmlResponse
    {
        $safe = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        return new HtmlResponse(<<<HTML
<!DOCTYPE html>
<html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>결제 안내</title></head>
<body style="font-family:sans-serif; text-align:center; margin:4rem 1rem; color:#333;">
<p style="font-size:1rem; line-height:1.6;">{$safe}</p>
<p style="margin-top:2rem;"><a href="/" style="color:#1e5dd2;">홈으로</a></p>
</body></html>
HTML);
    }

    /**
     * 가상계좌 발급 안내 — 계좌·금액·기한을 보여주고 주문 화면(소비처가 준 경로)으로 이어간다.
     *
     * @param array{bank: string, account: string, amount: int, due_date: string} $account
     */
    private function virtualAccountPage(array $account, string $continueUrl): HtmlResponse
    {
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $bank = $e($account['bank']);
        $number = $e($account['account']);
        $amount = number_format((int) $account['amount']);
        $due = $account['due_date'] !== '' ? $e($account['due_date']) . ' 까지' : '-';
        $next = $continueUrl !== '' ? $e($continueUrl) : '/';
        $numberJs = json_encode((string) $account['account'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        return new HtmlResponse(<<<HTML
<!DOCTYPE html>
<html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>가상계좌 발급 안내</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Apple SD Gothic Neo","Malgun Gothic",sans-serif;margin:0;padding:2.5rem 1rem;background:#f6f7f9;color:#222}
.box{max-width:420px;margin:0 auto;background:#fff;border-radius:14px;padding:1.75rem 1.5rem;box-shadow:0 2px 12px rgba(0,0,0,.06)}
h1{font-size:1.2rem;margin:0 0 .4rem}
.sub{color:#666;font-size:.9rem;margin:0 0 1.4rem;line-height:1.5}
.row{display:flex;justify-content:space-between;align-items:center;padding:.7rem 0;border-bottom:1px solid #eee;font-size:.95rem}
.row span:first-child{color:#777}
.acct{font-weight:700;letter-spacing:.02em}
.amt{font-weight:700;color:#1e5dd2;font-size:1.1rem}
.copy{margin-left:.5rem;border:1px solid #ccd;background:#fff;border-radius:6px;padding:.2rem .5rem;font-size:.8rem;cursor:pointer}
.note{font-size:.85rem;color:#666;line-height:1.6;margin:1.2rem 0 1.4rem}
.btn{display:block;text-align:center;background:#1e5dd2;color:#fff;text-decoration:none;border-radius:8px;padding:.85rem;font-weight:600}
</style></head>
<body>
<div class="box">
  <h1>가상계좌가 발급되었습니다</h1>
  <p class="sub">아래 계좌로 입금 기한까지 입금하시면 결제가 자동으로 완료됩니다.</p>
  <div class="row"><span>은행</span><span>{$bank}</span></div>
  <div class="row"><span>계좌번호</span><span><span class="acct">{$number}</span><button type="button" class="copy" id="copyAcct">복사</button></span></div>
  <div class="row"><span>입금 금액</span><span class="amt">{$amount}원</span></div>
  <div class="row"><span>입금 기한</span><span>{$due}</span></div>
  <p class="note">금액이 다르면 입금되지 않을 수 있으니 정확한 금액을 입금해주세요. 계좌 정보는 주문 내역에서도 다시 확인할 수 있습니다.</p>
  <a class="btn" href="{$next}">주문 내역 보기</a>
</div>
<script>
document.getElementById('copyAcct').addEventListener('click', function () {
  var b = this;
  if (navigator.clipboard) navigator.clipboard.writeText({$numberJs}).then(function () { b.textContent = '복사됨'; });
});
</script>
</body></html>
HTML);
    }
}
