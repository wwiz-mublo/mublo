<?php
declare(strict_types=1);
namespace Mublo\Plugin\Qna\Sitemap;

use Mublo\Contract\Sitemap\SitemapUrlProviderInterface;

/**
 * QnaSitemapProvider
 *
 * Q&A 플러그인의 사이트맵 URL 제공자 — **의도적으로 항상 빈 목록을 반환한다.**
 *
 * ── 왜 실을 URL 이 하나도 없는가 ──
 *
 * 이 플러그인은 게시판이 아니라 1:1 문의 접수창구다. 공개 Q&A 가 아니다.
 * 코드와 스키마 양쪽이 "전건 비공개"를 못박고 있어, 공개로 증명되는 행이
 * 단 하나도 존재하지 않는다.
 *
 * 1. 열람 권한 자체가 작성자 본인/운영자 한정.
 *    QnaPost::canBeViewedBy() 는 `$isAdmin || $memberId === 작성자` 만 통과시킨다.
 *    주석 그대로 "is_secret 값과 무관하게 적용한다 — 문의는 무조건 비공개 정책".
 *    즉 is_secret=0 인 행을 골라내도 제3자는 열람할 수 없다. 스키마의
 *    is_secret 컬럼은 공개 여부의 근거가 되지 못한다(기본값도 1).
 *
 * 2. 프론트 라우트 전체가 로그인 게이트.
 *    QnaController 의 index/show/create/store/reply/destroy 는 모두 첫 줄에서
 *    auth->check() 를 확인하고, 실패 시 /login 으로 리다이렉트한다.
 *    크롤러(비로그인)에게 /qna/{id} 는 항상 로그인 페이지로 튕기는 URL 이다.
 *
 * 3. 목록(/qna)도 "내 문의"뿐.
 *    QnaService::getMyInquiries() 는 회원 본인 글만 조회한다. 비로그인에게는
 *    영구히 빈 화면이므로 계약 5번(빈 목록 페이지 금지)에 정면으로 걸린다.
 *
 * 4. 나머지 경로는 애초에 대상 밖.
 *    /qna/write(작성 폼), /qna/upload, /admin/qna/* 는 계약 1번이 명시적으로
 *    배제하는 작성/수정 폼·관리자 경로다.
 *
 * 따라서 "조건을 잘 걸어 일부만 공개"가 성립하지 않는다. 공개 조합 자체가
 * 스키마에 존재하지 않으므로, 억지로 URL 을 만들어내는 대신 빈 목록을 낸다.
 *
 * ── 나중에 공개 Q&A 를 열게 된다면 ──
 *
 * 정책이 바뀌어 "공개 질문"이 생기는 날에는 (a) canBeViewedBy 가 비로그인
 * 열람을 허용하고, (b) QnaController::show 의 로그인 게이트가 공개글에
 * 한해 풀리고, (c) 공개 여부를 담는 컬럼(예: is_public)이 스키마에
 * 추가된 뒤에야 이 파일을 수정해야 한다. 세 가지가 모두 갖춰지기 전에
 * 여기서 URL 을 내보내면 회원 문의 내용이 검색엔진에 색인된다.
 */
class QnaSitemapProvider implements SitemapUrlProviderInterface
{
    /**
     * 공개 URL 없음 — 상단 주석 참조.
     *
     * @param int $domainId 현재 도메인(공개 대상이 없어 사용하지 않는다)
     * @return iterable<array{path: string, lastmod?: string, changefreq?: string, priority?: string}>
     */
    public function sitemapUrls(int $domainId): iterable
    {
        return [];
    }
}
