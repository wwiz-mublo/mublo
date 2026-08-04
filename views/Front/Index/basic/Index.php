<?php
/**
 * Front Index Page (basic skin)
 *
 * 메인 페이지 - 이 스킨을 실제로 사용할 때만 index 블록을 지연 렌더링한다.
 * standalone/chromeless 스킨은 이 파일을 실행하지 않으므로 블록 시스템도 실행되지 않는다.
 *
 * @var string $pageTitle 페이지 제목
 * @var array $mublo Front View 데이터 계약
 */

$domainId = (int) $mublo['site']['domainId'];
$blockHtml = \Mublo\Helper\BlockHelper::index($domainId);
?>
<div class="page-index">
    <?php if (!empty($blockHtml)): ?>
        <?= $blockHtml ?>
    <?php else: ?>
        <div class="page-index__empty">
            <p>관리자에서 메인 페이지 블록을 설정해주세요.</p>
        </div>
    <?php endif; ?>
</div>
