<?php
$csrfToken = $mublo['security']['csrfToken'];
/**
 * @var array[] $notifications
 * @var array $pagination
 * @var int $unreadCount
 */
?>

<?php ob_start(); ?>
<div class="mypage-header notification-page-header">
    <div>
        <h1 class="mypage-header__title">알림</h1>
        <p class="mypage-header__desc">댓글, 쪽지, 주문 등 사이트 안에서 받은 알림을 확인합니다.</p>
    </div>
    <?php if ($unreadCount > 0): ?>
    <form method="post" action="/mypage/notifications/read-all">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
        <button type="submit" class="btn btn--outline btn--sm">모두 읽음</button>
    </form>
    <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
<div class="notification-empty">
    <i class="bi bi-bell" aria-hidden="true"></i>
    <p>받은 알림이 없습니다.</p>
</div>
<?php else: ?>
<div class="notification-list">
    <?php foreach ($notifications as $notification): ?>
    <?php $isUnread = empty($notification['read_at']); ?>
    <article class="notification-item<?= $isUnread ? ' is-unread' : '' ?>">
        <span class="notification-item__dot" aria-label="<?= $isUnread ? '읽지 않음' : '읽음' ?>"></span>
        <div class="notification-item__content">
            <strong class="notification-item__title"><?= htmlspecialchars((string) $notification['title']) ?></strong>
            <?php if ((string) ($notification['body'] ?? '') !== ''): ?>
            <p class="notification-item__body"><?= nl2br(htmlspecialchars((string) $notification['body'])) ?></p>
            <?php endif; ?>
            <time class="notification-item__time" datetime="<?= htmlspecialchars((string) $notification['created_at']) ?>">
                <?= htmlspecialchars(substr((string) $notification['created_at'], 0, 16)) ?>
            </time>
        </div>
        <form method="post" action="/mypage/notifications/open">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
            <input type="hidden" name="notification_id" value="<?= (int) $notification['notification_id'] ?>">
            <button type="submit" class="btn btn--ghost btn--sm"><?= !empty($notification['target_url']) ? '보기' : '읽음' ?></button>
        </form>
    </article>
    <?php endforeach; ?>
</div>
<?= $this->pagination($pagination) ?>
<?php endif; ?>

<?php $content = ob_get_clean(); ?>
<?php include __DIR__ . '/_layout.php'; ?>
