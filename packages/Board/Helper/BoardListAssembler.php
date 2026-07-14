<?php

namespace Mublo\Packages\Board\Helper;

/**
 * BoardListAssembler
 *
 * 목록용 게시글 데이터에 관계 데이터(첨부/링크/카테고리)를 흡수시키는 조립기.
 *
 * 엔티티(BoardArticle)는 관계 필드를 담지 않으므로, Repository가 배치로 적재한
 * 관계 맵을 각 $item 배열에 합쳐 "완전한 raw 데이터"를 만든다. ArticlePresenter는
 * 이 raw $item을 받아 표시용으로 변형(transform)만 한다.
 *
 * 목록을 그리는 모든 진입점(목록 컨트롤러·공지·게시판 블록·게시판그룹 블록)이
 * 이 한 곳을 거치게 해, 관계 주입이 특정 경로에서 누락되는 일을 막는다.
 */
final class BoardListAssembler
{
    /**
     * 엔티티/배열 목록 + 관계 맵 → 관계가 흡수된 $item 배열 목록
     *
     * @param array $items     BoardArticle[] 또는 toArray() 결과 배열[]
     * @param array $relations ['attachments' => map, 'links' => map, 'categories' => map]
     *                         (Repository::loadRelations() 결과)
     * @return array<int, array<string, mixed>>
     */
    public static function assemble(array $items, array $relations): array
    {
        $attachments = $relations['attachments'] ?? [];
        $links = $relations['links'] ?? [];
        $categories = $relations['categories'] ?? [];

        return array_map(function ($row) use ($attachments, $links, $categories) {
            $item = is_array($row) ? $row : $row->toArray();
            $articleId = (int) ($item['article_id'] ?? 0);
            $categoryId = (int) ($item['category_id'] ?? 0);

            $item['attachments'] = $attachments[$articleId] ?? [];
            $item['links'] = $links[$articleId] ?? [];
            $item['category_name'] = $categories[$categoryId]['name'] ?? null;
            $item['category_slug'] = $categories[$categoryId]['slug'] ?? null;

            return $item;
        }, $items);
    }
}
