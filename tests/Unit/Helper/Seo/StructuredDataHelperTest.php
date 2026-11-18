<?php
declare(strict_types=1);
/**
 * tests/Unit/Helper/Seo/StructuredDataHelperTest.php
 *
 * StructuredDataHelper 테스트
 *
 * 이 헬퍼의 핵심 계약은 "값이 없으면 마크업을 만들지 않는다" 이다.
 * 보이는 콘텐츠와 어긋나는 구조화데이터는 없는 것보다 나쁘기 때문에
 * (리치결과 제외 사유가 된다) 빈 값 처리를 집중적으로 검증한다.
 */

namespace Tests\Unit\Helper\Seo;

use PHPUnit\Framework\TestCase;
use Mublo\Helper\Seo\StructuredDataHelper;

class StructuredDataHelperTest extends TestCase
{
    private const BASE_URL = 'https://example.kr';

    private function sampleCompany(): array
    {
        return [
            'name'            => '무블로렌탈',
            'cs_tel'          => '1588-0000',
            'email'           => 'help@example.kr',
            'business_number' => '123-45-67890',
            'zipcode'         => '06253',
            'address'         => '서울시 강남구 테헤란로 1',
            'address_detail'  => '5층',
        ];
    }

    // ── FAQPage ────────────────────────────────────────────────────────────

    public function testFaqPageReturnsNullWhenNoItems(): void
    {
        $this->assertNull(StructuredDataHelper::faqPage([]));
    }

    public function testFaqPageSkipsItemsMissingQuestionOrAnswer(): void
    {
        $result = StructuredDataHelper::faqPage([
            ['faq_question' => '설치비가 있나요?', 'faq_answer' => '무료입니다.'],
            ['faq_question' => '',                'faq_answer' => '답변만 있음'],
            ['faq_question' => '질문만 있음',      'faq_answer' => '   '],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['mainEntity']);
        $this->assertSame('설치비가 있나요?', $result['mainEntity'][0]['name']);
    }

    public function testFaqPageReturnsNullWhenAllItemsInvalid(): void
    {
        // 행은 있지만 쓸 수 있는 문답이 없으면 빈 FAQPage 대신 null 이어야 한다
        $result = StructuredDataHelper::faqPage([
            ['faq_question' => '', 'faq_answer' => ''],
            ['faq_question' => '질문', 'faq_answer' => ''],
        ]);

        $this->assertNull($result);
    }

    public function testFaqPageStripsHtmlFromAnswer(): void
    {
        $result = StructuredDataHelper::faqPage([
            ['faq_question' => '약정은?', 'faq_answer' => '<p>3년 또는 <b>5년</b></p>'],
        ]);

        $this->assertSame('3년 또는 5년', $result['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testFaqPageHasCorrectSchemaShape(): void
    {
        $result = StructuredDataHelper::faqPage([
            ['faq_question' => 'Q', 'faq_answer' => 'A'],
        ]);

        $this->assertSame('https://schema.org', $result['@context']);
        $this->assertSame('FAQPage', $result['@type']);
        $this->assertSame('Question', $result['mainEntity'][0]['@type']);
        $this->assertSame('Answer', $result['mainEntity'][0]['acceptedAnswer']['@type']);
    }

    public function testFaqPageAcceptsCustomColumnNames(): void
    {
        $result = StructuredDataHelper::faqPage(
            [['q' => '질문', 'a' => '답변']],
            'q',
            'a'
        );

        $this->assertSame('질문', $result['mainEntity'][0]['name']);
    }

    // ── FAQPage (카테고리 그룹) ─────────────────────────────────────────────

    public function testFaqPageFromGroupedFlattensAllCategories(): void
    {
        $result = StructuredDataHelper::faqPageFromGrouped([
            ['category_name' => '약정', 'items' => [
                ['question' => '약정은?', 'answer' => '3년 또는 5년'],
                ['question' => '해지는?', 'answer' => '의무기간 후 가능'],
            ]],
            ['category_name' => '설치', 'items' => [
                ['question' => '설치비는?', 'answer' => '무료'],
            ]],
        ]);

        $this->assertCount(3, $result['mainEntity']);
        $this->assertSame('약정은?', $result['mainEntity'][0]['name']);
        $this->assertSame('설치비는?', $result['mainEntity'][2]['name']);
    }

    public function testFaqPageFromGroupedReturnsNullWhenNoItems(): void
    {
        $this->assertNull(StructuredDataHelper::faqPageFromGrouped([]));
        // 카테고리는 있지만 항목이 비어 있는 경우
        $this->assertNull(StructuredDataHelper::faqPageFromGrouped([
            ['category_name' => '약정', 'items' => []],
        ]));
    }

    public function testFaqPageFromGroupedToleratesMissingItemsKey(): void
    {
        $result = StructuredDataHelper::faqPageFromGrouped([
            ['category_name' => '빈 카테고리'],
            ['category_name' => '설치', 'items' => [['question' => 'Q', 'answer' => 'A']]],
        ]);

        $this->assertCount(1, $result['mainEntity']);
    }

    // ── BreadcrumbList ─────────────────────────────────────────────────────

    public function testBreadcrumbListReturnsNullWhenEmpty(): void
    {
        $this->assertNull(StructuredDataHelper::breadcrumbList([]));
        $this->assertNull(StructuredDataHelper::breadcrumbList([['name' => '', 'item' => '/a']]));
    }

    public function testBreadcrumbListOmitsItemOnLastCrumb(): void
    {
        // 현재 페이지에는 item 을 붙이지 않는 것이 구글 권장이다
        $result = StructuredDataHelper::breadcrumbList([
            ['name' => '홈',   'item' => 'https://a.kr/'],
            ['name' => '렌탈', 'item' => 'https://a.kr/rental/goods'],
            ['name' => '정수기', 'item' => null],
        ]);

        $this->assertCount(3, $result['itemListElement']);
        $this->assertSame('https://a.kr/', $result['itemListElement'][0]['item']);
        $this->assertArrayNotHasKey('item', $result['itemListElement'][2]);
    }

    public function testBreadcrumbListRenumbersAfterSkippingBlankNames(): void
    {
        // 이름 없는 항목을 건너뛴 뒤에도 position 은 1부터 연속이어야 한다
        $result = StructuredDataHelper::breadcrumbList([
            ['name' => '홈',   'item' => 'https://a.kr/'],
            ['name' => '',     'item' => 'https://a.kr/broken'],
            ['name' => '정수기', 'item' => null],
        ]);

        $positions = array_column($result['itemListElement'], 'position');
        $this->assertSame([1, 2], $positions);
    }

    // ── Organization ───────────────────────────────────────────────────────

    public function testOrganizationReturnsNullWhenNoIdentity(): void
    {
        // 신규 테넌트: 회사 정보도 사이트 제목도 없는 상태
        $this->assertNull(StructuredDataHelper::organization([], [], [], [], self::BASE_URL));
    }

    public function testOrganizationReturnsNullWhenBaseUrlEmpty(): void
    {
        $this->assertNull(
            StructuredDataHelper::organization($this->sampleCompany(), [], [], [], '')
        );
    }

    public function testOrganizationFallsBackToSiteTitle(): void
    {
        $result = StructuredDataHelper::organization(
            [],
            ['site_title' => '위즈샵'],
            [],
            [],
            self::BASE_URL
        );

        $this->assertSame('위즈샵', $result['name']);
    }

    public function testOrganizationBuildsFullSchema(): void
    {
        $result = StructuredDataHelper::organization(
            $this->sampleCompany(),
            ['site_title' => '위즈샵'],
            [],
            [],
            self::BASE_URL
        );

        // 상호가 사이트 제목보다 우선
        $this->assertSame('무블로렌탈', $result['name']);
        $this->assertSame(self::BASE_URL . '/', $result['url']);
        $this->assertSame('서울시 강남구 테헤란로 1 5층', $result['address']['streetAddress']);
        $this->assertSame('06253', $result['address']['postalCode']);
        $this->assertSame('KR', $result['address']['addressCountry']);
        $this->assertSame('1588-0000', $result['contactPoint']['telephone']);
        $this->assertSame('123-45-67890', $result['identifier']['value']);
    }

    public function testOrganizationOmitsAddressWhenAbsent(): void
    {
        $result = StructuredDataHelper::organization(
            ['name' => '무블로'],
            [],
            [],
            [],
            self::BASE_URL
        );

        $this->assertArrayNotHasKey('address', $result);
        $this->assertArrayNotHasKey('contactPoint', $result);
        $this->assertArrayNotHasKey('identifier', $result);
        $this->assertArrayNotHasKey('sameAs', $result);
    }

    public function testOrganizationConvertsRelativeLogoToAbsoluteUrl(): void
    {
        $result = StructuredDataHelper::organization(
            ['name' => '무블로'],
            [],
            [],
            ['logo_pc' => '/storage/logo.png'],
            self::BASE_URL
        );

        $this->assertSame(self::BASE_URL . '/storage/logo.png', $result['logo']);
    }

    public function testOrganizationKeepsAbsoluteLogoUrl(): void
    {
        $result = StructuredDataHelper::organization(
            ['name' => '무블로'],
            [],
            [],
            ['logo_pc' => 'https://cdn.example.com/logo.png'],
            self::BASE_URL
        );

        $this->assertSame('https://cdn.example.com/logo.png', $result['logo']);
    }

    public function testOrganizationFiltersIncompleteAndNonHttpSnsChannels(): void
    {
        $result = StructuredDataHelper::organization(
            ['name' => '무블로'],
            [],
            ['sns_channels' => [
                ['type' => 'youtube',   'url' => 'https://youtube.com/@a'],
                ['type' => 'instagram', 'url' => ''],                       // URL 없음
                ['type' => '',          'url' => 'https://x.com/a'],        // 종류 없음
                ['type' => 'blog',      'url' => 'javascript:alert(1)'],    // http(s) 아님
            ]],
            [],
            self::BASE_URL
        );

        $this->assertSame(['https://youtube.com/@a'], $result['sameAs']);
    }

    // ── WebSite ────────────────────────────────────────────────────────────

    public function testWebSiteReturnsNullWithoutSiteTitle(): void
    {
        $this->assertNull(StructuredDataHelper::webSite([], self::BASE_URL));
    }

    public function testWebSiteSearchTargetMatchesSearchControllerParam(): void
    {
        $result = StructuredDataHelper::webSite(['site_title' => '위즈샵'], self::BASE_URL);

        // SearchController 는 'q' 를 읽는다. 여기가 어긋나면 검색 액션이 동작하지 않는다.
        $this->assertSame(
            self::BASE_URL . '/search?q={search_term_string}',
            $result['potentialAction']['target']['urlTemplate']
        );
    }

    // ── Article ────────────────────────────────────────────────────────────

    private function sampleArticle(): array
    {
        return [
            'title'       => '정수기 렌탈 비교 가이드',
            'content'     => '<p>약정별   요금을 <b>비교</b>합니다.</p>',
            'author_name' => '홍길동',
            'created_at'  => '2026-01-05 10:00:00',
            'updated_at'  => '2026-02-11 09:30:00',
            'status'      => 'published',
            'is_secret'   => 0,
        ];
    }

    public function testArticleReturnsNullForSecretPost(): void
    {
        $article = $this->sampleArticle();
        $article['is_secret'] = 1;

        $this->assertNull(StructuredDataHelper::article($article, 'https://a.kr/board/n/view/1'));
    }

    public function testArticleReturnsNullForUnpublishedPost(): void
    {
        $article = $this->sampleArticle();
        $article['status'] = 'draft';

        $this->assertNull(StructuredDataHelper::article($article, 'https://a.kr/board/n/view/1'));
    }

    public function testArticleReturnsNullWithoutTitleOrUrl(): void
    {
        $this->assertNull(StructuredDataHelper::article([], 'https://a.kr/x'));
        $this->assertNull(StructuredDataHelper::article($this->sampleArticle(), ''));
    }

    public function testArticleBuildsExpectedFields(): void
    {
        $result = StructuredDataHelper::article(
            $this->sampleArticle(),
            'https://a.kr/board/n/view/1',
            '위즈샵'
        );

        $this->assertSame('Article', $result['@type']);
        $this->assertSame('정수기 렌탈 비교 가이드', $result['headline']);
        $this->assertSame('2026-01-05', $result['datePublished']);
        $this->assertSame('2026-02-11', $result['dateModified']);
        $this->assertSame('홍길동', $result['author']['name']);
        $this->assertSame('위즈샵', $result['publisher']['name']);
        $this->assertSame('https://a.kr/board/n/view/1', $result['mainEntityOfPage']['@id']);
        // HTML 제거 + 공백 정규화
        $this->assertSame('약정별 요금을 비교합니다.', $result['description']);
    }

    public function testArticleFallsBackToPublishedDateWhenNoUpdatedAt(): void
    {
        $article = $this->sampleArticle();
        unset($article['updated_at']);

        $result = StructuredDataHelper::article($article, 'https://a.kr/x');

        $this->assertSame('2026-01-05', $result['dateModified']);
    }

    public function testArticleOmitsDateModifiedWhenAllDatesUnparseable(): void
    {
        $article = $this->sampleArticle();
        $article['created_at'] = '';
        $article['updated_at'] = 'not-a-date';

        $result = StructuredDataHelper::article($article, 'https://a.kr/x');

        $this->assertArrayNotHasKey('datePublished', $result);
        $this->assertArrayNotHasKey('dateModified', $result);
    }

    public function testArticleDecodesEscapedAuthorName(): void
    {
        // Presenter 가 htmlspecialchars 로 이스케이프한 값이 그대로 들어온다
        $article = $this->sampleArticle();
        $article['author_name'] = 'A&amp;B';

        $result = StructuredDataHelper::article($article, 'https://a.kr/x');

        $this->assertSame('A&B', $result['author']['name']);
    }

    // ── toScriptTag ────────────────────────────────────────────────────────

    public function testToScriptTagReturnsEmptyStringForNull(): void
    {
        $this->assertSame('', StructuredDataHelper::toScriptTag(null));
        $this->assertSame('', StructuredDataHelper::toScriptTag([]));
    }

    public function testToScriptTagEscapesClosingScriptTag(): void
    {
        // 값에 </script> 가 섞이면 태그가 조기 종료돼 XSS 통로가 된다
        $html = StructuredDataHelper::toScriptTag(['name' => '</script><img src=x onerror=alert(1)>']);

        $this->assertStringNotContainsString('</script><img', $html);
        $this->assertStringEndsWith('</script>', $html);
        $this->assertStringContainsString('<', $html);
    }

    public function testToScriptTagKeepsKoreanReadable(): void
    {
        $html = StructuredDataHelper::toScriptTag(['name' => '무블로렌탈']);

        $this->assertStringContainsString('무블로렌탈', $html);
    }
}
