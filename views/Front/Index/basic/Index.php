<?php
declare(strict_types=1);

/**
 * 메인 화면은 관리자 블록 구성만 렌더링한다.
 * 문구·디자인·순서·노출 여부는 블록 관리에서 변경한다.
 *
 * @var array $mublo Front View 데이터 계약
 */
$domainId = (int) $mublo['site']['domainId'];
$blockHtml = \Mublo\Helper\BlockHelper::index($domainId);
?>
<div class="page-index">
    <?php if ($blockHtml !== ''): ?>
        <?= $blockHtml ?>
    <?php else: ?>
        <div class="page-index__empty">
            <p>관리자에서 메인 페이지 블록을 설정해 주세요.</p>
        </div>
    <?php endif; ?>
</div>
