<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\Shop\PriceCompare\BuiltinChannels;
use Mublo\Packages\Shop\PriceCompare\PriceCompareService;

/**
 * 가격비교 사이트 안내
 *
 * 운영자가 여기서 하는 일은 채널을 켜고, 피드 주소를 채널사 관리자에 등록하는 것뿐이다.
 * 수집 로그나 생성 버튼은 두지 않는다 — 등록 상태와 수집 결과는 채널사 관리자가
 * 보여주고, 그쪽이 진실이다.
 *
 * 채널 목록은 등록된 것을 그대로 나열한다. 확장이 자기 채널을 추가하면 이 화면에
 * 코드를 고치지 않고도 함께 나온다.
 */
class PriceCompareController
{
    public function __construct(
        private readonly PriceCompareService $priceCompareService,
    ) {
    }

    /** GET /admin/shop/price-compare */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $baseUrl = rtrim($context->getRequest()->getSchemeAndHost(), '/');

        $builtinCodes = BuiltinChannels::codes();
        $states = $this->priceCompareService->channelStates($domainId);

        $channels = [];
        foreach ($this->priceCompareService->channels() as $channel) {
            $code = $channel->code();
            $state = $states[$code] ?? [];

            $channels[] = [
                'code'                => $code,
                'label'               => $channel->label(),
                'format'              => strtoupper($channel->format()),
                'builtin'             => in_array($code, $builtinCodes, true),
                'active'              => (bool) ($state['is_active'] ?? false),
                // 입력칸에는 운영자 지정값만 채운다. 기본값은 placeholder 로 보여줘야
                // "비우면 기본값으로 돌아간다"가 화면에서 읽힌다.
                'campaignKey'         => (string) ($state['campaign_key'] ?? ''),
                'defaultCampaignKey'  => $channel->defaultCampaignKey(),
                'guide'               => $channel->guide(),
                'feedUrl'             => $baseUrl . '/shop/price-compare/' . $code,
                'summaryUrl'          => $baseUrl . '/shop/price-compare/' . $code . '/summary',
            ];
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/PriceCompare/Index')
            ->withData([
                'pageTitle' => '가격비교 사이트',
                'channels'  => $channels,
            ]);
    }

    /** POST /admin/shop/price-compare/store */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        $request = $context->getRequest();

        $submitted = $request->input('channels');
        $activeCodes = is_array($submitted)
            ? array_values(array_filter(array_map('strval', $submitted), fn(string $c): bool => $c !== ''))
            : [];

        $submittedKeys = $request->input('campaign_keys');
        $campaignKeys = [];
        if (is_array($submittedKeys)) {
            foreach ($submittedKeys as $code => $key) {
                $campaignKeys[(string) $code] = is_scalar($key) ? (string) $key : '';
            }
        }

        $result = $this->priceCompareService->saveChannels($domainId, $activeCodes, $campaignKeys);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }
}
