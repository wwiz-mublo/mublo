<?php
/**
 * Admin Error 403
 *
 * @var string $message 에러 메시지
 */
$message = $message ?? '이 페이지에 접근할 권한이 없습니다.';
?>
<div class="page-container">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card mt-5">
                <div class="card-body text-center py-5">
                    <h1 class="display-1 text-warning mb-4">403</h1>
                    <h4 class="mb-3">접근 권한이 없습니다</h4>
                    <p class="text-muted mb-4"><?= htmlspecialchars($message) ?></p>
                    <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left"></i> 이전 페이지
                    </a>
                    <a href="/admin" class="btn btn-primary">
                        <i class="bi bi-house"></i> 대시보드
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
