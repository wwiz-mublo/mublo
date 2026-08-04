<?php
/**
 * Admin Error 404
 *
 * @var string $message 에러 메시지
 */
$message = $message ?? '요청하신 페이지를 찾을 수 없습니다.';
?>
<div class="page-container">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card mt-5">
                <div class="card-body text-center py-5">
                    <h1 class="display-1 text-muted mb-4">404</h1>
                    <h4 class="mb-3">페이지를 찾을 수 없습니다</h4>
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
