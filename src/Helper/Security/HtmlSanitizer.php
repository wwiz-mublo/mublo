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
 * - rich  : 에디터/게시판 본문 — YouTube/Vimeo 임베드 + 에디터 출력 CSS 허용
 * - basic : 폼 HTML 필드 — iframe 불가, 서식 CSS 만
 * - block : 블록 HTML — 관리자가 조판하는 채널이므로 표현 CSS까지 허용
 *
 * ## 어느 선까지 넓히는가
 *
 * 경계는 "누가 편집하느냐" 가 아니라 **"이 값이 스크립트·외부요청을 실을 수
 * 있느냐"** 다. 스크립트 채널(script 태그, on* 핸들러, javascript: URL)은 세
 * 프로파일 모두 똑같이 막는다.
 *
 * rich 가 EDITOR_CSS_PROPERTIES 까지 받는 이유는 MubloEditor 자신이 그 CSS 를
 * 출력하기 때문이다. 인용구 갤러리·체크리스트·목차·이미지 레이아웃·링크 카드는
 * 뷰 페이지에 별도 CSS 가 없어도 렌더되도록 완결된 인라인 스타일로 저장된다.
 * 여기서 border/background/flex 를 떨어뜨리면 회원이 에디터에서 만든 것과
 * 저장 후 보이는 것이 달라진다 — 정화기가 조용히 기능을 깨뜨리는 셈이다.
 * 값은 길이·숫자·색·그라디언트·키워드로 제한되므로 스크립트는 실리지 않는다.
 *
 * rich 에서 끝까지 막는 두 가지가 프로파일 차이의 핵심이다.
 *  - position/z-index: 회원 콘텐츠가 페이지 UI 위에 겹칠 수 있다(클릭재킹).
 *    block 도 같은 이유로 막는다.
 *  - background 의 url(): 리소스 참조는 관리자 조판(block)에만 연다.
 *    rich 는 GradientAttrDef 를 url 금지 모드로 받아 색·그라디언트만 통과한다.
 *
 * 사용:
 * HtmlSanitizer::sanitize('<div onclick="alert(1)">Hello</div>');
 * // 결과: '<div>Hello</div>'
 */
class HtmlSanitizer
{
    /**
     * 모든 프로파일이 공유하는 인라인 style 화이트리스트 (서식 수준).
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
     * rich·block 이 추가로 허용하는 CSS 속성 (에디터가 출력하는 레이아웃·장식).
     *
     * 이 중 다수는 HTMLPurifier 코어 CSS 정의에 없어, buildExtendedCssDefinition()
     * 에서 값 검증기를 직접 붙인다. 검증기 없이 이름만 넣으면 조용히 무시된다.
     *
     * 값이 숫자·키워드·길이·색·그라디언트뿐이라 스크립트를 실을 수 없는 것만 골랐다.
     * position / z-index (클릭재킹)는 어느 프로파일에도 넣지 않는다. background 는
     * GradientAttrDef 로 그라디언트·단색만 통과시키고, 同출처 상대 경로 url() 은
     * block 에서만 연다 — 외부요청 url()(스킴·프로토콜 상대)은 거기서도 막힌다.
     */
    private const EDITOR_CSS_PROPERTIES = [
        'line-height', 'word-break', 'vertical-align', 'list-style', 'list-style-type',
        'min-width', 'min-height', 'max-height',
        'background', 'background-image',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-radius', 'box-shadow', 'object-fit',
        'overflow', 'overflow-x', 'overflow-y',
        'display', 'gap', 'row-gap', 'column-gap',
        'flex', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis',
        'justify-content', 'align-items', 'align-content', 'align-self',
    ];

    /**
     * block 프로파일만 추가로 허용하는 CSS 속성 (관리자 조판용 표현).
     */
    private const BLOCK_EXTRA_CSS_PROPERTIES = [
        'letter-spacing', 'white-space', 'text-transform', 'opacity', 'text-shadow',
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
     * list-style / list-style-type 으로 허용하는 마커 키워드.
     * 코어 단축 검증기가 품고 있는 list-style-image(url) 경로를 닫기 위한 목록이다.
     */
    private const LIST_STYLE_KEYWORDS = [
        'none', 'disc', 'circle', 'square',
        'decimal', 'decimal-leading-zero',
        'lower-roman', 'upper-roman', 'lower-alpha', 'upper-alpha',
        'lower-latin', 'upper-latin', 'lower-greek',
        'inside', 'outside',
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
     * rich·block 이 허용하는 MubloEditor 콘텐츠 마커 (요소 → 속성 → 값 검증기).
     *
     * 에디터가 자기 출력물을 다시 찾을 때 쓰는 표식이다. 체크리스트는 CSS 훅과
     * 토글 핸들러가, 목차는 "이미 있으면 교체" 판정이 이 속성에 걸려 있다.
     * 값은 표시되지 않고 셀렉터로만 쓰이므로 스크립트 채널이 아니다.
     */
    private const EDITOR_MARKER_ATTRIBUTES = [
        'ul'     => ['data-mublo-checklist' => 'Text'],
        'nav'    => ['data-mublo-toc' => 'Text'],
        'figure' => [
            'data-mublo-card'   => 'Enum#og,video',
            'data-mublo-layout' => 'Text',
        ],
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
        // rich·block 은 신뢰 iframe(YouTube/Vimeo)과 에디터 출력 CSS 를 허용한다.
        // basic(폼)만 둘 다 막는다.
        $isEditorChannel = ($profile !== 'basic');
        $allowIframe = $isEditorChannel;
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

        // 인라인 style 화이트리스트. rich·block 은 에디터 출력 CSS 까지,
        // block 은 조판용 표현까지, basic(폼)은 서식만.
        // position / expression 등 위험 값은 어느 프로파일에서도 자동 차단된다.
        $cssProperties = self::BASE_CSS_PROPERTIES;
        if ($isEditorChannel) {
            $cssProperties = [...$cssProperties, ...self::EDITOR_CSS_PROPERTIES];
        }
        if ($isBlock) {
            $cssProperties = [...$cssProperties, ...self::BLOCK_EXTRA_CSS_PROPERTIES];
        }
        $config->set('CSS.AllowedProperties', $cssProperties);

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
        // 새 요소/속성 등록이 조용히 무시된다.
        // (rev 2: 레이아웃 data-*, rev 3: 에디터 마커·체크박스·img loading)
        $config->set('HTML.DefinitionRev', 3);

        // maybeGetRawHTMLDefinition() 은 직렬화 캐시가 유효하면 null 을 반환한다.
        // 따라서 모든 addElement/addAttribute 는 이 가드 안에서만 호출해야 한다.
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            foreach (self::EXTRA_HTML5_ELEMENTS as [$name, $type, $contents, $attrCollection]) {
                $def->addElement($name, $type, $contents, $attrCollection);
            }
            // <time datetime="...">
            $def->addAttribute('time', 'datetime', 'Text');

            // 에디터 출력 마커 + 체크리스트 체크박스 — rich·block 한정.
            if ($isEditorChannel) {
                foreach (self::EDITOR_MARKER_ATTRIBUTES as $element => $attributes) {
                    foreach ($attributes as $attr => $type) {
                        $def->addAttribute($element, $attr, $type);
                    }
                }

                // 체크리스트 항목. type 을 필수(*)로 두었으므로 checkbox 가 아닌
                // input 은 속성이 탈락하면서 요소째 사라진다 — 본문에 텍스트
                // 입력칸이 생길 여지를 남기지 않는다. name/value 는 열지 않는다.
                $def->addElement('input', 'Inline', 'Empty', 'Common', [
                    'type*'    => 'Enum#checkbox',
                    'checked'  => 'Bool#checked',
                    'disabled' => 'Bool#disabled',
                ]);

                // 카드·레이아웃 이미지의 지연 로딩 힌트
                $def->addAttribute('img', 'loading', 'Enum#lazy,eager');
            }

            // 블록 조판용 레이아웃 data-* — block 프로파일 한정.
            // DefinitionID 가 프로파일별로 분리돼 있어 rich/basic 정의를 오염시키지 않는다.
            if ($isBlock) {
                foreach (self::BLOCK_LAYOUT_DATA_ATTRIBUTES as $attr => $type) {
                    $def->addAttribute('div', $attr, $type);
                }
            }
        }

        if ($isEditorChannel) {
            self::buildExtendedCssDefinition($config, $isBlock);
        }

        return $config;
    }

    /**
     * rich·block 의 확장 CSS 속성에 값 검증기를 붙인다.
     *
     * HTMLPurifier 코어 CSS 정의에 없는 속성(display·opacity·flex 등)은 여기서
     * AttrDef 를 직접 등록해야 한다. 등록하지 않으면 AllowedProperties 에 이름이
     * 있어도 조용히 무시된다.
     *
     * 검증기의 핵심은 **값이 스크립트·외부 리소스를 실을 수 없게** 하는 것이다.
     * 길이·숫자·색·그라디언트·키워드만 통과시키므로, url()·position:fixed·잘못된
     * 키워드는 어느 토큰도 검증기를 통과하지 못해 자동 제거된다.
     *
     * @param bool $allowUrlBackground background 에 同출처 url() 레이어를 허용할지
     *                                 (block 전용 — 회원 채널은 색·그라디언트만)
     */
    private static function buildExtendedCssDefinition(
        HTMLPurifier_Config $config,
        bool $allowUrlBackground
    ): void {
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
            'object-fit' => new HTMLPurifier_AttrDef_Enum(['fill', 'contain', 'cover', 'none', 'scale-down']),
            // 코어의 list-style 단축은 list-style-image(임의 url) 를 품는다.
            // 마커 키워드만 받는 검증기로 덮어 그 경로를 닫는다.
            'list-style' => new HTMLPurifier_AttrDef_Enum(self::LIST_STYLE_KEYWORDS),
            'list-style-type' => new HTMLPurifier_AttrDef_Enum(self::LIST_STYLE_KEYWORDS),
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

            // 복합: flex 단축 = <number>{0,2} <length|percentage|auto|none>?
            // 퍼센트가 빠지면 `flex:0 0 40%` 의 40% 만 조용히 사라져 칸 비율이
            // 무너진다 — 이미지+텍스트 레이아웃이 실제로 그 형태를 쓴다.
            'flex' => new HTMLPurifier_AttrDef_CSS_Multiple(
                new HTMLPurifier_AttrDef_CSS_Composite([
                    new HTMLPurifier_AttrDef_CSS_Number(true),
                    new HTMLPurifier_AttrDef_CSS_Length('0'),
                    new HTMLPurifier_AttrDef_CSS_Percentage(),
                    new HTMLPurifier_AttrDef_Enum(['auto', 'none', 'content']),
                ]),
                3
            ),

            // 그림자 = 콤마로 이어진 다중 레이어. CSS_Multiple 은 공백으로만 쪼개서
            // rgba(...) 를 포함한 다중 그림자를 뒤섞으므로 전용 검증기를 쓴다.
            'box-shadow' => ShadowAttrDef::boxShadow(),
            'text-shadow' => ShadowAttrDef::textShadow(),

            // 배경 = 그라디언트·단색 (+ block 에서만 同출처 상대 경로 url()).
            // 스킴/프로토콜 상대 url()·image-set() 등 외부 리소스 함수는
            // 어느 프로파일에서도 거부된다 (GradientAttrDef).
            'background' => new GradientAttrDef($allowUrlBackground),
            'background-image' => new GradientAttrDef($allowUrlBackground),
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
