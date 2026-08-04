<?php
/**
 * 로그인/회원 영역 컴포넌트 (알림 링크 + 읽지 않음 배지)
 *
 * 파일 스킨(frame/{skin}/Header.php)과 프레임 템플릿 슬롯({{login_area}})이 공유한다.
 * 로그인 상태 분기를 이 컴포넌트가 소유한다 — 비로그인이면 출력 없음.
 *
 * @var array $viewer $mublo.viewer 계약
 */
$viewer = $viewer ?? [];
$isLogin = !empty($viewer['authenticated']);
$unreadNotifications = max(0, (int) ($viewer['notificationUnreadCount'] ?? 0));
?>
            <?php if ($isLogin): ?>
            <a href="/mypage/notifications" class="mublo-header__notifications" aria-label="알림<?= $unreadNotifications > 0 ? ' ' . $unreadNotifications . '개 읽지 않음' : '' ?>">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <?php if ($unreadNotifications > 0): ?>
                <span class="mublo-header__notification-badge"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
