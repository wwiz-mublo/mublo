<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\FileResponse;
use Mublo\Packages\Shop\PriceCompare\PriceCompareService;

/**
 * 가격비교 피드 서빙
 *
 * 비교사 크롤러가 직접 받아가는 공개 엔드포인트다. 담기는 값(상품명·가격·이미지·링크)은
 * 이미 상품 페이지에 공개된 것이므로 사이트맵과 같이 토큰 없이 연다.
 *
 * 경로는 채널마다 늘지 않는다. 마지막 세그먼트가 등록된 채널 키다.
 */
class PriceCompareController
{
    public function __construct(
        private readonly PriceCompareService $priceCompareService,
    ) {
    }

    /** GET /shop/price-compare/{channel} — 전체 피드 */
    public function feed(string $channel, Request $request, Context $context): FileResponse
    {
        return $this->serve($channel, $request, $context, false);
    }

    /**
     * GET /shop/price-compare/{channel}/summary — 변경분 피드
     *
     * 비교사가 전체 피드보다 자주 받아가는 주소다. 형식과 컬럼은 전체 피드와 같고
     * 담기는 상품만 최근 변경분으로 좁혀진다.
     */
    public function summary(string $channel, Request $request, Context $context): FileResponse
    {
        return $this->serve($channel, $request, $context, true);
    }

    private function serve(string $channel, Request $request, Context $context, bool $summary): FileResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $feed = $this->priceCompareService->render(
            $channel,
            $domainId,
            $request->getSchemeAndHost(),
            $summary
        );

        if ($feed === null) {
            return new FileResponse(
                null,
                404,
                ['Content-Type' => 'text/plain; charset=utf-8'],
                "Unknown price comparison channel.\n"
            );
        }

        return new FileResponse(
            null,
            200,
            [
                // 형식(TSV/RSS)은 채널이 고르므로 Content-Type 도 채널을 따라간다.
                'Content-Type' => $feed->contentType,
                // 크롤러가 오래된 사본을 재사용하지 않게 한다. 가격이 바뀐 뒤에도
                // 이전 피드가 남아있으면 비교사 화면과 실제 결제 금액이 어긋난다.
                'Cache-Control' => 'no-store',
            ],
            $feed->body
        );
    }
}
