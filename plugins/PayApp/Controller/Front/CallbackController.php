<?php
declare(strict_types=1);

namespace Mublo\Plugin\PayApp\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\HtmlResponse;
use Mublo\Plugin\PayApp\Service\PayAppFeedbackService;

/**
 * PayApp Feedback URL 콜백 컨트롤러
 *
 * PayApp이 결제 완료/취소 시 서버→서버로 POST 호출.
 * HTTP 200 + 본문 'SUCCESS' 를 받아야 수신 완료로 본다. 그 밖의 응답은 재전송된다
 * (결제 요청 때 checkretry=y, 문서: 최대 10회).
 *
 * POST /pay-app/callback/feedback
 *
 * 판단은 PayAppFeedbackService 가 한다 — 이 컨트롤러는 본문을 꺼내 넘기고 응답만 돌려준다.
 */
class CallbackController
{
    public function __construct(
        private PayAppFeedbackService $feedbackService,
    ) {}

    public function feedback(Request $request, Context $context): HtmlResponse
    {
        // PayApp은 POST body로 전달
        $data = $request->all();
        if (empty($data) || empty($data['mul_no'])) {
            // raw body에서 파싱 시도
            $raw = (string) file_get_contents('php://input');
            if ($raw !== '') {
                parse_str($raw, $data);
            }
        }

        try {
            $outcome = $this->feedbackService->handle(
                $data,
                (string) $request->query('by', ''),
                $context->getDomainId() ?? 1,
                $request->getClientIp()
            );
        } catch (\Throwable $e) {
            // 예상 못 한 예외는 일시 오류로 보고 재전송을 받는다(처리는 멱등)
            error_log('[PayApp] Feedback processing error: ' . $e->getMessage() . ' mul_no=' . (string) ($data['mul_no'] ?? ''));
            return new HtmlResponse('FAIL', 500);
        }

        return new HtmlResponse($outcome->body, $outcome->status);
    }


    /**
     * 결제창(팝업) 복귀 지점
     * GET|POST /pay-app/callback/return
     *
     * 페이앱은 결제가 끝나면 결제창 팝업을 이 주소로 이동시킨다. PG 는 자기가 있는 창만
     * 옮길 수 있으므로, 여기서 부모창(가맹점 페이지)에 결제창이 끝났음을 알리고 팝업을
     * 닫는다. 그 뒤 처리는 부모창이 한다 — 부모창은 이미 팝업 닫힘을 감지해 결제 상태를
     * 서버에서 확인(statecheck)하고 주문을 확정하는 경로를 갖고 있다.
     *
     * 결제 성공 여부를 여기서 판정하지 않는다. 이 창은 "결제창이 끝났다"는 사실만 알고,
     * 승인 여부의 권위는 PG 서버 조회와 소비 패키지의 검증에 있다.
     */
    public function returnUrl(Request $request, Context $context): HtmlResponse
    {
        return new HtmlResponse(<<<'HTML'
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>결제 확인 중</title></head>
<body>
<script>
(function () {
    var opener = null;
    try { if (window.opener && !window.opener.closed) opener = window.opener; } catch (e) {}

    if (opener) {
        // 부모창이 살아 있다 — 창을 닫아 부모의 '팝업 닫힘 감지 → 결제 확인'을 깨운다.
        try { window.close(); } catch (e) {}
        return;
    }

    // 부모창이 없다(사용자가 닫았거나 팝업이 단독 창이 된 경우).
    // 결제 결과는 PG 서버 통보(webhook)가 처리하므로 여기서는 안내만 남긴다.
    document.getElementById('msg').style.display = '';
})();
</script>
<p id="msg" style="display:none; font-family:sans-serif; text-align:center; margin-top:3rem;">
    결제 결과를 처리하고 있습니다.<br>이 창을 닫고 주문 내역에서 확인해주세요.
</p>
</body></html>
HTML);
    }
}
