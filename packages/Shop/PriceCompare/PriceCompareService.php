<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\PriceCompare;

use Mublo\Core\Registry\ContractRegistry;
use Mublo\Packages\Shop\Contract\PriceCompare\PriceCompareChannelInterface;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\PriceCompare\Writer\RssWriter;
use Mublo\Packages\Shop\PriceCompare\Writer\TsvWriter;
use Mublo\Packages\Shop\Repository\PriceCompareChannelRepository;

/**
 * 가격비교 피드 조립
 *
 * 채널 목록은 ContractRegistry 에서 온다. Shop 은 어떤 채널이 등록되어 있는지
 * 미리 알지 못하고, 그래서 채널이 늘어도 이 클래스와 라우트·화면이 바뀌지 않는다.
 *
 * 피드는 요청 시점에 만든다. 상품 조회는 조각내어 읽지만 완성된 텍스트는 한 번에
 * 메모리에 올라간다(코어에 스트리밍 응답이 없다). 상품이 많아 이것이 부담이 되면
 * 여기서 파일로 쓰고 라우트가 그 파일을 내주는 형태로 갈라지면 된다 — 상품 조회와
 * 채널 변환은 그대로 쓸 수 있다.
 */
final class PriceCompareService
{
    /**
     * 변경분 피드가 담는 기간 — 당일(자정 이후) 변경분.
     *
     * 비교사는 전체 피드와 별도로 "바뀐 것만" 자주 받아가 가격 반영을 앞당긴다.
     * 국내 비교사 피드의 관례가 당일 기준이라 그것을 따른다.
     *
     * 자정 직후에 수집하면 어제 저녁 변경이 어느 요약에도 실리지 않는다. 그것을
     * 메우는 것이 전체 피드이고, 이 생태계는 그 전제로 돌아간다 — 진실은 전체
     * 피드이며 요약은 반영을 앞당기는 수단일 뿐이다.
     *
     * 판정은 날짜 문자열을 잘라 비교하는 형태로 하지 않는다. 컬럼에 함수를 씌우면
     * 인덱스를 못 쓴다. 같은 뜻을 범위 비교로 낸다.
     */
    private const SUMMARY_WINDOW = 'today';

    public function __construct(
        private readonly ContractRegistry $registry,
        private readonly ProductFeedQuery $query,
        private readonly TsvWriter $tsvWriter,
        private readonly RssWriter $rssWriter,
        private readonly PriceCompareChannelRepository $channelRepository,
    ) {
    }

    /**
     * 채널별 저장 상태. 행이 없는 채널은 꺼진 것으로 본다.
     *
     * 등록 단계가 아니라 서빙 단계에서 읽는다. 부팅에서 읽으면 피드와 무관한 모든
     * 요청에 쿼리가 한 번씩 붙는데, 이 기능은 비교사 크롤러와 안내 화면만 쓴다.
     *
     * @return array<string, array{is_active: bool, campaign_key: string}>
     *         campaign_key 는 운영자가 지정한 값만 담는다(미지정이면 빈 문자열).
     */
    public function channelStates(int $domainId): array
    {
        $states = [];

        foreach ($this->channelRepository->findByDomain($domainId) as $code => $row) {
            $settings = json_decode((string) ($row['settings'] ?? ''), true);

            $states[$code] = [
                'is_active'    => (bool) ($row['is_active'] ?? false),
                'campaign_key' => is_array($settings) ? trim((string) ($settings['campaign_key'] ?? '')) : '',
            ];
        }

        return $states;
    }

    /**
     * 실제로 링크에 붙는 캠페인키. 운영자 지정값이 없으면 채널 기본값.
     *
     * 통계 도구에 이미 쓰던 키가 있으면 그것을 계속 쓸 수 있어야 한다. 그래서 설정이
     * 우선하고, 코드에 선언된 값은 폴백이다.
     */
    public function campaignKey(PriceCompareChannelInterface $channel, int $domainId): string
    {
        $override = $this->channelStates($domainId)[$channel->code()]['campaign_key'] ?? '';

        return $override !== '' ? $override : $channel->defaultCampaignKey();
    }

    /**
     * 채널 사용 여부와 캠페인키 저장.
     *
     * 제출된 코드만 켜고 나머지는 끈다. 등록된 채널 전체를 대상으로 하므로, 화면에서
     * 체크를 푼 채널이 켜진 상태로 남지 않는다.
     *
     * 캠페인키를 비우면 저장에서 지워 채널 기본값으로 되돌아간다.
     *
     * @param list<string>          $activeCodes
     * @param array<string, string> $campaignKeys code => 운영자 지정 캠페인키
     */
    public function saveChannels(int $domainId, array $activeCodes, array $campaignKeys = []): Result
    {
        $known = array_map(
            static fn(PriceCompareChannelInterface $channel): string => $channel->code(),
            $this->channels()
        );

        $unknown = array_merge(
            array_diff($activeCodes, $known),
            array_diff(array_keys($campaignKeys), $known)
        );
        if ($unknown !== []) {
            return Result::failure('등록되지 않은 채널입니다: ' . implode(', ', array_unique($unknown)));
        }

        foreach ($campaignKeys as $code => $key) {
            $key = trim((string) $key);
            // 방문자 통계가 쿼리스트링으로 받는 값이다. 공백이나 & = 같은 문자가 섞이면
            // 링크가 깨지거나 다른 파라미터로 읽힌다.
            if ($key !== '' && preg_match('/^[A-Za-z0-9._-]{1,100}$/', $key) !== 1) {
                return Result::failure(
                    "캠페인키 '{$key}' 를 쓸 수 없습니다. 영문·숫자와 . _ - 만 100자까지 가능합니다."
                );
            }
        }

        foreach ($known as $code) {
            $key = trim((string) ($campaignKeys[$code] ?? ''));

            $this->channelRepository->save(
                $domainId,
                $code,
                in_array($code, $activeCodes, true),
                ['campaign_key' => $key === '' ? null : $key]
            );
        }

        return Result::success('가격비교 채널 설정이 저장되었습니다.');
    }

    /**
     * 등록된 채널 목록 (관리자 안내 화면이 이 순서로 렌더한다).
     *
     * @return list<PriceCompareChannelInterface>
     */
    public function channels(): array
    {
        $channels = [];

        foreach ($this->registry->keys(PriceCompareChannelInterface::class) as $key) {
            $channel = $this->channel((string) $key);
            if ($channel !== null) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * 채널 하나의 피드. 채널이 없거나 형식을 지원하지 않으면 null.
     *
     * $summary 는 형식이나 컬럼을 바꾸지 않는다. 담기는 상품만 최근 변경분으로 좁힌다.
     * 그래서 채널은 변경분 피드의 존재를 몰라도 되고, 새 채널이 자동으로 얻는다.
     */
    public function render(string $code, int $domainId, string $baseUrl, bool $summary = false): ?FeedOutput
    {
        $channel = $this->channel($code);
        if ($channel === null) {
            return null;
        }

        $state = $this->channelStates($domainId)[$code] ?? null;

        // 켜지 않은 채널은 없는 것처럼 응답한다. 피드는 전 상품을 한 번에 내주므로
        // 운영자가 열기로 결정하기 전에는 내용을 내주지 않는다.
        if (!($state['is_active'] ?? false)) {
            return null;
        }

        $campaignKey = ($state['campaign_key'] ?? '') !== ''
            ? $state['campaign_key']
            : $channel->defaultCampaignKey();

        $format = strtolower($channel->format());
        if (!in_array($format, ['tsv', 'rss'], true)) {
            error_log(sprintf(
                "[Shop] 가격비교 채널 '%s' 의 형식 '%s' 을 낼 라이터가 없습니다.",
                $code,
                $format
            ));

            return null;
        }

        $columns = $channel->columns();
        $rows = [];
        $updatedSince = $summary
            ? (new \DateTimeImmutable(self::SUMMARY_WINDOW))->format('Y-m-d H:i:s')
            : null;

        foreach ($this->query->items($domainId, $baseUrl, $updatedSince, $campaignKey) as $item) {
            $row = $channel->row($item);

            // 컬럼 수가 어긋난 행은 그 줄부터 파일을 밀어버린다. 채널 구현이 컬럼을
            // 늘리다 한쪽만 고친 경우가 대부분이라, 조용히 버리지 않고 남긴다.
            if (count($row) !== count($columns)) {
                error_log(sprintf(
                    "[Shop] 가격비교 채널 '%s' — 상품 %d 의 컬럼 수가 %d/%d 로 어긋나 건너뜁니다.",
                    $code,
                    $item->goodsId,
                    count($row),
                    count($columns)
                ));
                continue;
            }

            $rows[] = $row;
        }

        if ($format === 'rss') {
            // 피드 제목은 매칭에 쓰이지 않는다. 어느 사이트의 피드인지만 알 수 있으면
            // 되므로 호스트를 그대로 쓰고, 쇼핑몰 설정을 여기까지 끌어오지 않는다.
            return new FeedOutput(
                $this->rssWriter->render($columns, $rows, $baseUrl, $baseUrl),
                'application/xml; charset=utf-8'
            );
        }

        return new FeedOutput(
            $this->tsvWriter->render($columns, $rows),
            'text/plain; charset=utf-8'
        );
    }

    /** 등록 조회는 확장 실패가 사이트를 멈추지 않도록 감싼다. */
    private function channel(string $code): ?PriceCompareChannelInterface
    {
        if ($code === '' || !$this->registry->hasKey(PriceCompareChannelInterface::class, $code)) {
            return null;
        }

        try {
            $channel = $this->registry->get(PriceCompareChannelInterface::class, $code);
        } catch (\Throwable $e) {
            error_log("[Shop] 가격비교 채널 '{$code}' 생성 실패: " . $e->getMessage());

            return null;
        }

        return $channel instanceof PriceCompareChannelInterface ? $channel : null;
    }
}
