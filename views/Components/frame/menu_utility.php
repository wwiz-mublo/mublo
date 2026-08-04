<?php
/**
 * 유틸리티 메뉴 컴포넌트 (헤더 우측 보조 메뉴)
 *
 * 파일 스킨(frame/{skin}/Header.php)과 프레임 템플릿 슬롯({{menu_utility}})이 공유한다.
 * visibility(guest/member/all) 필터링을 이 컴포넌트가 소유한다.
 * panel 변형은 모바일 패널용으로 알림 링크를 함께 포함한다.
 *
 * @var array      $utilityMenus            유틸리티 메뉴 목록 [{label, url, target, visibility}]
 * @var array      $viewer                  $mublo.viewer 계약
 * @var string     $variant                 'header'(PC, 기본) | 'panel'(모바일 패널)
 */
$variant = $variant ?? 'header';
$viewer = $viewer ?? [];
$isLogin = !empty($viewer['authenticated']);
$unreadNotifications = max(0, (int) ($viewer['notificationUnreadCount'] ?? 0));

$filteredUtility = array_filter($utilityMenus ?? [], function ($item) use ($isLogin) {
    $vis = $item['visibility'] ?? 'all';
    if ($vis === 'guest') return !$isLogin;
    if ($vis === 'member') return $isLogin;
    return true;
});
?>
<?php if ($variant === 'panel'): ?>
            <?php if ($isLogin || !empty($filteredUtility)): ?>
            <div class="mublo-panel__utility">
                <?php if ($isLogin): ?>
                <a href="/mypage/notifications" class="mublo-panel__utility-link">
                    <span>알림<?= $unreadNotifications > 0 ? ' (' . $unreadNotifications . ')' : '' ?></span>
                </a>
                <?php endif; ?>
                <?php foreach ($filteredUtility as $item): ?>
                <a href="<?= htmlspecialchars($item['url'] ?? '#') ?>"
                   class="mublo-panel__utility-link"
                   <?= !empty($item['target']) && $item['target'] === '_blank' ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <span><?= htmlspecialchars($item['label'] ?? '') ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
<?php else: ?>
            <?php if (!empty($filteredUtility)): ?>
            <ul class="mublo-header__utility">
                <?php foreach ($filteredUtility as $item): ?>
                <li>
                    <a href="<?= htmlspecialchars($item['url'] ?? '#') ?>"
                       <?= !empty($item['target']) && $item['target'] === '_blank' ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <span><?= htmlspecialchars($item['label'] ?? '') ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
<?php endif; ?>
