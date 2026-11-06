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
    ) {
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
}
