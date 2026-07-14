<?php

namespace Mublo\Plugin\VisitorStats\Service;

use Mublo\Core\Session\SessionInterface;
use Mublo\Plugin\VisitorStats\Repository\VisitorLogRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorDailyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorHourlyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorPageRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorReferrerRepository;

/**
 * VisitorCollector
 *
 * 방문자 수집 + 실시간 집계
 * 세션 기반으로 UV/PV를 구분하여 모든 집계 테이블을 갱신
 */
class VisitorCollector
{
    /** 원본 로그 자동 퍼지 — 1/N 확률로 실행 (UV 신규 세션 경로에서만) */
    private const AUTO_PURGE_PROBABILITY = 200;

    /** 원본 로그 보존 일수 (집계 테이블은 영구 보존, 원본 IP 로그만 정리) */
    private const AUTO_PURGE_RETENTION_DAYS = 30;

    /** 검색엔진 도메인 목록 */
    private const SEARCH_ENGINES = [
        'google' => 'google',
        'naver' => 'naver',
        'daum' => 'daum',
        'bing' => 'bing',
        'yahoo' => 'yahoo',
        'zum' => 'zum',
        'duckduckgo' => 'duckduckgo',
    ];

    /** SNS 도메인 목록 */
    private const SOCIAL_DOMAINS = [
        'facebook' => 'facebook',
        'fb.com' => 'facebook',
        'instagram' => 'instagram',
        'twitter' => 'twitter',
        't.co' => 'twitter',
        'x.com' => 'twitter',
        'youtube' => 'youtube',
        'tiktok' => 'tiktok',
        'kakaostory' => 'kakaostory',
        'band.us' => 'band',
    ];

    public function __construct(
        private VisitorLogRepository $logRepo,
        private VisitorDailyRepository $dailyRepo,
        private VisitorHourlyRepository $hourlyRepo,
        private VisitorPageRepository $pageRepo,
        private VisitorReferrerRepository $referrerRepo,
        private VisitorCampaignRepository $campaignRepo,
        private SessionInterface $session,
    ) {}

    /**
     * 방문 추적
     */
    public function track(
        int $domainId,
        string $ipAddress,
        string $userAgent,
        string $uri,
        ?string $referer,
        ?int $memberId,
        string $siteDomain,
        ?string $campaignKey = null
    ): void {
        $today = date('Y-m-d');
        $hour = (int) date('G');
        $sessionKey = 'visitor_tracked_' . $today;

        // 캠페인 키: 새 키가 있으면 세션에 저장, 없으면 세션에서 복원
        if ($campaignKey !== null && $campaignKey !== '') {
            $this->session->set(\Mublo\Contract\Tracking\TrackingKeys::CAMPAIGN_KEY, $campaignKey);
        } else {
            $campaignKey = $this->session->get(\Mublo\Contract\Tracking\TrackingKeys::CAMPAIGN_KEY);
        }

        // 쿼리스트링 제거한 페이지 URL
        $pageUrl = $this->cleanUrl($uri);

        // 이미 UV 카운트 완료 → PV만 증분
        if ($this->session->has($sessionKey)) {
            $this->incrementPageviewOnly($domainId, $today, $hour, $pageUrl, $campaignKey);
            return;
        }

        // UV: 신규 세션 처리
        $sessionId = $this->session->getId() ?: bin2hex(random_bytes(16));
        $parsed = UserAgentParser::parse($userAgent);
        $refererInfo = $this->parseReferer($referer, $siteDomain);

        // 내부 리퍼러면 집계 제외 (리퍼러 타입만)
        if ($refererInfo['type'] === 'internal') {
            $refererInfo['type'] = 'direct';
            $refererInfo['domain'] = '';
            $refererInfo['url'] = null;
        }

        $isNew = $this->logRepo->isNewVisitor($domainId, $ipAddress, $today);

        // 로그 INSERT IGNORE
        $inserted = $this->logRepo->insertIgnore([
            'domain_id'      => $domainId,
            'session_id'     => $sessionId,
            'member_id'      => $memberId,
            'ip_address'     => $ipAddress,
            'user_agent'     => mb_substr($userAgent, 0, 500),
            'browser'        => $parsed['browser'],
            'os'             => $parsed['os'],
            'device'         => $parsed['device'],
            'referer_url'    => $refererInfo['url'] ? mb_substr($refererInfo['url'], 0, 500) : null,
            'referer_domain' => $refererInfo['domain'] ? mb_substr($refererInfo['domain'], 0, 200) : null,
            'referer_type'   => $refererInfo['type'],
            'landing_url'    => mb_substr($pageUrl, 0, 500),
            'campaign_key'   => $campaignKey ? mb_substr($campaignKey, 0, 100) : null,
            'is_new'         => $isNew ? 1 : 0,
            'visit_date'     => $today,
            'visit_hour'     => $hour,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        if (!$inserted) {
            // UK 중복: 이미 이 세션의 로그가 있음 → PV만 증분
            $this->incrementPageviewOnly($domainId, $today, $hour, $pageUrl, $campaignKey);
            $this->session->set($sessionKey, true);
            return;
        }

        // 모든 집계 UV + PV 증분
        $flags = [
            'is_new'    => $isNew,
            'is_member' => $memberId !== null,
            'device'    => $parsed['device'],
        ];

        $this->dailyRepo->incrementVisitor($domainId, $today, $flags);
        $this->hourlyRepo->incrementVisitor($domainId, $today, $hour);
        $this->pageRepo->incrementVisitor($domainId, $today, $pageUrl);

        $this->referrerRepo->incrementVisitor(
            $domainId, $today, $refererInfo['type'], $refererInfo['domain']
        );

        // 캠페인 키 집계
        if ($campaignKey) {
            $this->campaignRepo->incrementVisitor($domainId, $today, $campaignKey);
        }

        // 세션 마킹
        $this->session->set($sessionKey, true);

        // 원본 로그 자동 퍼지 (세션 GC 방식의 확률적 실행) —
        // 관리자의 수동 정리에만 의존하면 IP가 담긴 원본 로그가 무기한 쌓인다.
        // UV 경로에서만 낮은 확률로 실행되므로 요청 비용은 무시할 수준이다.
        // 집계 테이블은 건드리지 않는다 (통계는 보존, 원본만 정리).
        if (random_int(1, self::AUTO_PURGE_PROBABILITY) === 1) {
            $this->logRepo->purgeOldLogs(self::AUTO_PURGE_RETENTION_DAYS, $domainId);
        }
    }

    /**
     * PV만 증분 (UV 제외)
     */
    private function incrementPageviewOnly(int $domainId, string $date, int $hour, string $pageUrl, ?string $campaignKey = null): void
    {
        $this->dailyRepo->incrementPageview($domainId, $date);
        $this->hourlyRepo->incrementPageview($domainId, $date, $hour);
        $this->pageRepo->incrementPageview($domainId, $date, $pageUrl);

        if ($campaignKey) {
            $this->campaignRepo->incrementPageview($domainId, $date, $campaignKey);
        }
    }

    /**
     * 리퍼러 파싱
     *
     * @return array{type: string, domain: string, url: string|null}
     */
    private function parseReferer(?string $referer, string $siteDomain): array
    {
        if (empty($referer)) {
            return ['type' => 'direct', 'domain' => '', 'url' => null];
        }

        $parsed = parse_url($referer);
        $host = strtolower($parsed['host'] ?? '');

        if ($host === '') {
            return ['type' => 'direct', 'domain' => '', 'url' => null];
        }

        // 내부 링크
        $siteDomainLower = strtolower($siteDomain);
        if ($host === $siteDomainLower || str_ends_with($host, '.' . $siteDomainLower)) {
            return ['type' => 'internal', 'domain' => $host, 'url' => $referer];
        }

        // 검색엔진
        foreach (self::SEARCH_ENGINES as $keyword => $label) {
            if (str_contains($host, $keyword)) {
                return ['type' => 'search', 'domain' => $label, 'url' => $referer];
            }
        }

        // SNS
        foreach (self::SOCIAL_DOMAINS as $keyword => $label) {
            if (str_contains($host, $keyword)) {
                return ['type' => 'social', 'domain' => $label, 'url' => $referer];
            }
        }

        return ['type' => 'external', 'domain' => $host, 'url' => $referer];
    }

    /**
     * URL에서 쿼리스트링/프래그먼트 제거
     */
    private function cleanUrl(string $uri): string
    {
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }
        $pos = strpos($uri, '#');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }
        return $uri ?: '/';
    }
}
