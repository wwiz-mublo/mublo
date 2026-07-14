<?php
/**
 * 이메일 알림 - 발송 이력
 *
 * @var string $pageTitle
 * @var array $logs
 * @var array $pagination
 */
$logs = $logs ?? [];
$pagination = $pagination ?? [];

$columns = $this->columns()
    ->add('log_id', '번호', ['_th_attr' => ['style' => 'width:70px']])
    ->add('template_code', '템플릿', [
        '_th_attr' => ['style' => 'width:160px'],
        'render' => function ($row) {
            $code = htmlspecialchars((string) ($row['template_code'] ?? ''));
            return $code ? "<code>{$code}</code>" : '<span class="text-muted">-</span>';
        },
    ])
    ->add('recipient', '수신자', ['_th_attr' => ['style' => 'width:220px']])
    ->add('subject', '제목')
    ->add('result_code', '결과', [
        '_th_attr' => ['style' => 'width:90px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center'],
        'render' => function ($row) {
            $code = (string) ($row['result_code'] ?? '');
            if ($code === 'OK') {
                return '<span class="badge text-success-emphasis bg-success-subtle border border-success-subtle">성공</span>';
            }
            return $code !== ''
                ? '<span class="badge text-danger-emphasis bg-danger-subtle border border-danger-subtle">' . htmlspecialchars($code) . '</span>'
                : '<span class="text-muted">-</span>';
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
            <p>이메일 발송 이력을 조회합니다.</p>
        </div>
    </div>

    <div class="page-block">
        <div class="row align-items-center gy-2 gy-xl-0 mb-2">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/email-notify/history">전체</a></span>
                    <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 건</span>
                </span>
            </div>
        </div>

        <div class="table-responsive">
            <?= $this->listRenderHelper
                ->setColumns($columns)
                ->setRows($logs)
                ->setSkin('table/basic')
                ->setWrapAttr(['class' => 'table table-hover align-middle'])
                ->showHeader(true)
                ->render() ?>
        </div>

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
