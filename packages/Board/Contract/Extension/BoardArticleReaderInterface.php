<?php

namespace Mublo\Packages\Board\Contract\Extension;

use Mublo\Packages\Board\Api\DTO\ArticleSnapshot;

/**
 * Board 확장이 게시글을 조회할 때 사용하는 안정 API.
 */
interface BoardArticleReaderInterface
{
    /**
     * 현재 도메인 소유 글 또는 전역 게시판 글만 반환한다.
     * 조회 가능성만 판정하며 변경 권한을 부여하지 않는다.
     */
    public function findAccessibleById(int $articleId, int $domainId): ?ArticleSnapshot;
}
