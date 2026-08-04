<?php
/**
 * Policy View - 약관/정책 상세 보기
 *
 * 약관 열람 기본 스킨
 *
 * @var \Mublo\Entity\Member\Policy $policy 정책 엔티티
 * @var string $renderedContent 치환 변수 적용된 정책 내용
 */
$this->assets->addCss('/serve/front/view/policy/basic/css/policy.css');
?>

<div class="policy-view-wrapper">
    <h1 class="policy-title"><?= htmlspecialchars($policy->getPolicyTitle()) ?></h1>

    <div class="policy-meta">
        <span>버전 <?= htmlspecialchars($policy->getPolicyVersion()) ?></span>
        <?php if ($policy->getUpdatedAt()): ?>
            <span>|</span>
            <span>최종 수정일 <?= date('Y.m.d', strtotime($policy->getUpdatedAt())) ?></span>
        <?php elseif ($policy->getCreatedAt()): ?>
            <span>|</span>
            <span>등록일 <?= date('Y.m.d', strtotime($policy->getCreatedAt())) ?></span>
        <?php endif; ?>
    </div>

    <div class="policy-body">
        <?= $renderedContent ?>
    </div>

    <div class="policy-footer">
        <a href="javascript:history.back()" class="btn-back">돌아가기</a>
    </div>
</div>
