<?php
declare(strict_types=1);

namespace Mublo\Helper\Security\Css;

use HTMLPurifier_AttrDef;
use HTMLPurifier_AttrDef_CSS_Color;
use HTMLPurifier_AttrDef_CSS_Length;

/**
 * ShadowAttrDef
 *
 * box-shadow / text-shadow 검증기.
 *
 * 콤마로 이어진 다중 그림자를 레이어 단위로 검증한다. HTMLPurifier 의
 * CSS_Multiple 은 공백으로만 쪼개서 `0 1px 0 rgba(0,0,0,.5), 0 2px 4px #000`
 * 같은 값을 뒤섞어 깨진 CSS 를 만들어 내므로 쓰지 않는다.
 *
 * 각 레이어: [inset] <length>{2,4} [<color>]  (text-shadow 는 inset 불가, 길이 2~3개)
 * 색은 HTMLPurifier 색 검증기를 그대로 쓰므로 url()·함수값은 통과할 수 없다.
 */
final class ShadowAttrDef extends HTMLPurifier_AttrDef
{
    private HTMLPurifier_AttrDef_CSS_Length $length;
    private HTMLPurifier_AttrDef_CSS_Color $color;

    public function __construct(private bool $allowInset = true, private int $maxLengths = 4)
    {
        // 음수 오프셋 허용
        $this->length = new HTMLPurifier_AttrDef_CSS_Length();
        $this->color = new HTMLPurifier_AttrDef_CSS_Color();
    }

    public static function boxShadow(): self
    {
        return new self(true, 4);
    }

    public static function textShadow(): self
    {
        return new self(false, 3);
    }

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

        if (strtolower($string) === 'none') {
            return 'none';
        }

        $layers = CssValueTokenizer::splitLayers($string);
        if ($layers === null) {
            return false;
        }

        $validated = [];
        foreach ($layers as $layer) {
            $result = $this->validateLayer($layer, $config, $context);
            if ($result === false) {
                return false;
            }
            $validated[] = $result;
        }

        return implode(', ', $validated);
    }

    /**
     * @return string|false
     */
    private function validateLayer(string $layer, $config, $context)
    {
        $tokens = CssValueTokenizer::splitTokens($layer);
        if ($tokens === null) {
            return false;
        }

        $inset = false;
        $lengths = [];
        $color = null;

        foreach ($tokens as $token) {
            if (strtolower($token) === 'inset') {
                // inset 중복·text-shadow 에서의 inset 은 거부
                if (!$this->allowInset || $inset) {
                    return false;
                }
                $inset = true;
                continue;
            }

            $asLength = $this->length->validate($token, $config, $context);
            if ($asLength !== false) {
                if (count($lengths) >= $this->maxLengths) {
                    return false;
                }
                $lengths[] = $asLength;
                continue;
            }

            // 색은 레이어당 하나. 길이보다 먼저 와도(CSS 허용) 받아준다.
            $asColor = $this->color->validate($token, $config, $context);
            if ($asColor !== false && $color === null) {
                $color = $asColor;
                continue;
            }

            return false;
        }

        // 오프셋 x·y 는 필수
        if (count($lengths) < 2) {
            return false;
        }

        $parts = [];
        if ($inset) {
            $parts[] = 'inset';
        }
        array_push($parts, ...$lengths);
        if ($color !== null) {
            $parts[] = $color;
        }

        return implode(' ', $parts);
    }
}
