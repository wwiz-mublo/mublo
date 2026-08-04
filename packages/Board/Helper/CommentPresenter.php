<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Helper;

use Mublo\Helper\String\StringHelper;

/**
 * CommentPresenter
 *
 * board_comments 원본 행(getRecentForBlock 결과)을 스킨에서 바로 사용할 수 있는
 * 표시용 데이터로 변환. ArticlePresenter와 동일한 규칙(상대시간·마스킹·escape)을 따른다.
 *
 * 스킨에서:
 * ```php
 * <a href="<?= $c['url'] ?>"><?= $c['content_excerpt'] ?></a>
 * <?= $c['author_name'] ?> · <?= $c['date_relative'] ?> · <?= $c['board_name'] ?>
 * ```
 */
class CommentPresenter
{
    /**
     * 목록용 변환
     *
     * @param array[] $rows getRecentForBlock() 결과
     * @param int $excerptLength 내용 요약 길이
     * @return array[] 변환된 댓글 배열 목록
     */
    public function toList(array $rows, int $excerptLength = 60): array
    {
        return array_map(
            fn(array $row) => $this->transform($row, $excerptLength),
            $rows
        );
    }

    private function transform(array $row, int $excerptLength): array
    {
        $esc = fn(?string $v): string =>
            htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

        $isSecret = !empty($row['is_secret']);
        $isMember = !empty($row['member_id']);
        $name     = (string) ($row['author_name'] ?? '익명');

        // 비밀댓글은 내용 노출 금지. 그 외에는 태그 제거 후 요약.
        if ($isSecret) {
            $excerpt = '🔒 비밀 댓글입니다.';
        } else {
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($row['content'] ?? ''))));
            $excerpt = $esc(StringHelper::truncate($plain, $excerptLength));
        }

        // 게시글 URL (ArticlePresenter와 동일 규칙)
        $boardSlug  = (string) ($row['board_slug'] ?? '');
        $articleId  = (int) ($row['article_id'] ?? 0);
        $articleSlug = (string) ($row['article_slug'] ?? '');
        $url = $boardSlug !== '' && $articleId > 0
            ? "/board/{$boardSlug}/view/{$articleId}" . ($articleSlug !== '' ? '/' . urlencode($articleSlug) : '')
            : '#';

        $dates = $this->buildDateFields((string) ($row['created_at'] ?? ''));

        return [
            'comment_id'         => (int) ($row['comment_id'] ?? 0),
            'article_id'         => $articleId,
            'board_id'           => (int) ($row['board_id'] ?? 0),
            'is_secret'          => $isSecret,
            'is_member'          => $isMember,
            'author_name'        => $esc($name),
            'author_name_masked' => $esc(StringHelper::mask($name, 1, 0)),
            'content_excerpt'    => $excerpt,
            'board_name'         => $esc($row['board_name'] ?? ''),
            'board_slug'         => $boardSlug,
            'article_title'      => $esc($row['article_title'] ?? ''),
            'url'                => $url,
        ] + $dates;
    }

    /**
     * 날짜 포맷 생성 (ArticlePresenter와 동일 세트)
     *
     * @return array<string,string>
     */
    private function buildDateFields(string $createdAt): array
    {
        $empty = [
            'date_raw' => '', 'date_full' => '', 'date_short' => '',
            'date_compact' => '', 'date_time' => '', 'date_relative' => '', 'date_ymd' => '',
        ];

        if ($createdAt === '') {
            return $empty;
        }

        try {
            $dt = new \DateTimeImmutable($createdAt);
        } catch (\Exception) {
            return $empty;
        }

        return [
            'date_raw'      => $dt->format('Y-m-d H:i:s'),
            'date_full'     => $dt->format('Y-m-d H:i'),
            'date_short'    => $dt->format('Y-m-d'),
            'date_compact'  => $dt->format('m-d'),
            'date_time'     => $dt->format('H:i'),
            'date_relative' => $this->relativeTime($dt),
            'date_ymd'      => $dt->format('y.m.d'),
        ];
    }

    /**
     * 상대시간 계산 (한국어) — ArticlePresenter와 동일 기준
     */
    private function relativeTime(\DateTimeImmutable $dt): string
    {
        $diff = (new \DateTimeImmutable())->getTimestamp() - $dt->getTimestamp();

        if ($diff < 0)      return $dt->format('m-d');
        if ($diff < 60)     return '방금 전';
        if ($diff < 3600)   return (int) ($diff / 60) . '분 전';
        if ($diff < 86400)  return (int) ($diff / 3600) . '시간 전';
        if ($diff < 172800) return '어제';

        return $dt->format('m-d');
    }
}
