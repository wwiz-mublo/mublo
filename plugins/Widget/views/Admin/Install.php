<?php
/**
 * 위젯 플러그인 설치 안내
 *
 * @var string $pageTitle 페이지 제목
 * @var array  $pending   미실행 마이그레이션 파일 목록
 */
$pending = $pending ?? [];
$pageTitle = $pageTitle ?? ($title ?? '위젯 플러그인 설치');
?>

<div class="page-title">
    <div class="page-title-text">
        <h3><?= htmlspecialchars($pageTitle) ?></h3>
    </div>
</div>

<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-grid-1x2 text-secondary" style="font-size:3rem;"></i>
        <h4 class="mt-3">위젯 플러그인 설치가 필요합니다</h4>
        <p class="text-muted mb-4">데이터베이스 테이블을 생성해야 위젯 기능을 사용할 수 있습니다.</p>

        <?php if (!empty($pending)): ?>
        <div class="mb-4 mx-auto" style="max-width:500px;">
            <h6 class="text-start">실행할 마이그레이션:</h6>
            <ul class="list-group text-start">
                <?php foreach ($pending as $file): ?>
                <li class="list-group-item py-1">
                    <i class="bi bi-file-earmark-code me-1 text-primary"></i>
                    <code><?= htmlspecialchars($file) ?></code>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <button type="button" class="btn btn-primary" id="btn-install">
            <i class="bi bi-download"></i> 지금 설치하기
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('btn-install');
    if (!btn) return;

    btn.addEventListener('click', function () {
        if (!confirm('위젯 플러그인을 설치하시겠습니까?\n데이터베이스 테이블이 생성/변경됩니다.')) {
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>설치 중...';

        MubloRequest.requestJson('/admin/widget/install', {}, { loading: true })
            .then(function () {
                location.reload();
            })
            .catch(function (err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-download"></i> 지금 설치하기';
                alert((err && err.message) || '설치에 실패했습니다.');
            });
    });
});
</script>
