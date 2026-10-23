<?php
/**
 * 쿠폰 발급 내역 (정책별)
 *
 * @var string $pageTitle
 * @var array  $coupon     쿠폰 정책 (CouponPolicy::toArray)
 * @var array  $items      발급 쿠폰 행
 * @var array  $members    member_id => [user_id, name]
 * @var string $status     ''|available|used|expired
 * @var array  $summary    total, used, expired, available
 * @var array  $pagination
 */
$couponId = (int) ($coupon['coupon_group_id'] ?? 0);
$now = date('Y-m-d H:i:s');
$tabs = [
    '' => ['전체', $summary['total']],
    'available' => ['사용 가능', $summary['available']],
    'used' => ['사용', $summary['used']],
    'expired' => ['만료', $summary['expired']],
];
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p><?= htmlspecialchars($coupon['name'] ?? '') ?></p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/coupons" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list"></i> 목록</a>
            <a href="/admin/shop/coupons/<?= $couponId ?>/bulk-issue" class="btn btn-sm btn-primary"><i class="bi bi-send"></i> 일괄 지급</a>
        </div>
    </div>

    <div class="page-block">
        <ul class="nav nav-tabs mb-3">
            <?php foreach ($tabs as $key => [$label, $count]): ?>
            <li class="nav-item">
                <a class="nav-link<?= $status === $key ? ' active' : '' ?>"
                   href="/admin/shop/coupons/<?= $couponId ?>/issued<?= $key !== '' ? '?status=' . $key : '' ?>">
                    <?= $label ?> <span class="text-muted small"><?= number_format((int) $count) ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>회원</th>
                        <th style="width:190px">쿠폰 번호</th>
                        <th style="width:150px">발급일</th>
                        <th style="width:150px">만료일</th>
                        <th style="width:90px" class="text-center">상태</th>
                        <th style="width:200px">사용</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">발급 내역이 없습니다.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($items as $row):
                        $mid = (int) $row['member_id'];
                        $m = $members[$mid] ?? null;
                        $used = !empty($row['is_used']);
                        $expired = !$used && (($row['status'] ?? '') === 'EXPIRED' || ($row['valid_until'] ?? '') < $now);
                    ?>
                    <tr>
                        <td>
                            <?php if ($m): ?>
                            <a href="/admin/member/edit/<?= $mid ?>" class="text-body"><?= htmlspecialchars($m['name']) ?></a>
                            <br><small class="text-muted"><?= htmlspecialchars($m['user_id']) ?></small>
                            <?php else: ?>
                            <span class="text-muted">회원 #<?= $mid ?> (없음)</span>
                            <?php endif; ?>
                        </td>
                        <td><code class="small"><?= htmlspecialchars((string) ($row['coupon_number'] ?? '')) ?></code></td>
                        <td class="small"><?= htmlspecialchars((string) $row['issued_at']) ?></td>
                        <td class="small"><?= htmlspecialchars((string) $row['valid_until']) ?></td>
                        <td class="text-center">
                            <?php if ($used): ?>
                            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">사용</span>
                            <?php elseif ($expired): ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">만료</span>
                            <?php else: ?>
                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">사용 가능</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($used): ?>
                            <?= htmlspecialchars((string) ($row['used_at'] ?? '')) ?>
                            <?php if (!empty($row['order_no'])): ?>
                            <br><a href="/admin/shop/orders/<?= htmlspecialchars((string) $row['order_no']) ?>"><?= htmlspecialchars((string) $row['order_no']) ?></a>
                            · <?= number_format((int) ($row['used_amount'] ?? 0)) ?>원
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-end my-2">
            <?= $this->pagination($pagination) ?>
        </div>
    </div>
</div>
