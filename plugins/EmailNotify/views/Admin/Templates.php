<?php
/**
 * 이메일 알림 - 템플릿 목록
 *
 * 추가/수정은 전용 폼 페이지(TemplateForm)로 이동한다.
 *
 * @var string $pageTitle
 * @var array  $templates
 * @var array  $pagination
 */
$templates = $templates ?? [];
$pagination = $pagination ?? [];

$columns = $this->columns()
    ->add('template_id', '번호', ['_th_attr' => ['style' => 'width:70px']])
    ->add('template_code', '코드', [
        '_th_attr' => ['style' => 'width:180px'],
        'render' => function ($row) {
            $code = htmlspecialchars((string) ($row['template_code'] ?? ''));
            return $code ? "<code>{$code}</code>" : '<span class="text-muted">-</span>';
        },
    ])
    ->add('template_name', '이름')
    ->add('subject', '제목')
    ->add('is_active', '사용', [
        '_th_attr' => ['style' => 'width:64px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center'],
        'render' => function ($row) {
            return !empty($row['is_active'])
                ? '<span class="badge text-success-emphasis bg-success-subtle border border-success-subtle">사용</span>'
                : '<span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">중지</span>';
        },
    ])
    ->actions('actions', '관리', function ($row) {
        $id = (int) ($row['template_id'] ?? 0);
        return '<a href="/admin/email-notify/templates/edit/' . $id . '" class="btn btn-sm btn-default">수정</a>'
             . ' <button type="button" class="btn btn-sm btn-default" onclick="deleteTemplate(' . $id . ')">삭제</button>';
    })
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>이메일 템플릿을 등록하고 <code>#{변수}</code> 토큰으로 동적 내용을 구성합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/email-notify/templates/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 템플릿 추가
            </a>
        </div>
    </div>

    <div class="page-block">
        <div class="row align-items-center gy-2 gy-xl-0 mb-2">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/email-notify/templates">전체</a></span>
                    <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? count($templates)) ?></b> 개</span>
                </span>
            </div>
        </div>

        <div class="table-responsive">
            <?= $this->listRenderHelper
                ->setColumns($columns)
                ->setRows($templates)
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

<script>
function deleteTemplate(templateId) {
    if (!confirm('이 템플릿을 삭제하시겠습니까?')) return;
    MubloRequest.requestJson('/admin/email-notify/templates/delete', { template_id: templateId }, { loading: true })
        .then(function(response) {
            alert(response.message || '삭제되었습니다.');
            location.reload();
        })
        .catch(function(err) {
            alert((err && err.message) || '삭제에 실패했습니다.');
        });
}
</script>
