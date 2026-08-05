<?php
/**
 * SNS 연동 내역
 *
 * @var array  $accounts    연동 계정 목록 (sa.* + nickname, user_id)
 * @var string $provider    현재 필터 provider
 * @var bool   $failedOnly  폐기 실패 연결만 보기
 * @var int    $failedCount 폐기 실패로 남은 연결 수 (탭 배지)
 * @var array  $pagination  totalItems, perPage, currentPage, totalPages
 */
$providerMeta = [
    'naver'  => ['label' => '네이버',  'icon' => 'bi-chat-fill',       'color' => '#03C75A'],
    'kakao'  => ['label' => '카카오',  'icon' => 'bi-chat-square-fill', 'color' => '#FEE500'],
    'google' => ['label' => 'Google', 'icon' => 'bi-google',           'color' => '#4285F4'],
];

function buildUrl(string $base, array $params): string {
    $q = http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
    return $q ? $base . '?' . $q : $base;
}

$baseUrl     = '/admin/sns-login/accounts';
$currentPage = $pagination['currentPage'];
$totalPages  = $pagination['totalPages'];
?>
<div class="page-container">

    <div class="page-title">
        <div class="page-title-text">
            <h3>SNS 연동 내역</h3>
            <p>회원의 SNS 연동 현황을 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/sns-login/settings" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-gear"></i> 설정
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 제공자 필터 탭 -->
        <ul class="nav nav-tabs mb-2">
            <li class="nav-item">
                <a class="nav-link <?= ($provider === '' && !$failedOnly) ? 'active' : '' ?>"
                   href="<?= buildUrl($baseUrl, ['provider' => '']) ?>">
                    전체
                    <?php if ($provider === '' && !$failedOnly): ?>
                    <span class="badge bg-secondary ms-1"><?= number_format($pagination['totalItems']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php foreach ($providerMeta as $key => $meta): ?>
            <li class="nav-item">
                <a class="nav-link <?= ($provider === $key && !$failedOnly) ? 'active' : '' ?>"
                   href="<?= buildUrl($baseUrl, ['provider' => $key]) ?>">
                    <i class="<?= $meta['icon'] ?>" style="color:<?= $meta['color'] ?>;"></i>
                    <?= $meta['label'] ?>
                    <?php if ($provider === $key && !$failedOnly): ?>
                    <span class="badge bg-secondary ms-1"><?= number_format($pagination['totalItems']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
            <?php if ($failedCount > 0 || $failedOnly): ?>
            <li class="nav-item ms-auto">
                <a class="nav-link <?= $failedOnly ? 'active' : '' ?> text-danger"
                   href="<?= buildUrl($baseUrl, ['failed' => '1']) ?>">
                    <i class="bi bi-exclamation-triangle"></i>
                    폐기 실패
                    <span class="badge bg-danger ms-1"><?= number_format($failedCount) ?></span>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <?php if ($failedOnly): ?>
        <div class="alert alert-warning py-2 small">
            제공자 측 연결 해제에 실패해 남은 연결입니다.
            회원은 이미 탈퇴·해제되었지만 제공자에는 연결이 살아 있을 수 있습니다.
            설정(Client ID·시크릿)을 확인한 뒤 <strong>재시도</strong>하면 폐기와 정리가 함께 진행됩니다.
        </div>
        <?php endif; ?>

        <!-- 목록 테이블 -->
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:100px;">제공자</th>
                        <th>닉네임</th>
                        <th>아이디</th>
                        <th>제공자 이메일</th>
                        <th style="width:160px;">연동일시</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($accounts)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-person-x fs-2 d-block mb-2"></i>
                            연동 내역이 없습니다.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($accounts as $row):
                        $meta = $providerMeta[$row['provider']] ?? ['label' => $row['provider'], 'icon' => 'bi-person-badge', 'color' => '#6c757d'];
                        $revokeFailed = !empty($row['revoke_failed_at']);
                    ?>
                    <tr>
                        <td>
                            <span class="d-flex align-items-center gap-1">
                                <i class="<?= $meta['icon'] ?>" style="color:<?= $meta['color'] ?>;"></i>
                                <small><?= $meta['label'] ?></small>
                            </span>
                        </td>
                        <td>
                            <?php if ($row['nickname']): ?>
                            <a href="/admin/member/edit/<?= (int)$row['member_id'] ?>">
                                <?= htmlspecialchars($row['nickname']) ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">(탈퇴회원)</span>
                            <?php endif; ?>
                            <?php if ($revokeFailed): ?>
                            <span class="badge bg-danger-subtle text-danger-emphasis ms-1"
                                  title="<?= htmlspecialchars(substr($row['revoke_failed_at'], 0, 16) . ' · ' . ($row['revoke_failure_reason'] ?? '')) ?>">
                                폐기 실패
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars($row['user_id'] ?? '') ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($row['provider_email'] ?? '-') ?></td>
                        <td class="text-muted small"><?= htmlspecialchars(substr($row['linked_at'] ?? '', 0, 16)) ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    onclick="unlinkAccount(<?= (int)$row['id'] ?>, <?= $revokeFailed ? 'true' : 'false' ?>)">
                                <?= $revokeFailed ? '재시도' : '해제' ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 페이지네이션 -->
        <?php if ($totalPages > 1): ?>
        <div class="row gx-2 justify-content-between align-items-center my-2">
            <div class="col-auto"></div>
            <div class="col-auto">
                <?= $this->pagination($pagination) ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
function unlinkAccount(id, revokeFailed) {
    var message = revokeFailed
        ? '제공자 연결 폐기를 다시 시도하시겠습니까?\n성공하면 이 연동 기록도 함께 삭제됩니다.'
        : '이 SNS 연동을 해제하시겠습니까?\n해당 회원은 SNS 로그인을 사용할 수 없게 됩니다.';
    if (!confirm(message)) return;

    MubloRequest.requestJson('/admin/sns-login/accounts/' + id, {}, { method: 'DELETE' })
        .then(function() { location.reload(); });
}
</script>
