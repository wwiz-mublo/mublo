<?php
/**
 * 센드온 알림톡 - 발송 이력
 *
 * @var string $pageTitle
 * @var array $logs
 * @var array $pagination
 */
$logs = $logs ?? [];
$pagination = $pagination ?? [];
// 서비스가 totalPages를 제공하지 않으므로 보강
$pagination['totalPages'] = $pagination['totalPages']
    ?? max(1, (int) ceil(($pagination['totalItems'] ?? 0) / ($pagination['perPage'] ?? 20)));

// 컬럼 정의
$columns = $this->columns()
    ->add('log_id', '번호', ['_th_attr' => ['style' => 'width:50px']])
    ->add('message_type', '타입', [
        '_th_attr' => ['style' => 'width:100px'],
        'render' => function ($row) {
            $type = (string) ($row['message_type'] ?? '');
            $cls = $type === 'ALIMTALK' ? 'text-warning-emphasis bg-warning-subtle border border-warning-subtle' : 'text-info-emphasis bg-info-subtle border border-info-subtle';
            return '<span class="badge ' . $cls . '">' . htmlspecialchars($type) . '</span>';
        },
    ])
    ->add('template_code', '템플릿', [
        '_th_attr' => ['style' => 'width:120px'],
        'render' => function ($row) {
            $code = htmlspecialchars((string) ($row['template_code'] ?? ''));
            return $code ? '<code class="small">' . $code . '</code>' : '<span class="text-muted">-</span>';
        },
    ])
    ->add('recipient', '수신자', ['_th_attr' => ['style' => 'width:120px']])
    ->add('is_fallback', 'LMS대체', [
        '_th_attr' => ['style' => 'width:80px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center'],
        'render' => function ($row) {
            return !empty($row['is_fallback'])
                ? '<span class="badge text-info-emphasis bg-info-subtle border border-info-subtle">대체</span>'
                : '<span class="text-muted">-</span>';
        },
    ])
    ->add('result_code', '결과', [
        '_th_attr' => ['style' => 'width:80px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center'],
        'render' => function ($row) {
            $code = (string) ($row['result_code'] ?? '');
            if ($code === '200') {
                return '<span class="badge text-success-emphasis bg-success-subtle border border-success-subtle">성공</span>';
            }
            return '<span class="badge text-danger-emphasis bg-danger-subtle border border-danger-subtle">' . htmlspecialchars($code !== '' ? $code : 'ERR') . '</span>';
        },
    ])
    ->add('result_message', '메시지', [
        '_cell_attr' => ['class' => 'text-muted small', 'style' => 'max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'],
    ])
    ->add('created_at', '일시', [
        '_th_attr' => ['style' => 'width:140px'],
        '_cell_attr' => ['class' => 'text-muted small'],
    ])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>알림톡 발송 이력을 조회합니다.</p>
        </div>
    </div>

    <div class="page-block">
        <!-- 현황 -->
        <div class="row align-items-center gy-2 gy-xl-0 mb-2">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/sendon-talk/history">전체</a></span>
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
