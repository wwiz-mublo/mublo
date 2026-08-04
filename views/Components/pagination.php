<?php
/**
 * Pagination Component - Basic Skin
 *
 * @var int $currentPage 현재 페이지
 * @var int $totalPages 전체 페이지 수
 * @var int $pageNums 표시할 페이지 번호 개수
 * @var string $queryString 현재 쿼리스트링
 */

$currentPage = (int) ($currentPage ?? 1);
$totalPages  = (int) ($totalPages  ?? 1);
$pageNums    = (int) ($pageNums    ?? 10);
$queryString = (string) ($queryString ?? '');

$startPage = max(1, $currentPage - floor($pageNums / 2));
$endPage = min($totalPages, $startPage + $pageNums - 1);

// 페이지 범위 보정
if ($endPage - $startPage + 1 < $pageNums) {
    $startPage = max(1, $endPage - $pageNums + 1);
}

// page= 제거
$queryString = preg_replace('/([&?])?page=[^&]*(&|$)/', '$1', $queryString);
// 마지막 & 또는 ? 제거
$queryString = preg_replace('/[&?]$/', '', $queryString);
?>

<nav class="nav-page">
    <ul class="pagination pagination-sm">

        <?php if ($currentPage > 1): ?>
            <li class="page-item page-start">
                <a class="page-link"
                   href="?page=1<?= $queryString ? '&'.$queryString : '' ?>"
                   aria-label="처음 페이지">
                    <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
                </a>
            </li>
        <?php else: ?>
            <li class="page-item page-start disabled">
                <a class="page-link" aria-label="처음 페이지">
                    <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
                </a>
            </li>
        <?php endif; ?>

        <?php if ($currentPage > 1): ?>
            <li class="page-item page-prev">
                <a class="page-link"
                   href="?page=<?= $currentPage - 1 ?><?= $queryString ? '&'.$queryString : '' ?>"
                   aria-label="이전 페이지">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    <span class="page-label">이전</span>
                </a>
            </li>
        <?php else: ?>
            <li class="page-item page-prev disabled">
                <a class="page-link" aria-label="이전 페이지">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    <span class="page-label">이전</span>
                </a>
            </li>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <li class="page-item page-count <?= ($i == $currentPage) ? 'active' : '' ?>">
                <a class="page-link"
                   href="?page=<?= $i ?><?= $queryString ? '&'.$queryString : '' ?>">
                    <?= $i ?>
                </a>
            </li>
        <?php endfor; ?>

        <?php if ($currentPage < $totalPages): ?>
            <li class="page-item page-next">
                <a class="page-link"
                   href="?page=<?= $currentPage + 1 ?><?= $queryString ? '&'.$queryString : '' ?>"
                   aria-label="다음 페이지">
                    <span class="page-label">다음</span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </li>
        <?php else: ?>
            <li class="page-item page-next disabled">
                <a class="page-link" aria-label="다음 페이지">
                    <span class="page-label">다음</span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </li>
        <?php endif; ?>

        <?php if ($currentPage < $totalPages): ?>
            <li class="page-item page-end">
                <a class="page-link"
                   href="?page=<?= $totalPages ?><?= $queryString ? '&'.$queryString : '' ?>"
                   aria-label="마지막 페이지">
                    <i class="bi bi-chevron-double-right" aria-hidden="true"></i>
                </a>
            </li>
        <?php else: ?>
            <li class="page-item page-end disabled">
                <a class="page-link" aria-label="마지막 페이지">
                    <i class="bi bi-chevron-double-right" aria-hidden="true"></i>
                </a>
            </li>
        <?php endif; ?>

    </ul>
</nav>
