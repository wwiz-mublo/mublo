<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Helper;

use Mublo\Helper\Security\HtmlSanitizer;

/**
 * BoardContentSanitizer
 *
 * 게시판 본문 전용 HTML 정화기.
 *
 * 본문은 회원/게스트가 제출하는 신뢰 불가 입력이므로, 정규식 기반 정화기(우회에 취약)가 아닌
 * DOM 기반 Core 정화기(HtmlSanitizer::sanitizeEditorContent)로 정화한다. 이 정화기는
 * script/style/object 등 액티브 콘텐츠 태그를 제거하고 iframe도 신뢰 호스트만 허용한다.
 * 게시판은 동영상 임베드가 필요하므로, 이 헬퍼가 신뢰 도메인(YouTube/Vimeo)의 iframe만
 * 표준 임베드로 재조립해 허용하도록 정책을 확장한다. Core 정화기는 수정하지 않고 재사용한다.
 *
 * 처리 순서:
 *   1) 본문에서 iframe을 추출 → 신뢰 도메인이면 표준 임베드로 재조립해 토큰(평문)으로 치환,
 *      그 외 도메인 iframe은 제거
 *   2) 토큰이 박힌 본문을 Core HtmlSanitizer::sanitizeEditorContent(DOM 기반)로 정화
 *   3) 토큰을 표준 임베드 마크업으로 복원
 *
 * 임베드는 입력 attribute를 그대로 통과시키지 않고 src에서 동영상 ID만 추출해
 * 고정 템플릿으로 재조립하므로 attribute 주입 위험이 없다. 인라인 스타일(16:9)을
 * 포함해 프론트(에디터 CSS 미로드)에서도 정상 표시된다.
 */
final class BoardContentSanitizer
{
    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $tokens = [];
        $nonce = bin2hex(random_bytes(6));

        // 1. iframe 추출 → 신뢰 도메인만 표준 임베드로 재조립 후 토큰화, 나머지는 제거
        $replaced = preg_replace_callback(
            '#<iframe\b[^>]*?\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*?>(?:\s*</iframe>)?#is',
            static function (array $m) use (&$tokens, $nonce): string {
                $src = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
                $embed = self::buildEmbed($src);
                if ($embed === null) {
                    return ''; // 비허용 도메인 iframe 제거
                }
                $token = '@@BOARD_EMBED_' . $nonce . '_' . count($tokens) . '@@';
                $tokens[$token] = $embed;
                return $token;
            },
            $html
        );

        $html = $replaced ?? $html;

        // 2. Core DOM 기반 정화기로 나머지 정화 (script/style/on* 제거, iframe 호스트 제한)
        $html = HtmlSanitizer::sanitizeEditorContent($html);

        // 3. 토큰 복원
        if ($tokens) {
            $html = strtr($html, $tokens);
        }

        return $html;
    }

    /**
     * iframe src를 검사해 신뢰 동영상이면 표준 임베드 마크업을 반환, 아니면 null.
     */
    private static function buildEmbed(string $src): ?string
    {
        $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $embedUrl = null;

        if (preg_match('#(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})#i', $src, $mm)) {
            $embedUrl = 'https://www.youtube.com/embed/' . $mm[1];
        } elseif (preg_match('#youtube-nocookie\.com/embed/([A-Za-z0-9_-]{11})#i', $src, $mm)) {
            $embedUrl = 'https://www.youtube-nocookie.com/embed/' . $mm[1];
        } elseif (preg_match('#(?:player\.)?vimeo\.com/(?:video/)?(\d+)#i', $src, $mm)) {
            $embedUrl = 'https://player.vimeo.com/video/' . $mm[1];
        }

        if ($embedUrl === null) {
            return null;
        }

        $safeUrl = htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8');

        // 래퍼 없이도 16:9 반응형이 되도록 aspect-ratio 인라인 스타일 사용
        return '<iframe src="' . $safeUrl . '" frameborder="0" allowfullscreen'
            . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"'
            . ' style="display:block;width:100%;max-width:100%;aspect-ratio:16/9;border:0;margin:1rem 0;"></iframe>';
    }
}
