<?php
declare(strict_types=1);
namespace Mublo\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Infrastructure\Security\RateLimiter;
use Mublo\Service\Editor\OgMetaFetcher;

/**
 * 에디터 링크 카드 메타 API
 *
 * GET /api/v1/editor/og?url=https://...
 *
 * 책임:
 * - 붙여넣은 링크의 제목·설명·대표이미지를 서버가 대신 읽어 온다
 * - 나가는 요청의 안전(SSRF 방어)은 OgMetaFetcher 가 책임진다
 *
 * 이 엔드포인트는 서버가 외부로 요청을 내보내므로, 인증이 없는 만큼 IP 단위
 * 남용 제한을 업로드보다 좁게 잡는다. 읽기 전용이라 CSRF 검증 대상은 아니다
 * (CsrfMiddleware 가 GET 을 건너뛴다).
 *
 * 응답:
 * - success: { success: true, title, description, image, host }
 * - error:   { success: false, message }
 */
class EditorOgController
{
    private const RATE_LIMIT_MAX = 30;
    private const RATE_LIMIT_WINDOW = 600;   // 10분

    /** 주소 자체가 지나치게 길면 읽지 않는다 */
    private const MAX_URL_LENGTH = 2048;

    public function __construct(
        private OgMetaFetcher $fetcher,
        private ?RateLimiter $rateLimiter = null,
    ) {
    }

    public function fetch(Request $request, Context $context): JsonResponse
    {
        if ($this->rateLimiter !== null) {
            $key = 'editor-og:' . ($context->getDomainId() ?? 0) . ':' . $request->getClientIp();
            if (!$this->rateLimiter->attempt($key, self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW)) {
                return JsonResponse::error('요청이 너무 많습니다. 잠시 후 다시 시도해주세요.', null, 429);
            }
        }

        $url = trim((string) $request->get('url', ''));
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH) {
            return JsonResponse::error('주소가 필요합니다.', null, 400);
        }

        $meta = $this->fetcher->fetch($url);
        if ($meta === null) {
            // 막힌 주소인지 그냥 못 읽은 것인지는 구분해서 알리지 않는다 —
            // 내부망 탐색에 쓰일 수 있는 단서를 응답으로 흘리지 않기 위해서다.
            return JsonResponse::error('링크 정보를 가져올 수 없습니다.', null, 422);
        }

        return JsonResponse::success($meta);
    }
}
