<?php
/**
 * Shop Admin - 404 에러 페이지
 *
 * @var string $message 에러 메시지
 */
$message = $message ?? '요청하신 항목을 찾을 수 없습니다.';
?>
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <div class="card shadow-sm">
                <div class="card-body py-5">
                    <div class="mb-3">
                        <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: var(--bs-warning);"></i>
                    </div>
                    <h4 class="mb-3">항목을 찾을 수 없습니다</h4>
                    <p class="text-muted mb-4"><?= htmlspecialchars($message) ?></p>
                    <button type="button" class="btn btn-secondary" onclick="history.back()">
                        <i class="bi bi-arrow-left"></i> 이전 페이지
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
