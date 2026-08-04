<?php
declare(strict_types=1);

namespace Mublo\Contract\Sitemap;

/**
 * 사이트맵 URL 제공 계약
 *
 * Package/Plugin 이 구현하고 코어 SitemapController 가 소비한다.
 * ContractRegistry 에 1:N 등록(register/all)으로 붙는다.
 *
 *   $registry->register(
 *       SitemapUrlProviderInterface::class,
 *       'mshop',
 *       fn() => new MshopSitemapProvider(...)   // Closure = 지연 생성
 *   );
 *
 * 코어는 확장의 테이블을 알지 못한다. 확장이 비활성이면 Provider 가 로드되지
 * 않으므로 URL 도 자동으로 사라진다.
 *
 * ── 구현 시 반드시 지킬 것 ──
 *
 * 1. **공개 콘텐츠만 낸다.** 로그인·권한·비밀글이 걸린 URL 은 절대 포함하지 않는다.
 *    주문서·결제·인증 콜백·작성/수정 폼처럼 개인 정보나 상태가 걸린 경로도 제외한다.
 *    사이트맵은 크롤러에게 "여기를 색인하라"고 알리는 공개 선언이다.
 *
 * 2. **도메인으로 반드시 거른다.** $domainId 로 필터하지 않으면 모든 도메인이
 *    남의 콘텐츠 URL 을 광고하게 된다.
 *
 * 3. **path 만 반환한다.** 호스트를 붙이지 않는다. 코어가 요청 호스트 기준으로
 *    baseUrl 을 붙이므로, 멀티도메인에서 잘못된 호스트가 섞이는 사고를 막는다.
 *
 * 4. **쿼리스트링을 넣지 않는다.** canonical 이 쿼리를 버리므로(FrontViewRenderer)
 *    `/list?cat=1` 을 실으면 페이지가 선언한 canonical 과 불일치해 색인되지 않는다.
 *
 * 5. **빈 목록 페이지를 만들지 않는다.** 내용이 없는 URL 을 대량으로 실으면
 *    크롤 예산만 소모하고 얇은 콘텐츠로 평가된다.
 */
interface SitemapUrlProviderInterface
{
    /**
     * 이 확장이 사이트맵에 실을 공개 URL 목록
     *
     * 대량 데이터는 generator 로 yield 해도 된다(반환 타입이 iterable).
     *
     * @param int $domainId 현재 도메인
     * @return iterable<array{
     *     path: string,
     *     lastmod?: string,
     *     changefreq?: string,
     *     priority?: string
     * }> path 는 '/' 로 시작하는 경로. lastmod 는 'Y-m-d' 또는 파싱 가능한 일시.
     */
    public function sitemapUrls(int $domainId): iterable;
}
