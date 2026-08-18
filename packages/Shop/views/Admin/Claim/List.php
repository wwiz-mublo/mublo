<?php
/**
 * 반품·교환 관리 목록
 *
 * @var array $claims 클레임 목록
 * @var array $pagination 페이지네이션 정보
 * @var array $filters 검색 조건 (return_type, status, keyword)
 * @var array $statusOptions 상태 옵션 [value => label]
 */
$claims = $claims ?? [];
$filters = $filters ?? [];
$pagination = $pagination ?? [];
$statusOptions = $statusOptions ?? [];
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '반품·교환 관리') ?></h3>
            <p>교환과 반품은 회수·검수까지 같은 길을 걷고 마지막에만 갈립니다 — 교환은 재출고로, 반품은 환불로 끝납니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/order-states?activeCode=K_Shop_006" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-bell"></i> 상태·알림 설정
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <?php // GET 제출은 기존 쿼리를 버리므로 사이드바 하이라이트가 풀린다.
                  // 페이지네이션은 현재 쿼리스트링을 유지하므로 여기만 챙기면 된다. ?>
            <input type="hidden" name="activeCode" value="K_Shop_016">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/shop/claims?activeCode=K_Shop_016">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 건</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <select name="return_type" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">유형: 전체</option>
                                <option value="EXCHANGE" <?= ($filters['return_type'] ?? '') === 'EXCHANGE' ? 'selected' : '' ?>>교환</option>
                                <option value="RETURN" <?= ($filters['return_type'] ?? '') === 'RETURN' ? 'selected' : '' ?>>반품</option>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">상태: 전체</option>
                                <?php foreach ($statusOptions as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="주문번호/상품명"
                                       value="<?= htmlspecialchars($filters['keyword'] ?? '') ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($filters['keyword'])): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/shop/claims?activeCode=K_Shop_016'"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-default">
                                <i class="bi bi-search"></i> 검색
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- 클레임 목록 -->
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th class="text-nowrap">번호</th>
                        <th class="text-nowrap">유형</th>
                        <th class="text-nowrap">주문번호</th>
                        <th>상품</th>
                        <th>교환 옵션</th>
                        <th class="text-center text-nowrap">수량</th>
                        <th class="text-nowrap">사유</th>
                        <th class="text-nowrap">상태</th>
                        <th class="text-center text-nowrap">귀책</th>
                        <th class="text-nowrap">신청일</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($claims === []): ?>
                    <tr><td colspan="10" class="text-center text-muted py-5">반품·교환 내역이 없습니다.</td></tr>
                <?php endif; ?>
                <?php foreach ($claims as $claim): ?>
                    <?php
                    $status = (string) ($claim['return_status'] ?? '');
                    $claimType = (string) ($claim['return_type'] ?? 'EXCHANGE');
                    $isReturn = $claimType === 'RETURN';
                    $badge = in_array($status, ['COMPLETED', 'CLOSED'], true) ? 'success'
                        : (in_array($status, ['REFUSED', 'REJECTED', 'CANCELLED'], true) ? 'danger' : 'warning');
                    $resp = match ($claim['responsibility'] ?? '') { 'CUSTOMER' => '고객', 'SELLER' => '판매자', default => '관리자' };
                    ?>
                    <tr>
                        <td class="text-nowrap"><a href="/admin/shop/claims/<?= (int) $claim['return_id'] ?>?activeCode=K_Shop_016">#<?= (int) $claim['return_id'] ?></a></td>
                        <td class="text-nowrap">
                            <span class="badge bg-<?= $isReturn ? 'secondary' : 'info' ?>-subtle text-<?= $isReturn ? 'secondary' : 'info' ?>-emphasis border border-<?= $isReturn ? 'secondary' : 'info' ?>-subtle"><?= $isReturn ? '반품' : '교환' ?></span>
                        </td>
                        <td class="text-nowrap"><a href="/admin/shop/orders/<?= urlencode((string) $claim['order_no']) ?>?activeCode=K_Shop_005"><?= htmlspecialchars($claim['order_no'] ?? '') ?></a></td>
                        <td>
                            <div><?= htmlspecialchars($claim['source_goods_name'] ?? '') ?></div>
                            <small class="text-muted"><?= htmlspecialchars($claim['source_option_name'] ?? '-') ?></small>
                        </td>
                        <td><?= $isReturn ? '<span class="text-muted">-</span>' : htmlspecialchars($claim['target_option_name'] ?? '동일 상품') ?></td>
                        <td class="text-center"><?= (int) ($claim['exchange_quantity'] ?? $claim['quantity'] ?? 0) ?></td>
                        <td class="small text-nowrap"><?= htmlspecialchars(\Mublo\Packages\Shop\Enum\ClaimReason::labelFor($claim['reason_type'] ?? '')) ?></td>
                        <td class="text-nowrap">
                            <span class="badge bg-<?= $badge ?>-subtle text-<?= $badge ?>-emphasis border border-<?= $badge ?>-subtle"><?= htmlspecialchars(\Mublo\Packages\Shop\Enum\ClaimStatus::tryFrom($status)?->label($claimType) ?? $status) ?></span>
                        </td>
                        <td class="text-center text-nowrap"><?= $resp ?></td>
                        <td class="text-nowrap"><?= htmlspecialchars($claim['requested_at'] ?? $claim['created_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- 페이지네이션 -->
        <div class="row gx-2 justify-content-between align-items-center my-2">
            <div class="col-auto"></div>
            <div class="col-auto">
                <?= $this->pagination($pagination) ?>
            </div>
        </div>
    </div>
</div>
