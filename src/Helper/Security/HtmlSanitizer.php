<?php
declare(strict_types=1);

namespace Mublo\Helper\Security;

use HTMLPurifier;
use HTMLPurifier_AttrDef_CSS_AlphaValue;
use HTMLPurifier_AttrDef_CSS_Composite;
use HTMLPurifier_AttrDef_CSS_Length;
use HTMLPurifier_AttrDef_CSS_Multiple;
use HTMLPurifier_AttrDef_CSS_Number;
use HTMLPurifier_AttrDef_CSS_Percentage;
use HTMLPurifier_AttrDef_Enum;
use HTMLPurifier_Config;
use Mublo\Helper\Security\Css\GradientAttrDef;
use Mublo\Helper\Security\Css\ShadowAttrDef;

/**
 * HtmlSanitizer
 *
 * HTML 콘텐츠 정화 헬퍼 (HTMLPurifier 기반)
 *
 * 검증된 HTMLPurifier 엔진 위에 Mublo 정책(안전 iframe 화이트리스트,
 * 인라인 CSS 화이트리스트, HTML5 요소 허용)을 얹어 XSS를 차단하면서
 * 안전한 서식/미디어는 보존한다.
 *
 * 프로파일:
 * - rich  : 에디터/게시판 본문 — YouTube/Vimeo 임베드 허용, 좁은 CSS
 * - basic : 폼 HTML 필드 — iframe 불가, 좁은 CSS
 * - block : 블록 HTML — 관리자가 조판하는 채널이므로 레이아웃 CSS까지 허용
 *
 * ## 왜 블록만 CSS 를 넓히는가
 *
 * 게시판 본문은 일반 회원이 쓰는 채널이라 서식 정도면 충분하다. 블록 HTML 은
 * 관리자(스태프 이상)가 화면을 조판하는 채널이므로 flex 레이아웃·그림자·둥근
 * 모서리까지 필요하다. 그러나 **넓히는 것은 CSS 뿐이다.** 스크립트 채널
 * (script 태그, on* 핸들러, javascript: URL)은 세 프로파일 모두 똑같이 막는다.
 * 경계는 "누가 편집하느냐" 가 아니라 "이 값이 스크립트를 실을 수 있느냐" 다.
 *
 * 사용:
 * HtmlSanitizer::sanitize('<div onclick="alert(1)">Hello</div>');
 * // 결과: '<div>Hello</div>'
 */
class HtmlSanitizer
{
    /**
     * rich / basic 프로파일의 인라인 style 화이트리스트 (서식 수준).
     */
    private const BASE_CSS_PROPERTIES = [
        'color', 'background-color',
        'font-size', 'font-family', 'font-weight', 'font-style',
        'text-decoration', 'text-align',
        'width', 'height', 'max-width',
        'margin', 'margin-left', 'margin-right', 'margin-top', 'margin-bottom',
        'padding', 'padding-left', 'padding-right', 'padding-top', 'padding-bottom',
        'border-collapse',
    ];

    /**
     * block 프로파일이 추가로 허용하는 CSS 속성 (레이아웃 수준).
     *
     * 이 중 다수는 HTMLPurifier 코어 CSS 정의에 없어, buildBlockCssDefinition()
     * 에서 값 검증기를 직접 붙인다. 검증기 없이 이름만 넣으면 조용히 무시된다.
     *
     * 값이 숫자·키워드·길이·색·그라디언트뿐이라 스크립트를 실을 수 없는 것만 골랐다.
     * position / z-index (클릭재킹)는 뺐다. background 는 GradientAttrDef 로
     * 그라디언트·단색·同출처 상대 경로 url() 만 통과시킨다 — 외부요청 url()
     * (스킴·프로토콜 상대)은 거기서 막힌다.
     */
    private const BLOCK_EXTRA_CSS_PROPERTIES = [
        'line-height', 'letter-spacing', 'word-break', 'white-space',
        'text-transform', 'vertical-align', 'opacity',
        'min-width', 'min-height', 'max-height',
        'background', 'background-image',
        'border', 'border-radius', 'box-shadow', 'text-shadow',
        'overflow', 'overflow-x', 'overflow-y',
        'display', 'gap', 'row-gap', 'column-gap',
        'flex', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis',
        'justify-content', 'align-items', 'align-content', 'align-self',
    ];

    /**
     * display 로 허용하는 키워드. position:fixed 계열의 레이아웃 탈출을 막기 위해
     * 화이트리스트로 제한한다.
     */
    private const DISPLAY_KEYWORDS = [
        'block', 'inline', 'inline-block', 'flex', 'inline-flex',
        'grid', 'inline-grid', 'none', 'list-item',
        'table', 'inline-table', 'table-cell', 'table-row', 'table-row-group',
        'table-header-group', 'table-footer-group', 'table-caption',
        'table-column', 'table-column-group',
    ];

    /**
     * HTMLPurifier 기본 셋에 없는(HTML5) 추가 허용 요소.
     * [태그, 콘텐츠 모델 타입, 콘텐츠 모델, 속성 컬렉션]
     */
    private const EXTRA_HTML5_ELEMENTS = [
        ['figure', 'Block', 'Flow', 'Common'],
        ['figcaption', 'Block', 'Flow', 'Common'],
        ['section', 'Block', 'Flow', 'Common'],
        ['article', 'Block', 'Flow', 'Common'],
        ['aside', 'Block', 'Flow', 'Common'],
        ['header', 'Block', 'Flow', 'Common'],
        ['footer', 'Block', 'Flow', 'Common'],
        ['main', 'Block', 'Flow', 'Common'],
        ['nav', 'Block', 'Flow', 'Common'],
        ['details', 'Block', 'Flow', 'Common'],
        ['summary', 'Block', 'Flow', 'Common'],
        ['mark', 'Inline', 'Inline', 'Common'],
        ['time', 'Inline', 'Inline', 'Common'],
    ];

    /**
     * block 프로파일이 div 에 허용하는 Mublo 레이아웃 데이터 속성.
     *
     * MubloItemLayout.js 가 읽는 공식 설정 API 만 값 검증기와 함께 연다.
     * 계약 구조(div.mublo-item-layout > ul > li)는 dev-guide/block-system.md 참조.
     *
     * data-swiper(자유 JSON)·data-breakpoint(스킨 전용)는 의도적으로 뺐다 —
     * 렌더러·스킨이 출력하는 신뢰 채널로만 존재한다.
     *
     * 'Number' 는 HTMLPurifier 기준 **양의 정수만** 통과한다(0·음수·단위 탈락).
     * autoplay 0 탈락은 속성 생략과 동작이 같으므로 "끄기 = 생략" 계약이 된다.
     * 상한(30000ms)은 MubloItemLayout.js 가 읽는 쪽에서 클램프한다.
     */
    private const BLOCK_LAYOUT_DATA_ATTRIBUTES = [
        'data-pc-style'       => 'Enum#list,slide,none',
        'data-mo-style'       => 'Enum#list,slide,none',
        'data-pc-cols'        => 'Enum#1,2,3,4,5,6,7,8,9,10,11,12,auto',
        'data-mo-cols'        => 'Enum#1,2,3,4,5,6,7,8,9,10,11,12,auto',
        'data-pc-loop'        => 'Enum#true,false',
        'data-mo-loop'        => 'Enum#true,false',
        'data-pc-slide-cover' => 'Enum#true,false',
        'data-mo-slide-cover' => 'Enum#true,false',
        'data-pc-autoplay'    => 'Number',
        'data-mo-autoplay'    => 'Number',
    ];

    /**
     * 신뢰 iframe 호스트/경로 정규식 (YouTube, YouTube-nocookie, Vimeo)
     */
    private const SAFE_IFRAME_REGEXP =
        '%^(https?:)?//(www\.youtube(?:-nocookie)?\.com/embed/|youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%';

    /**
     * 프로파일별 HTMLPurifier 인스턴스 캐시 (요청 단위)
     *
     * @var array<string, HTMLPurifier>
     */
    private static array $purifiers = [];

    /**
     * HTML 정화 (rich 프로파일)
     *
     * @param string $html 원본 HTML
     * @param array $options (하위호환용) — 현재는 rich 프로파일로 고정 처리
     * @return string 정화된 HTML
     */
    public static function sanitize(string $html, array $options = []): string
    {
        return self::purify($html, 'rich');
    }

    /**
     * 에디터/게시판 본문 저장용 HTML 정화 (rich 프로파일)
     */
    public static function sanitizeEditorContent(string $html): string
    {
        return self::purify($html, 'rich');
    }

    /**
     * 폼 HTML 필드용 정화 (basic 프로파일 — iframe 불가)
     */
    public static function sanitizeBasic(string $html): string
    {
        return self::purify($html, 'basic');
    }

    /**
     * 블록 HTML 정화 (block 프로파일 — 레이아웃 CSS 허용)
     *
     * 관리자가 조판하는 블록 콘텐츠용. flex/그림자/둥근모서리까지 살리되,
     * 스크립트 채널은 rich/basic 과 똑같이 막는다.
     */
    public static function sanitizeForBlock(string $html): string
    {
        return self::purify($html, 'block');
    }

    /**
     * 실제 정화 수행
     *
     * @param string $profile 'rich' | 'basic'
     */
    private static function purify(string $html, string $profile): string
    {
        if (trim($html) === '') {
            return '';
        }

        return trim(self::purifier($profile)->purify($html));
    }

    /**
     * 프로파일별 HTMLPurifier 인스턴스 (요청 단위 메모이제이션)
     */
    private static function purifier(string $profile): HTMLPurifier
    {
        if (!isset(self::$purifiers[$profile])) {
            self::$purifiers[$profile] = new HTMLPurifier(self::buildConfig($profile));
        }

        return self::$purifiers[$profile];
    }

    /**
     * 프로파일별 HTMLPurifier 설정 구성
     */
    private static function buildConfig(string $profile): HTMLPurifier_Config
    {
        // rich·block 은 신뢰 iframe(YouTube/Vimeo)을 허용한다. basic(폼)만 막는다.
        $allowIframe = ($profile !== 'basic');
        $isBlock = ($profile === 'block');

        $config = HTMLPurifier_Config::createDefault();

        // HTMLPurifier 코어가 CSS.DefinitionID 를 참조하지만 스키마에 등록하지 않아,
        // 커스텀 CSS 정의를 얻을 때 "undefined directive" 경고를 낸다. 먼저 등록해 둔다.
        // 스키마는 프로세스 싱글턴이므로 최초 1회만 등록한다.
        if (!isset($config->def->info['CSS.DefinitionID'])) {
            $config->def->add('CSS.DefinitionID', null, 'string', true);
            $config->def->add('CSS.DefinitionRev', 1, 'int', false);
        }

        $config->set('Core.Encoding', 'UTF-8');
        // target 속성 및 폭넓은 HTML을 다루기 위해 Transitional 사용
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');

        // id 속성 허용, target="_blank" 허용 + rel 자동 부여
        $config->set('Attr.EnableID', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        // (HTMLPurifier 기본값이나 명시): _blank 링크에 noopener/noreferrer 부착
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);

        // 인라인 style 화이트리스트. block 은 레이아웃 CSS 까지, 나머지는 서식만.
        // position / expression 등 위험 값은 어느 프로파일에서도 자동 차단된다.
        $config->set('CSS.AllowedProperties', $isBlock
            ? [...self::BASE_CSS_PROPERTIES, ...self::BLOCK_EXTRA_CSS_PROPERTIES]
            : self::BASE_CSS_PROPERTIES);

        // 신뢰 iframe만 허용 (rich·block 프로파일)
        if ($allowIframe) {
            $config->set('HTML.SafeIframe', true);
            $config->set('URI.SafeIframeRegexp', self::SAFE_IFRAME_REGEXP);
        }

        // 정의 캐시 경로 설정 (커스텀 HTML5 요소 정의에 필요)
        $cachePath = self::cachePath();
        if ($cachePath !== null) {
            $config->set('Cache.SerializerPath', $cachePath);
        } else {
            // 쓰기 가능한 캐시 경로가 없으면 직렬화 캐시 비활성 (정의는 매 인스턴스 재생성)
            $config->set('Cache.DefinitionImpl', null);
        }

        // HTML5 요소 추가 (프로파일별 정의 ID 분리)
        $config->set('HTML.DefinitionID', 'mublo-' . $profile);
        // 정의 변경 시 반드시 rev 를 올린다 — 안 올리면 직렬화 캐시가 살아서
        // 새 요소/속성 등록이 조용히 무시된다. (rev 2: 레이아웃 data-* 허용)
        $config->set('HTML.DefinitionRev', 2);

        // maybeGetRawHTMLDefinition() 은 직렬화 캐시가 유효하면 null 을 반환한다.
        // 따라서 모든 addElement/addAttribute 는 이 가드 안에서만 호출해야 한다.
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            foreach (self::EXTRA_HTML5_ELEMENTS as [$name, $type, $contents, $attrCollection]) {
                $def->addElement($name, $type, $contents, $attrCollection);
            }
            // <time datetime="...">
            $def->addAttribute('time', 'datetime', 'Text');

            // 블록 조판용 레이아웃 data-* — block 프로파일 한정.
            // DefinitionID 가 프로파일별로 분리돼 있어 rich/basic 정의를 오염시키지 않는다.
            if ($isBlock) {
                foreach (self::BLOCK_LAYOUT_DATA_ATTRIBUTES as $attr => $type) {
                    $def->addAttribute('div', $attr, $type);
                }
            }
        }

        if ($isBlock) {
            self::buildBlockCssDefinition($config);
        }

        return $config;
    }

    /**
     * block 프로파일의 확장 CSS 속성에 값 검증기를 붙인다.
     *
     * HTMLPurifier 코어 CSS 정의에 없는 속성(display·opacity·flex 등)은 여기서
     * AttrDef 를 직접 등록해야 한다. 등록하지 않으면 AllowedProperties 에 이름이
     * 있어도 조용히 무시된다.
     *
     * 검증기의 핵심은 **값이 스크립트·외부 리소스를 실을 수 없게** 하는 것이다.
     * 길이·숫자·색·그라디언트·키워드만 통과시키므로, url()·position:fixed·잘못된
     * 키워드는 어느 토큰도 검증기를 통과하지 못해 자동 제거된다.
     */
    private static function buildBlockCssDefinition(HTMLPurifier_Config $config): void
    {
        // raw=true, optimized=false 로 얻어야 CSS.DefinitionID 없이 원본 정의를 수정할 수 있다.
        $css = $config->getCSSDefinition(true, false);
        if ($css === null) {
            return;
        }

        $lengthPos = new HTMLPurifier_AttrDef_CSS_Length('0');  // 0 이상 (간격·반경)
        // 길이 또는 퍼센트. border-radius:50%, flex-basis:30% 같은 값을 살린다.
        $lengthOrPct = fn (): HTMLPurifier_AttrDef_CSS_Composite => new HTMLPurifier_AttrDef_CSS_Composite([
            new HTMLPurifier_AttrDef_CSS_Length('0'),
            new HTMLPurifier_AttrDef_CSS_Percentage(),
        ]);

        $definitions = [
            // 키워드 화이트리스트
            'display' => new HTMLPurifier_AttrDef_Enum(self::DISPLAY_KEYWORDS),
            'overflow' => new HTMLPurifier_AttrDef_Enum(['visible', 'hidden', 'scroll', 'auto']),
            'overflow-x' => new HTMLPurifier_AttrDef_Enum(['visible', 'hidden', 'scroll', 'auto']),
            'overflow-y' => new HTMLPurifier_AttrDef_Enum(['visible', 'hidden', 'scroll', 'auto']),
            'word-break' => new HTMLPurifier_AttrDef_Enum(['normal', 'break-all', 'keep-all', 'break-word']),
            'text-transform' => new HTMLPurifier_AttrDef_Enum(['none', 'capitalize', 'uppercase', 'lowercase']),
            'flex-direction' => new HTMLPurifier_AttrDef_Enum(['row', 'row-reverse', 'column', 'column-reverse']),
            'flex-wrap' => new HTMLPurifier_AttrDef_Enum(['nowrap', 'wrap', 'wrap-reverse']),
            'justify-content' => new HTMLPurifier_AttrDef_Enum(['flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly', 'start', 'end', 'left', 'right']),
            'align-items' => new HTMLPurifier_AttrDef_Enum(['stretch', 'flex-start', 'flex-end', 'center', 'baseline', 'start', 'end']),
            'align-content' => new HTMLPurifier_AttrDef_Enum(['stretch', 'flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly']),
            'align-self' => new HTMLPurifier_AttrDef_Enum(['auto', 'stretch', 'flex-start', 'flex-end', 'center', 'baseline']),

            // 숫자·투명도
            'opacity' => new HTMLPurifier_AttrDef_CSS_AlphaValue(),
            'flex-grow' => new HTMLPurifier_AttrDef_CSS_Number(true),
            'flex-shrink' => new HTMLPurifier_AttrDef_CSS_Number(true),

            // 길이·간격 (여러 값 허용)
            'border-radius' => new HTMLPurifier_AttrDef_CSS_Multiple($lengthOrPct(), 4),
            'gap' => new HTMLPurifier_AttrDef_CSS_Multiple($lengthPos, 2),
            'row-gap' => $lengthPos,
            'column-gap' => $lengthPos,
            'flex-basis' => new HTMLPurifier_AttrDef_CSS_Composite([
                new HTMLPurifier_AttrDef_CSS_Length('0'),
                new HTMLPurifier_AttrDef_CSS_Percentage(),
                new HTMLPurifier_AttrDef_Enum(['auto', 'content']),
            ]),

            // 복합: flex 단축 = <number>{0,2} <length|auto|none>?
            'flex' => new HTMLPurifier_AttrDef_CSS_Multiple(
                new HTMLPurifier_AttrDef_CSS_Composite([
                    new HTMLPurifier_AttrDef_CSS_Number(true),
                    new HTMLPurifier_AttrDef_CSS_Length('0'),
                    new HTMLPurifier_AttrDef_Enum(['auto', 'none', 'content']),
                ]),
                3
            ),

            // 그림자 = 콤마로 이어진 다중 레이어. CSS_Multiple 은 공백으로만 쪼개서
            // rgba(...) 를 포함한 다중 그림자를 뒤섞으므로 전용 검증기를 쓴다.
            'box-shadow' => ShadowAttrDef::boxShadow(),
            'text-shadow' => ShadowAttrDef::textShadow(),

            // 배경 = 그라디언트·단색·同출처 상대 경로 url() 만. 스킴/프로토콜 상대
            // url()·image-set() 등 외부 리소스 함수는 거부된다 (GradientAttrDef).
            'background' => new GradientAttrDef(),
            'background-image' => new GradientAttrDef(),
        ];

        // setup() 전후로 두 번 얹는다.
        //  - 전: doSetup()의 setupConfigStuff()가 AllowedProperties 를 훑으면서 코어가
        //        모르는 속성(word-break 등)에 E_USER_WARNING 을 쏜다. 미리 등록해 막는다.
        //  - 후: doSetup()이 코어가 아는 속성(background·display 등)을 자기 검증기로
        //        덮어쓰므로, 그 뒤에 다시 얹어야 우리 정책이 이긴다.
        foreach ($definitions as $property => $attrDef) {
            $css->info[$property] = $attrDef;
        }

        $css->setup($config);

        foreach ($definitions as $property => $attrDef) {
            $css->info[$property] = $attrDef;
        }
    }

    /**
     * 쓰기 가능한 HTMLPurifier 캐시 디렉터리 반환 (없으면 null)
     */
    private static function cachePath(): ?string
    {
        $base = defined('MUBLO_STORAGE_PATH')
            ? MUBLO_STORAGE_PATH
            : sys_get_temp_dir() . '/mublo';

        $dir = $base . '/cache/htmlpurifier';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return (is_dir($dir) && is_writable($dir)) ? $dir : null;
    }

    /**
     * 텍스트만 추출 (모든 태그 제거)
     */
    public static function stripTags(string $html): string
    {
        return strip_tags($html);
    }

    /**
     * XSS 방지용 이스케이프
     *
     * HTML 태그를 문자 그대로 표시해야 할 때 사용.
     * 잘못된 UTF-8 시퀀스는 빈 문자열 대신 U+FFFD 로 치환해 출력이 통째로 사라지지 않게 한다.
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
