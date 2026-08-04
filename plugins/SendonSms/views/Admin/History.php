<?php
/**
 * 센드온 SMS - 발송 이력
 *
 * @var string $pageTitle
 * @var array $logs
 * @var array $pagination
 */
$logs = $logs ?? [];
$pagination = $pagination ?? [];

// 컬럼 정의
$columns = $this->columns()
    ->add('log_id', '번호', ['_th_attr' => ['style' => 'width:70px']])
    ->add('message_type', '메시지 타입', [
        '_th_attr' => ['style' => 'width:100px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center'],
        'render' => function ($row) {
            $type = strtoupper((string) ($row['message_type'] ?? 'SMS'));
            if ($type === 'LMS') {
                return '<span class="badge text-primary-emphasis bg-primary-subtle border border-primary-subtle">LMS</span>';
            }
            if ($type === 'MMS') {
                return '<span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle">MMS</span>';
            }
            return '<span class="badge text-info-emphasis bg-info-subtle border border-info-subtle">SMS</span>';
        },
    ])
    ->add('template_code', '템플릿', [
        '_th_attr' => ['style' => 'width:140px'],
        'render' => function ($row) {
            $code = htmlspecialchars((string) ($row['template_code'] ?? ''));
            return $code ? "<code>{$code}</code>" : '<span class="text-muted">-</span>';
        },
    ])
    ->add('recipient', '수신자', [
        '_th_attr' => ['style' => 'width:140px'],
        'render' => function ($row) {
            $phone = (string) ($row['recipient'] ?? '');
            if (strlen($phone) >= 8) {
                $phone = substr($phone, 0, 3) . '-****-' . substr($phone, -4);
            }
            return htmlspecialchars($phone);
        },
    ])
    ->add('is_reservation', '예약', [
        '_th_attr' => ['style' => 'width:70px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center'],
        'render' => function ($row) {
            $isReservation = !empty($row['is_reservation']);
            return $isReservation
                ? '<span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle">예약</span>'
                : '<span class="text-muted">-</span>';
        },
    ])
    ->add('group_id', '그룹 ID', [
        '_th_attr' => ['style' => 'width:120px'],
        'render' => function ($row) {
            $groupId = htmlspecialchars((string) ($row['group_id'] ?? ''));
            return $groupId ? "<code>{$groupId}</code>" : '<span class="text-muted">-</span>';
        },
    ])
    ->add('result_code', '결과코드', [
        '_th_attr' => ['style' => 'width:100px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center'],
        'render' => function ($row) {
            $code = htmlspecialchars((string) ($row['result_code'] ?? ''));
            if ($code === '0' || $code === '1') {
                return '<span class="badge text-success-emphasis bg-success-subtle border border-success-subtle">' . $code . '</span>';
            }
            return $code ? '<span class="badge text-danger-emphasis bg-danger-subtle border border-danger-subtle">' . $code . '</span>' : '<span class="text-muted">-</span>';
        },
    ])
    ->add('result_message', '메시지')
    ->add('created_at', '일시', ['_th_attr' => ['style' => 'width:160px']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>센드온 SMS 발송 이력을 조회합니다.</p>
        </div>
    </div>

    <div class="page-block">
        <!-- 현황 -->
        <div class="row align-items-center gy-2 gy-xl-0 mb-2">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/sendon-sms/history">전체</a></span>
                    <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 건</span>
                </span>
            </div>
        </div>

        <!-- 이력 목록 테이블 -->
        <div class="table-responsive">
            <?= $this->listRenderHelper
                ->setColumns($columns)
                ->setRows($logs)
                ->setSkin('table/basic')
                ->setWrapAttr(['class' => 'table table-hover align-middle'])
                ->showHeader(true)
                ->render() ?>
        </div>

        <!-- 하단 페이지네이션 -->
        <?php if (($pagination['totalPages'] ?? 1) > 1): ?>
        <div class="row gx-2 justify-content-between align-items-center my-2">
            <div class="col-auto"></div>
            <div class="col-auto">
                <?= $this->pagination($pagination) ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
