<?php
declare(strict_types=1);

namespace Mublo\Helper\Seo;

use Mublo\Helper\Sns\SnsHelper;

/**
 * StructuredDataHelper
 *
 * 사이트 전역 schema.org JSON-LD 생성기 (순수 함수, 외부 의존성 없음)
 *
 * 여기서 만드는 것은 "페이지 종류와 무관하게 모든 페이지에 붙는" 마크업뿐이다.
 * Product / FAQPage / BreadcrumbList 처럼 페이지별로 달라지는 것은
 * 각 Controller 가 만든다.
 *
 * AEO(AI 답변엔진 최적화) 관점:
 * AI 답변엔진은 "누가 파는 사이트인가"를 확인하지 못하면 인용을 꺼린다.
 * Organization 은 그 신원 정보를 기계가 읽을 수 있게 선언하는 역할을 한다.
 *
 * 모든 메서드는 값이 비면 null 을 돌려준다. 껍데기만 있는 구조화데이터는
 * 없는 것보다 나쁘기 때문이다(보이는 콘텐츠와 불일치 = 정책 위반).
 */
class StructuredDataHelper
{
    /**
     * Organization JSON-LD
     *
     * @param array  $companyConfig 도메인 회사 정보 (name, tel, email, address ...)
     * @param array  $siteConfig    도메인 사이트 설정 (site_title ...)
     * @param array  $seoConfig     도메인 SEO 설정 (sns_channels ...)
     * @param array  $siteImages    사이트 이미지 URL (logo_pc ...)
     * @param string $baseUrl       스킴 포함 호스트 (https://example.com)
     * @return array|null 표시할 정보가 없으면 null
     */
    public static function organization(
        array $companyConfig,
        array $siteConfig,
        array $seoConfig,
        array $siteImages,
        string $baseUrl
    ): ?array {
        // 상호가 없으면 사이트 제목으로 대체한다. 둘 다 없으면 선언할 신원이 없다.
        $name = trim((string) ($companyConfig['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($siteConfig['site_title'] ?? ''));
        }
        if ($name === '' || $baseUrl === '') {
            return null;
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $name,
            'url'      => $baseUrl . '/',
        ];

        $logo = trim((string) ($siteImages['logo_pc'] ?? $seoConfig['logo_pc'] ?? ''));
        if ($logo !== '') {
            $schema['logo'] = self::absoluteUrl($logo, $baseUrl);
        }

        $address = self::buildAddress($companyConfig);
        if ($address !== null) {
            $schema['address'] = $address;
        }

        $contactPoint = self::buildContactPoint($companyConfig);
        if ($contactPoint !== null) {
            $schema['contactPoint'] = $contactPoint;
        }

        // 사업자등록번호 — 국내 사업자 식별자. 답변엔진이 실재하는 사업자인지
        // 대조할 수 있는 가장 강한 신호다.
        $bizNo = trim((string) ($companyConfig['business_number'] ?? ''));
        if ($bizNo !== '') {
            $schema['identifier'] = [
                '@type' => 'PropertyValue',
                'name'  => '사업자등록번호',
                'value' => $bizNo,
            ];
        }

        // sameAs — 공식 SNS 채널. 신원 교차검증에 쓰인다.
        $sameAs = [];
        foreach (SnsHelper::filterActiveChannels($seoConfig['sns_channels'] ?? []) as $channel) {
            $url = trim((string) ($channel['url'] ?? ''));
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $sameAs[] = $url;
            }
        }
        if ($sameAs !== []) {
            $schema['sameAs'] = array_values(array_unique($sameAs));
        }

        return $schema;
    }

    /**
     * WebSite JSON-LD (사이트 내 검색 액션 포함)
     *
     * @param array  $siteConfig 도메인 사이트 설정
     * @param string $baseUrl    스킴 포함 호스트
     * @return array|null 사이트 제목이 없으면 null
     */
    public static function webSite(array $siteConfig, string $baseUrl): ?array
    {
        $name = trim((string) ($siteConfig['site_title'] ?? ''));
        if ($name === '' || $baseUrl === '') {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $name,
            'url'      => $baseUrl . '/',
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    // SearchController 는 파라미터명 'q' 를 읽는다
                    'urlTemplate' => $baseUrl . '/search?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * 카테고리별로 묶인 FAQ 목록에서 FAQPage JSON-LD 생성
     *
     * Faq 플러그인의 $grouped 구조를 그대로 받는다:
     *   [['category_name' => ..., 'items' => [['question' => ..., 'answer' => ...], ...]], ...]
     *
     * FAQPage 는 카테고리 구분을 표현하지 않으므로 전부 평탄화해 하나의 mainEntity 로 낸다.
     * 화면에 카테고리 탭이 있어도 같은 페이지에 렌더되는 문답이면 문제되지 않는다.
     *
     * @return array|null 유효한 문답이 하나도 없으면 null
     */
    public static function faqPageFromGrouped(array $grouped): ?array
    {
        $items = [];

        foreach ($grouped as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $items[] = $item;
            }
        }

        return self::faqPage($items, 'question', 'answer');
    }

    /**
     * BreadcrumbList JSON-LD
     *
     * @param array $trail [['name' => string, 'item' => string|null], ...]
     *                     item 이 null 이면 링크 없는 항목(현재 위치)으로 취급한다.
     * @return array|null 항목이 없으면 null
     */
    public static function breadcrumbList(array $trail): ?array
    {
        $elements = [];
        $position = 0;

        foreach ($trail as $crumb) {
            $name = trim((string) ($crumb['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $position++;
            $entry = [
                '@type'    => 'ListItem',
                'position' => $position,
                'name'     => $name,
            ];

            // 마지막 항목(현재 페이지)은 item 을 생략하는 것이 구글 권장이다.
            $item = trim((string) ($crumb['item'] ?? ''));
            if ($item !== '') {
                $entry['item'] = $item;
            }

            $elements[] = $entry;
        }

        if ($elements === []) {
            return null;
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * FAQPage JSON-LD
     *
     * 화면에 실제로 그려지는 FAQ 목록을 그대로 받아야 한다. 보이지 않는 항목을
     * 끼워넣거나 0건인데 빈 FAQPage 를 내보내면 "구조화데이터와 보이는 콘텐츠 불일치"
     * 로 분류돼 리치결과에서 제외된다.
     *
     * AEO 관점에서 Q&A 는 답변엔진이 가장 직접적으로 인용하는 포맷이다.
     *
     * @param array  $items       FAQ 행 목록
     * @param string $questionKey 질문 컬럼명
     * @param string $answerKey   답변 컬럼명
     * @return array|null 유효한 문답이 하나도 없으면 null
     */
    public static function faqPage(
        array $items,
        string $questionKey = 'faq_question',
        string $answerKey = 'faq_answer'
    ): ?array {
        $entities = [];

        foreach ($items as $item) {
            $question = trim((string) ($item[$questionKey] ?? ''));
            // 답변에 HTML 이 섞여 있어도 구조화데이터에는 평문만 담는다
            $answer = trim(strip_tags((string) ($item[$answerKey] ?? '')));

            if ($question === '' || $answer === '') {
                continue;
            }

            $entities[] = [
                '@type' => 'Question',
                'name'  => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $answer,
                ],
            ];
        }

        if ($entities === []) {
            return null;
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    /**
     * Article JSON-LD (게시글)
     *
     * 비밀글 / 미게시 글은 애초에 호출하지 않는 쪽이 맞지만, 실수로 넘어와도
     * 마크업이 새지 않도록 여기서도 한 번 막는다.
     *
     * 답변엔진은 최신성 신호를 크게 반영하므로 datePublished / dateModified 를
     * 가능한 한 채운다.
     *
     * @param array  $article  게시글 배열 (title, content, created_at, updated_at ...)
     * @param string $url      글의 절대 URL
     * @param string $siteName 발행 주체 이름 (Organization 과 같은 값)
     * @return array|null 제목이 없거나 공개 글이 아니면 null
     */
    public static function article(array $article, string $url, string $siteName = ''): ?array
    {
        if (!empty($article['is_secret']) || (($article['status'] ?? 'published') !== 'published')) {
            return null;
        }

        // Presenter 가 title_safe 를 만들어 두지만 구조화데이터에는 원문이 들어가야 한다
        $title = trim((string) ($article['title'] ?? ''));
        if ($title === '' || $url === '') {
            return null;
        }

        $schema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => mb_substr($title, 0, 110),
            'url'              => $url,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        ];

        $published = self::formatDate((string) ($article['created_at'] ?? ''));
        if ($published !== '') {
            $schema['datePublished'] = $published;
        }

        $modified = self::formatDate((string) ($article['updated_at'] ?? ''));
        $schema['dateModified'] = $modified !== '' ? $modified : ($published ?: null);
        if ($schema['dateModified'] === null) {
            unset($schema['dateModified']);
        }

        // author_name 은 Presenter 가 htmlspecialchars 로 이스케이프해 둔 값이라
        // JSON-LD 에 그대로 넣으면 &amp; 같은 엔티티가 노출된다.
        $author = trim(html_entity_decode((string) ($article['author_name'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($author !== '') {
            $schema['author'] = ['@type' => 'Person', 'name' => $author];
        }

        if ($siteName !== '') {
            $schema['publisher'] = ['@type' => 'Organization', 'name' => $siteName];
        }

        $body = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($article['content'] ?? ''))) ?? '');
        if ($body !== '') {
            $schema['description'] = mb_substr($body, 0, 200);
        }

        return $schema;
    }

    /**
     * JSON-LD 배열을 <script> 태그 문자열로 변환
     *
     * JSON_HEX_TAG 는 필수다. 값에 '</script>' 가 섞이면 태그가 조기 종료돼
     * 마크업이 깨지고 XSS 통로가 된다.
     */
    public static function toScriptTag(?array $schema): string
    {
        if (empty($schema)) {
            return '';
        }

        $json = json_encode(
            $schema,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if ($json === false) {
            return '';
        }

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * PostalAddress — 주소가 하나도 없으면 null
     */
    private static function buildAddress(array $companyConfig): ?array
    {
        $street = trim(
            trim((string) ($companyConfig['address'] ?? ''))
            . ' ' . trim((string) ($companyConfig['address_detail'] ?? ''))
        );
        $zipcode = trim((string) ($companyConfig['zipcode'] ?? ''));

        if ($street === '' && $zipcode === '') {
            return null;
        }

        $address = [
            '@type'          => 'PostalAddress',
            'addressCountry' => 'KR',
        ];
        if ($street !== '') {
            $address['streetAddress'] = $street;
        }
        if ($zipcode !== '') {
            $address['postalCode'] = $zipcode;
        }

        return $address;
    }

    /**
     * ContactPoint — 고객센터 연락처. 전화/이메일이 모두 없으면 null
     */
    private static function buildContactPoint(array $companyConfig): ?array
    {
        $tel = trim((string) ($companyConfig['cs_tel'] ?? $companyConfig['tel'] ?? ''));
        $email = trim((string) ($companyConfig['email'] ?? ''));

        if ($tel === '' && $email === '') {
            return null;
        }

        $contact = [
            '@type'       => 'ContactPoint',
            'contactType' => 'customer service',
            'areaServed'  => 'KR',
            'availableLanguage' => 'Korean',
        ];
        if ($tel !== '') {
            $contact['telephone'] = $tel;
        }
        if ($email !== '') {
            $contact['email'] = $email;
        }

        return $contact;
    }

    /**
     * 날짜 문자열을 ISO 8601 날짜로 정규화 — 파싱 실패 시 빈 문자열
     * (틀린 날짜를 선언하느니 신호를 생략한다)
     */
    private static function formatDate(string $datetime): string
    {
        $datetime = trim($datetime);
        if ($datetime === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($datetime))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 상대 경로 이미지를 절대 URL 로 변환 (구조화데이터는 절대 URL 이어야 한다)
     */
    private static function absoluteUrl(string $path, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }
}
