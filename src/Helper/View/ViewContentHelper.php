<?php

namespace Mublo\Helper\View;

/**
 * ViewContentHelper
 *
 * View에서 사용하는 콘텐츠 파싱 헬퍼.
 * ViewContext의 setHelper('content', ...) 로 주입되어
 * 스킨에서 $this->content->method() 형태로 호출.
 *
 * HTML 파싱 등 비용이 높을 수 있으므로 on-demand 호출 권장.
 *
 * 사용 예:
 * ```php
 * <?php $thumb = $this->content->thumbnail($article['content']); ?>
 * <?php if ($thumb): ?>
 *     <img src="<?= $thumb ?>" alt="thumbnail">
 * <?php endif; ?>
 *
 * <p><?= $this->content->summary($article['content'], 150) ?></p>
 * <span>이미지 <?= $this->content->imageCount($article['content']) ?>장</span>
 * ```
 */
class ViewContentHelper
{
    /**
     * 본문에서 첫 번째 이미지 src 추출
     *
     * @param string $content HTML 본문
     * @return string|null 이미지 URL 또는 null
     */
    public function thumbnail(string $content): ?string
    {
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * HTML 태그 제거 후 텍스트 요약
     *
     * @param string $content HTML 본문
     * @param int $length 최대 글자 수
     * @return string 요약 텍스트
     */
    public function summary(string $content, int $length = 100): string
    {
        $text = strip_tags($content);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text));

        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length, 'UTF-8') . '...';
    }

    /**
     * 본문 내 이미지 개수
     *
     * @param string $content HTML 본문
     * @return int 이미지 개수
     */
    public function imageCount(string $content): int
    {
        return preg_match_all('/<img\b/i', $content);
    }
}
