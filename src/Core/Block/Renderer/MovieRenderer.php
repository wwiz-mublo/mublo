<?php
namespace Mublo\Core\Block\Renderer;

use Mublo\Entity\Block\BlockColumn;

/**
 * MovieRenderer
 *
 * 동영상 콘텐츠 렌더러
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumn 엔티티
 * - $videoType: youtube|vimeo|video
 * - $videoHtml: 렌더링된 비디오 HTML
 * - $aspectRatio: 비율 (16:9, 4:3 등)
 */
class MovieRenderer implements RendererInterface
{
    use SkinRendererTrait;

    /**
     * 스킨 타입 반환
     */
    protected function getSkinType(): string
    {
        return 'movie';
    }

    /**
     * {@inheritdoc}
     */
    public function render(BlockColumn $column): string
    {
        $config = $column->getContentConfig() ?? [];
        $skin = $column->getContentSkin() ?: 'basic';

        // admin form은 키를 'video_type'으로, 값으로 'url'(직접 URL)을 저장한다.
        // 렌더러는 'type' 키와 'video' 값을 사용하므로 정규화한다.
        if (empty($config['type']) && !empty($config['video_type'])) {
            $config['type'] = $config['video_type'];
        }
        if (($config['type'] ?? '') === 'url') {
            $config['type'] = 'video';
        }

        // video_url이 있으면 URL 형태로 type/video_id 자동 보정
        // YouTube/Vimeo URL은 저장된 type 값과 관계없이 항상 임베드로 처리한다
        // (admin에서 type을 'video'로 둔 채 YouTube URL을 입력한 경우에도 정상 재생)
        if (!empty($config['video_url'])) {
            $parsed = $this->parseVideoUrl($config['video_url']);
            if ($parsed['type'] !== 'video') {
                $config['type'] = $parsed['type'];
                $config['video_id'] = $parsed['video_id'];
            } elseif (empty($config['video_id'])) {
                $config['type'] = $parsed['type'];
                $config['video_id'] = $parsed['video_id'];
            }
        }

        $type = $config['type'] ?? 'video';
        $aspectRatio = $config['aspect_ratio'] ?? '16:9';

        $videoHtml = match ($type) {
            'youtube' => $this->buildYoutubeHtml($config),
            'vimeo' => $this->buildVimeoHtml($config),
            default => $this->buildVideoHtml($config),
        };

        if (empty($videoHtml)) {
            return $this->renderEmptyContent('동영상이 설정되지 않았습니다.');
        }

        return $this->renderSkin($column, $skin, [
            'videoType' => $type,
            'videoHtml' => $videoHtml,
            'aspectRatio' => $aspectRatio,
        ]);
    }

    /**
     * YouTube HTML 빌드
     */
    private function buildYoutubeHtml(array $config): string
    {
        $videoId = $config['video_id'] ?? null;
        if (!$videoId) {
            return '';
        }

        $videoId = htmlspecialchars($videoId);

        // 사용자가 video_url 에 직접 적어둔 쿼리 파라미터를 보존한다
        // (admin form 에 토글이 없는 controls, rel, modestbranding, showinfo 등도 URL 로 지정 가능)
        $params = $this->extractUrlQuery($config['video_url'] ?? '');
        unset($params['v']);  // watch?v=ID 로 이미 video_id 추출됨
        unset($params['si']); // 공유 트래킹 파라미터 — 임베드에선 무의미

        // 폼 토글 옵션은 URL 에 동일 키가 없을 때만 채운다 (URL 우선)
        $autoplay = filter_var($config['autoplay'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($autoplay) {
            $params['autoplay'] = $params['autoplay'] ?? '1';
            $params['mute'] = $params['mute'] ?? '1'; // 브라우저 정책: 자동재생은 음소거 필수
        } elseif (filter_var($config['muted'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $params['mute'] = $params['mute'] ?? '1';
        }
        // 동영상 블록은 기본적으로 컨트롤을 숨긴다.
        // URL 또는 폼에서 controls=1 로 명시한 경우에만 표시.
        if (!isset($params['controls'])) {
            $controlsEnabled = filter_var($config['controls'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!$controlsEnabled) {
                $params['controls'] = '0';
            }
        }

        // 동영상 블록은 기본적으로 반복재생을 활성화한다.
        // (영상 종료 시 노출되는 추천/엔드 스크린을 막기 위한 기본 정책)
        // URL 또는 폼에서 loop=0 으로 명시한 경우에만 끈다.
        // YouTube 는 loop=1 단독으로 동작하지 않고 playlist={video_id} 가 함께 있어야 한다.
        $loopExplicit = $params['loop'] ?? $config['loop'] ?? null;
        $loopEnabled = $loopExplicit === null
            ? true
            : filter_var($loopExplicit, FILTER_VALIDATE_BOOLEAN);
        if ($loopEnabled) {
            $params['loop'] = '1';
            $params['playlist'] = $params['playlist'] ?? $videoId;
        } else {
            unset($params['loop'], $params['playlist']);
        }

        $queryString = !empty($params) ? '?' . http_build_query($params) : '';

        return <<<HTML
<iframe
    src="https://www.youtube.com/embed/{$videoId}{$queryString}"
    title="YouTube 동영상"
    class="block-movie__iframe"
    frameborder="0"
    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
    allowfullscreen
></iframe>
HTML;
    }

    /**
     * Vimeo HTML 빌드
     */
    private function buildVimeoHtml(array $config): string
    {
        $videoId = $config['video_id'] ?? null;
        if (!$videoId) {
            return '';
        }

        $videoId = htmlspecialchars($videoId);

        // 사용자가 video_url 에 직접 적어둔 쿼리 파라미터를 보존한다
        $params = $this->extractUrlQuery($config['video_url'] ?? '');

        $autoplay = filter_var($config['autoplay'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($autoplay) {
            $params['autoplay'] = $params['autoplay'] ?? '1';
            $params['muted'] = $params['muted'] ?? '1';
        } elseif (filter_var($config['muted'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $params['muted'] = $params['muted'] ?? '1';
        }
        // 컨트롤 기본 OFF. URL/폼에서 controls=1 로 명시할 때만 표시.
        if (!isset($params['controls'])) {
            $controlsEnabled = filter_var($config['controls'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!$controlsEnabled) {
                $params['controls'] = '0';
            }
        }

        // 동영상 블록은 기본적으로 반복재생을 활성화한다.
        // URL 또는 폼에서 loop=0 으로 명시한 경우에만 끈다.
        $loopExplicit = $params['loop'] ?? $config['loop'] ?? null;
        $loopEnabled = $loopExplicit === null
            ? true
            : filter_var($loopExplicit, FILTER_VALIDATE_BOOLEAN);
        if ($loopEnabled) {
            $params['loop'] = '1';
        } else {
            unset($params['loop']);
        }

        $queryString = !empty($params) ? '?' . http_build_query($params) : '';

        return <<<HTML
<iframe
    src="https://player.vimeo.com/video/{$videoId}{$queryString}"
    title="Vimeo 동영상"
    class="block-movie__iframe"
    frameborder="0"
    allow="autoplay; fullscreen; picture-in-picture"
    allowfullscreen
></iframe>
HTML;
    }

    /**
     * URL 의 쿼리 문자열을 연관 배열로 추출
     */
    private function extractUrlQuery(string $url): array
    {
        if ($url === '') {
            return [];
        }
        $parsed = parse_url($url);
        if (empty($parsed['query'])) {
            return [];
        }
        parse_str($parsed['query'], $query);
        return is_array($query) ? $query : [];
    }

    /**
     * 영상 URL에서 type과 video_id 자동 추출
     */
    private function parseVideoUrl(string $url): array
    {
        // YouTube: 다양한 URL 형식 지원
        if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
            return ['type' => 'youtube', 'video_id' => $m[1]];
        }

        // Vimeo
        if (preg_match('/vimeo\.com\/(?:video\/)?(\d+)/', $url, $m)) {
            return ['type' => 'vimeo', 'video_id' => $m[1]];
        }

        // 기타: HTML5 video로 처리
        return ['type' => 'video', 'video_id' => ''];
    }

    /**
     * HTML5 Video HTML 빌드
     */
    private function buildVideoHtml(array $config): string
    {
        $videoUrl = $config['video_url'] ?? null;
        if (!$videoUrl) {
            return '';
        }

        $videoUrl = htmlspecialchars($videoUrl);
        $attrs = [];

        if ($config['autoplay'] ?? false) {
            $attrs[] = 'autoplay';
        }
        if ($config['muted'] ?? false) {
            $attrs[] = 'muted';
        }
        // 동영상 블록은 기본적으로 반복재생 활성화 (loop=false 로 명시한 경우만 끔)
        if ($config['loop'] ?? true) {
            $attrs[] = 'loop';
        }
        if ($config['controls'] ?? true) {
            $attrs[] = 'controls';
        }

        $attrsStr = implode(' ', $attrs);

        return <<<HTML
<video src="{$videoUrl}" class="block-movie__video" {$attrsStr} playsinline></video>
HTML;
    }
}
