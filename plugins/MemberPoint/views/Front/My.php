<?php
/**
 * MemberPoint Plugin - 사용자 내 포인트 페이지
 *
 * @var string $pageTitle
 * @var object|null $member
 * @var array $points
 * @var int $totalPoint
 * @var array $pagination
 */
?>

<div class="point-page" style="max-width:1140px;margin:0 auto">
    <h2 class="mb-4">
        <i class="bi bi-coin me-2"></i><?= htmlspecialchars($pageTitle) ?>
    </h2>

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h6 class="card-subtitle mb-2 opacity-75">보유 포인트</h6>
                    <h2 class="card-title mb-0"><?= number_format($totalPoint) ?> P</h2>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">포인트 내역</h5>
        </div>
        <div class="card-body">
            <?php if (empty($points)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    포인트 내역이 없습니다.
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>일시</th>
                            <th>내용</th>
                            <th class="text-end">포인트</th>
                            <th class="text-end">잔액</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($points as $point): ?>
                            <tr>
                                <td><?= $point['created_at'] ?></td>
                                <td><?= htmlspecialchars($point['content']) ?></td>
                                <td class="text-end <?= $point['point'] > 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= $point['point'] > 0 ? '+' : '' ?><?= number_format($point['point']) ?>
                                </td>
                                <td class="text-end"><?= number_format($point['balance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (isset($pagination) && $pagination['total_pages'] > 1): ?>
                <nav>
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                        <li class="page-item <?= $i === $pagination['current_page'] ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
