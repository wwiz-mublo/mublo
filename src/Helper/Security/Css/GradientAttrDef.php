<?php

namespace Mublo\Helper\Security\Css;

use HTMLPurifier_AttrDef;
use HTMLPurifier_AttrDef_CSS_Color;

/**
 * GradientAttrDef
 *
 * background / background-image 를 **그라디언트·단색·同출처 url() 레이어로만**
 * 허용하는 검증기.
 *
 * HTMLPurifier 코어의 background 검증기는 CSS2 문법 기준이라 gradient 를 모르고
 * (그래서 값 전체가 버려진다) 대신 임의의 url() 을 통과시킨다. 여기서는
 * 레이어 단위로 화이트리스트 검증한다:
 *
 * - 그라디언트 함수(linear/radial/conic, repeating 포함)
 * - 단색 (외부 요청 없음)
 * - **同출처 상대 경로 url()** — `url(/storage/...)`, `url(/serve/...)` 처럼
 *   `/` 하나로 시작하는 무따옴표 경로만. 문자셋에서 `:` 를 배제하므로
 *   스킴(https:, javascript:, data:)은 구조적으로 불가능하고, `//`(프로토콜
 *   상대)와 `..` 도 거부한다. 외부 요청(추적/IP 유출) 벡터가 열리지 않는다.
 *   (블록 타입 독트린: 표현은 HTML 블록 — 사진 히어로가 HTML 로 성립하려면
 *   同출처 배경 이미지가 필요하다. #55)
 *
 * 값이 style 속성 안으로 들어가므로 따옴표·세미콜론 등 브레이크아웃 문자도 거부한다.
 */
final class GradientAttrDef extends HTMLPurifier_AttrDef
{
    /** 외부 리소스·스크립트를 실을 수 있는 함수 — 그라디언트 레이어 내부 밀반입 차단용 */
    private const FORBIDDEN_FUNCTIONS = '/(?:url|expression|image-set|-webkit-image-set|element|attr|var|calc|counter|-moz-binding)\s*\(/i';

    private const GRADIENT_LAYER = '/^(?:repeating-)?(?:linear|radial|conic)-gradient\(.+\)$/i';

    /**
     * 同출처 url() 레이어 — `/` 하나로 시작(// 아님), 무따옴표,
     * 경로 문자만([a-z0-9/_.\-]), `..` 없음.
     */
    private const URL_LAYER = '#^url\(\s*/(?!/)(?!.*\.\.)[a-z0-9/_.\-]*\s*\)$#i';

    /**
     * @param string $string
     * @param \HTMLPurifier_Config $config
     * @param \HTMLPurifier_Context $context
     * @return string|false
     */
    public function validate($string, $config, $context)
    {
        $string = trim($this->parseCDATA($string));

        if ($string === '') {
            return false;
        }

        // style 속성 브레이크아웃 / CSS 이스케이프 차단
        if (preg_match('/["\'\\\\;<>{}@]/', $string)) {
            return false;
        }

        // 그라디언트·경로 문법에 필요한 문자만 허용 — `:` 배제가 스킴 차단의 핵심이다
        if (preg_match('/^[a-z0-9#%,.()\/_\s+\-]+$/i', $string) !== 1) {
            return false;
        }

        // background: #fff 같은 단색 단축도 받아준다(외부 요청 없음).
        $asColor = (new HTMLPurifier_AttrDef_CSS_Color())->validate($string, $config, $context);
        if ($asColor !== false) {
            return $asColor;
        }

        $layers = CssValueTokenizer::splitLayers($string);
        if ($layers === null) {
            return false;
        }

        foreach ($layers as $layer) {
            // 同출처 url 레이어
            if (preg_match(self::URL_LAYER, $layer) === 1) {
                continue;
            }

            // 그라디언트 레이어 — 내부에 url() 등 함수 밀반입 금지
            if (preg_match(self::GRADIENT_LAYER, $layer) !== 1
                || preg_match(self::FORBIDDEN_FUNCTIONS, $layer) === 1) {
                return false;
            }
        }

        return implode(', ', $layers);
    }
}
