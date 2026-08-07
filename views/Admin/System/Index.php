<?php
/**
 * Admin System - Index
 *
 * 시스템 관리 페이지 (캐시 초기화 + DB 백업 + 마이그레이션 점검 + 임시파일 정리)
 *
 * @var string $pageTitle
 * @var string $description
 * @var array $cacheInfo
 * @var array $migrationStatuses
 * @var int $totalPending
 * @var int $totalExecuted
 * @var array $tempFileInfo
 * @var array $extensionLoadFailures
 * @var array $memberActionDiagnostics
 * @var array $resetItems
 * @var bool $isSuper
 */
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '시스템 관리') ?></h3>
            <p><?= htmlspecialchars($description ?? '') ?></p>
        </div>
    </div>

    <?php if (!empty($extensionLoadFailures)): ?>
    <div class="page-block">
        <div class="card border-warning">
            <div class="card-hero">
                <i class="bi bi-exclamation-triangle text-warning"></i>
                <span>확장 로딩 진단</span>
                <span class="badge bg-warning text-dark ms-auto"><?= count($extensionLoadFailures) ?>건</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>구분</th>
                                <th>이름</th>
                                <th>단계</th>
                                <th>오류</th>
                                <th>위치</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($extensionLoadFailures as $failure): ?>
                            <tr>
                                <?php $srcColor = match($failure['type'] ?? '') { 'plugin' => 'info', 'package' => 'primary', default => 'secondary' }; ?>
                                <td><span class="badge bg-<?= $srcColor ?>-subtle text-<?= $srcColor ?>-emphasis border border-<?= $srcColor ?>-subtle"><?= htmlspecialchars($failure['type'] ?? '-') ?></span></td>
                                <td><?= htmlspecialchars($failure['name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($failure['stage'] ?? '-') ?></td>
                                <td>
                                    <div><?= htmlspecialchars($failure['message'] ?? '-') ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($failure['exception'] ?? '') ?></small>
                                </td>
                                <td>
                                    <code class="small"><?= htmlspecialchars(basename($failure['file'] ?? '-')) ?>:<?= (int)($failure['line'] ?? 0) ?></code>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($memberActionDiagnostics)): ?>
    <div class="page-block">
        <div class="card border-warning">
            <div class="card-hero">
                <i class="bi bi-person-exclamation text-warning"></i>
                <span>회원 액션 정의 진단</span>
                <span class="badge bg-warning text-dark ms-auto"><?= count($memberActionDiagnostics) ?>건</span>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>출처</th><th>액션</th><th>사유</th></tr></thead>
                    <tbody>
                    <?php foreach ($memberActionDiagnostics as $diagnostic): ?>
                        <tr>
                            <td><?= htmlspecialchars(($diagnostic['sourceType'] ?? '-') . ':' . ($diagnostic['sourceName'] ?? '-')) ?></td>
                            <td><code><?= htmlspecialchars($diagnostic['actionKey'] ?? '-') ?></code></td>
                            <td><?= htmlspecialchars($diagnostic['reason'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="page-block row">

        <!-- ===== 좌측 열: 캐시 관리 + 임시파일 정리 ===== -->
        <div class="col-12 col-lg-6 d-flex flex-column gap-4">
            <!-- 캐시 관리 -->
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-lightning-charge text-pastel-orange"></i>
                    <span>캐시 관리</span>
                    <span class="badge bg-secondary ms-auto"><?= htmlspecialchars($cacheInfo['driver'] ?? 'file') ?></span>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-3">
                        <tbody>
                            <tr>
                                <th class="text-muted" style="width:120px">캐시 드라이버</th>
                                <td>
                                    <span class="badge <?= ($cacheInfo['driver'] ?? 'file') === 'redis' ? 'bg-danger' : 'bg-info' ?>">
                                        <?= htmlspecialchars(strtoupper($cacheInfo['driver'] ?? 'file')) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php if (isset($cacheInfo['size_human'])): ?>
                            <tr>
                                <th class="text-muted">캐시 용량</th>
                                <td id="cache-size"><?= htmlspecialchars($cacheInfo['size_human']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th class="text-muted">저장 위치</th>
                                <td><code class="small"><?= htmlspecialchars($cacheInfo['path'] ?? '-') ?></code></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="d-grid">
                        <button type="button" class="btn btn-outline-danger" id="btn-clear-cache">
                            <i class="bi bi-trash"></i> 전체 캐시 초기화
                        </button>
                    </div>

                    <div id="cache-result" class="mt-3" style="display:none;"></div>
                </div>
            </div>

            <!-- 임시파일 정리 -->
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-file-earmark-x text-pastel-purple"></i>
                    <span>임시파일 정리</span>
                    <?php if (($tempFileInfo['total']['count'] ?? 0) > 0): ?>
                        <span class="badge bg-warning text-dark ms-auto"><?= $tempFileInfo['total']['count'] ?>개 파일</span>
                    <?php else: ?>
                        <span class="badge bg-success ms-auto">깨끗함</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        에디터 이미지 업로드, 파일 첨부 등에서 저장되지 않고 남은 임시파일을 정리합니다.
                    </p>

                    <table class="table table-sm mb-3">
                        <thead>
                            <tr>
                                <th>구분</th>
                                <th class="text-center">파일 수</th>
                                <th class="text-end">용량</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><i class="bi bi-image text-info"></i> 에디터 임시 이미지</td>
                                <td class="text-center" id="temp-editor-count"><?= $tempFileInfo['editor']['count'] ?? 0 ?></td>
                                <td class="text-end" id="temp-editor-size"><?= $tempFileInfo['editor']['size_human'] ?? '0 B' ?></td>
                            </tr>
                            <tr>
                                <td><i class="bi bi-file-lock text-warning"></i> 보안 임시 파일</td>
                                <td class="text-center" id="temp-secure-count"><?= $tempFileInfo['secure']['count'] ?? 0 ?></td>
                                <td class="text-end" id="temp-secure-size"><?= $tempFileInfo['secure']['size_human'] ?? '0 B' ?></td>
                            </tr>
                            <tr class="fw-bold">
                                <td>합계</td>
                                <td class="text-center" id="temp-total-count"><?= $tempFileInfo['total']['count'] ?? 0 ?></td>
                                <td class="text-end" id="temp-total-size"><?= $tempFileInfo['total']['size_human'] ?? '0 B' ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="row g-2 align-items-end">
                        <div class="col">
                            <label class="form-label small text-muted mb-1">보관 기간</label>
                            <select id="temp-max-age" class="form-select">
                                <option value="1">1시간 이상 경과</option>
                                <option value="6">6시간 이상 경과</option>
                                <option value="12">12시간 이상 경과</option>
                                <option value="24" selected>24시간 이상 경과</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-outline-warning" id="btn-cleanup-temp">
                                <i class="bi bi-trash"></i> 임시파일 정리
                            </button>
                        </div>
                    </div>

                    <div id="temp-result" class="mt-3" style="display:none;"></div>
                </div>
            </div>
        </div>

        <!-- ===== 마이그레이션 점검 ===== -->
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-hero">
                    <i class="bi bi-database-check text-pastel-blue"></i>
                    <span>데이터베이스 마이그레이션</span>
                    <?php if ($totalPending > 0): ?>
                        <span class="badge bg-warning text-dark ms-auto"><?= $totalPending ?>개 대기</span>
                    <?php else: ?>
                        <span class="badge bg-success ms-auto">최신 상태</span>
                    <?php endif; ?>
                    <?php if (!empty($isSuper)): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="btn-backup-database">
                            <i class="bi bi-download"></i> DB 백업
                        </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-3">
                            <thead>
                                <tr>
                                    <th>구분</th>
                                    <th>이름</th>
                                    <th class="text-center">실행됨</th>
                                    <th class="text-center">대기</th>
                                    <th class="text-center">상태</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($migrationStatuses as $status): ?>
                                <tr>
                                    <td>
                                        <?php $srcColor = match($status['source']) {
                                            'plugin' => 'info',
                                            'package' => 'primary',
                                            default => 'secondary'
                                        }; ?>
                                        <span class="badge bg-<?= $srcColor ?>-subtle text-<?= $srcColor ?>-emphasis border border-<?= $srcColor ?>-subtle"><?= htmlspecialchars(ucfirst($status['source'])) ?></span>
                                    </td>
                                    <td><i class="<?= htmlspecialchars($status['icon'] ?? 'bi-circle') ?> me-2 text-muted"></i><?= htmlspecialchars($status['name']) ?></td>
                                    <td class="text-center"><?= count($status['executed']) ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($status['pending'])): ?>
                                            <span class="text-warning fw-bold"><?= count($status['pending']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (empty($status['pending'])): ?>
                                            <i class="bi bi-check-circle text-success"></i>
                                        <?php else: ?>
                                            <i class="bi bi-exclamation-triangle text-warning"></i>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if (!empty($status['pending'])): ?>
                                <tr>
                                    <td colspan="5" class="ps-4 py-1">
                                        <small class="text-muted">대기 중: </small>
                                        <?php foreach ($status['pending'] as $file): ?>
                                            <span class="badge bg-light text-dark border me-1"><?= htmlspecialchars($file) ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPending > 0): ?>
                    <div class="d-grid">
                        <button type="button" class="btn btn-warning" id="btn-run-migration">
                            <i class="bi bi-play-circle"></i> 미실행 마이그레이션 실행 (<?= $totalPending ?>개)
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="text-center text-muted py-2">
                        <i class="bi bi-check-circle-fill text-success"></i>
                        모든 마이그레이션이 실행된 상태입니다.
                    </div>
                    <?php endif; ?>

                    <div id="migration-result" class="mt-3" style="display:none;"></div>
                </div>
            </div>
        </div>

    </div>

    <?php if (!empty($isSuper)): ?>
    <!-- ===== 데이터베이스 백업 모달 (SUPER 전용) ===== -->
    <div class="modal fade" id="databaseBackupModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-database-down text-primary me-1"></i> 데이터베이스 백업</h5>
                    <button type="button" class="btn-close" id="databaseBackupHeaderClose" data-bs-dismiss="modal" aria-label="닫기"></button>
                </div>
                <div class="modal-body">
                    <div id="databaseBackupReady">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-1"></i>
                            현재 Mublo 데이터베이스 전체를 백업하여 내려받습니다.
                        </div>
                        <ul class="small text-muted ps-3 mb-3">
                            <li>이미지와 첨부파일은 백업에 포함되지 않습니다.</li>
                            <li>백업 파일은 다운로드가 끝나면 서버에서 자동 삭제됩니다.</li>
                            <li>마이그레이션 실행 전 백업 파일을 안전한 곳에 보관하세요.</li>
                        </ul>
                        <div>
                            <label for="databaseBackupPassword" class="form-label fw-bold">관리자 비밀번호 확인</label>
                            <input type="password" class="form-control" id="databaseBackupPassword"
                                   placeholder="비밀번호를 입력하세요" autocomplete="current-password">
                            <div class="invalid-feedback" id="databaseBackupPasswordError">비밀번호를 입력해주세요.</div>
                        </div>
                    </div>

                    <div id="databaseBackupProgress" class="text-center py-4" style="display:none;">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">백업 생성 중</span>
                        </div>
                        <h6 class="mb-1">데이터베이스를 백업하고 있습니다.</h6>
                        <p class="small text-muted mb-0">데이터 용량에 따라 시간이 걸릴 수 있습니다. 창을 닫지 마세요.</p>
                    </div>

                    <div id="databaseBackupComplete" class="text-center py-3" style="display:none;">
                        <i class="bi bi-check-circle-fill text-success fs-1"></i>
                        <h6 class="mt-3 mb-1">백업이 완료되었습니다.</h6>
                        <p class="small text-muted mb-3" id="databaseBackupCompleteMessage"></p>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="databaseBackupDownloadAgain">
                            <i class="bi bi-download"></i> 파일 다시 저장
                        </button>
                    </div>

                    <div id="databaseBackupError" style="display:none;">
                        <div class="alert alert-danger mb-0">
                            <i class="bi bi-x-circle me-1"></i>
                            <span id="databaseBackupErrorMessage">백업 중 오류가 발생했습니다.</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" id="databaseBackupFooter">
                    <button type="button" class="btn btn-secondary" id="databaseBackupCancel" data-bs-dismiss="modal">취소</button>
                    <button type="button" class="btn btn-primary" id="databaseBackupConfirm">
                        <i class="bi bi-download"></i> 백업 시작
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($isSuper) && !empty($resetItems)): ?>
    <!-- ===== 데이터 초기화 (SUPER 전용) ===== -->
    <div class="page-block row">
        <div class="col-12">
            <div class="card border-danger">
                <div class="card-hero bg-danger text-white rounded-top">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span>데이터 초기화</span>
                    <span class="badge bg-light text-danger ms-auto">SUPER 전용</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-4">
                        <i class="bi bi-shield-exclamation"></i>
                        <strong>주의:</strong> 초기화된 데이터는 복구할 수 없습니다. 신중하게 사용해 주세요.
                    </div>

                    <!-- 항목별 초기화 카드 그리드 -->
                    <div class="row g-3">
                        <?php foreach ($resetItems as $item): ?>
                            <?php foreach ($item['categories'] as $cat): ?>
                            <div class="col-12 col-md-6 col-xl-4">
                                <div class="card h-100 border">
                                    <div class="card-body d-flex flex-column">
                                        <h6 class="card-title mb-1">
                                            <i class="<?= htmlspecialchars($cat['icon'] ?? 'bi-circle') ?> me-1"></i>
                                            <?= htmlspecialchars($cat['label']) ?>
                                            <?php $srcColor = match($item['source']) { 'plugin' => 'info', 'package' => 'primary', default => 'secondary' }; ?>
                                            <span class="badge bg-<?= $srcColor ?>-subtle text-<?= $srcColor ?>-emphasis border border-<?= $srcColor ?>-subtle ms-1" style="font-size:10px">
                                                <?= htmlspecialchars($item['source'] === 'core' ? 'Core' : $item['name']) ?>
                                            </span>
                                        </h6>
                                        <p class="card-text small text-muted flex-grow-1"><?= htmlspecialchars($cat['description']) ?></p>
                                        <button type="button" class="btn btn-outline-danger btn-sm btn-reset-category"
                                                data-category="<?= htmlspecialchars($cat['id']) ?>"
                                                data-label="<?= htmlspecialchars($cat['label']) ?>"
                                                data-description="<?= htmlspecialchars($cat['description']) ?>">
                                            <i class="bi bi-trash"></i> 초기화
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>

                    <!-- 전체 초기화 -->
                    <hr class="my-4">
                    <div class="card border-danger bg-danger bg-opacity-10">
                        <div class="card-body text-center">
                            <h5 class="text-danger mb-2"><i class="bi bi-exclamation-octagon"></i> 전체 초기화</h5>
                            <p class="text-muted small mb-3">Core와 현재 활성·정상 부팅된 확장의 초기화 대상 데이터가 삭제됩니다. SUPER·사이트 소유자와 명시된 설정은 보존됩니다.</p>
                            <button type="button" class="btn btn-danger" id="btn-reset-all">
                                <i class="bi bi-radioactive"></i> 전체 초기화 실행
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 항목별 초기화 모달 -->
    <div class="modal fade" id="resetCategoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="resetCategoryModalTitle">데이터 초기화</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span id="resetCategoryDesc"></span>
                    </div>
                    <div class="mb-3">
                        <label for="resetCategoryPassword" class="form-label fw-bold">관리자 비밀번호 확인</label>
                        <input type="password" class="form-control" id="resetCategoryPassword" placeholder="비밀번호를 입력하세요" autocomplete="off">
                    </div>
                    <input type="hidden" id="resetCategoryKey" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="button" class="btn btn-danger" id="resetCategoryConfirmBtn">
                        <i class="bi bi-trash"></i> 초기화 실행
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 전체 초기화 모달 -->
    <div class="modal fade" id="resetAllModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-radioactive"></i> 전체 초기화</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-octagon"></i>
                        <strong>Core와 현재 활성·정상 부팅된 확장의 대상 데이터가 삭제됩니다.</strong><br>
                        <small>일반 회원은 탈퇴 처리되며 SUPER·사이트 소유자와 각 항목에 명시된 설정은 보존됩니다. 비활성 또는 부팅 실패 확장은 포함되지 않습니다.</small>
                    </div>
                    <div class="mb-3">
                        <label for="resetAllPassword" class="form-label fw-bold">관리자 비밀번호</label>
                        <input type="password" class="form-control" id="resetAllPassword" placeholder="비밀번호를 입력하세요" autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label for="resetAllConfirmText" class="form-label fw-bold">확인 문구 입력</label>
                        <input type="text" class="form-control" id="resetAllConfirmText" placeholder="'전체 초기화'를 정확히 입력하세요" autocomplete="off">
                        <div class="form-text">확인을 위해 <code>전체 초기화</code>를 정확히 입력해주세요.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="button" class="btn btn-danger" id="resetAllConfirmBtn" disabled>
                        <i class="bi bi-radioactive"></i> 전체 초기화 실행
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== 데이터베이스 백업 (SUPER 전용) =====
    const databaseBackupModalElement = document.getElementById('databaseBackupModal');
    let databaseBackupBlobUrl = null;
    let databaseBackupFileName = '';

    function formatBackupSize(bytes) {
        if (!Number.isFinite(bytes) || bytes <= 0) return '용량 확인 불가';
        const units = ['B', 'KB', 'MB', 'GB'];
        let value = bytes;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        return (unit === 0 ? value.toFixed(0) : value.toFixed(1)) + ' ' + units[unit];
    }

    function parseBackupFileName(disposition) {
        const match = /filename="?([^";]+)"?/i.exec(disposition || '');
        return match ? match[1] : 'mublo-db-backup.sql.gz';
    }

    function saveBackupBlob() {
        if (!databaseBackupBlobUrl) return;
        const link = document.createElement('a');
        link.href = databaseBackupBlobUrl;
        link.download = databaseBackupFileName;
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    function setDatabaseBackupState(state, message) {
        const ready = document.getElementById('databaseBackupReady');
        const progress = document.getElementById('databaseBackupProgress');
        const complete = document.getElementById('databaseBackupComplete');
        const error = document.getElementById('databaseBackupError');
        const footer = document.getElementById('databaseBackupFooter');
        const confirm = document.getElementById('databaseBackupConfirm');
        const cancel = document.getElementById('databaseBackupCancel');
        const headerClose = document.getElementById('databaseBackupHeaderClose');

        ready.style.display = state === 'ready' ? '' : 'none';
        progress.style.display = state === 'progress' ? '' : 'none';
        complete.style.display = state === 'complete' ? '' : 'none';
        error.style.display = state === 'error' ? '' : 'none';
        footer.style.display = state === 'complete' ? 'none' : '';

        const processing = state === 'progress';
        confirm.disabled = processing;
        cancel.disabled = processing;
        headerClose.disabled = processing;
        confirm.innerHTML = state === 'error'
            ? '<i class="bi bi-arrow-clockwise"></i> 다시 시도'
            : '<i class="bi bi-download"></i> 백업 시작';

        if (state === 'error') {
            document.getElementById('databaseBackupErrorMessage').textContent = message || '백업 중 오류가 발생했습니다.';
        }
    }

    document.getElementById('btn-backup-database')?.addEventListener('click', function() {
        if (databaseBackupBlobUrl) {
            URL.revokeObjectURL(databaseBackupBlobUrl);
            databaseBackupBlobUrl = null;
        }
        databaseBackupFileName = '';
        const password = document.getElementById('databaseBackupPassword');
        password.value = '';
        password.classList.remove('is-invalid');
        setDatabaseBackupState('ready');

        const modal = bootstrap.Modal.getOrCreateInstance(databaseBackupModalElement);
        modal.show();
        databaseBackupModalElement.addEventListener('shown.bs.modal', function handler() {
            password.focus();
            databaseBackupModalElement.removeEventListener('shown.bs.modal', handler);
        });
    });

    document.getElementById('databaseBackupConfirm')?.addEventListener('click', async function() {
        const passwordInput = document.getElementById('databaseBackupPassword');
        const password = passwordInput.value;
        if (!password) {
            passwordInput.classList.add('is-invalid');
            passwordInput.focus();
            return;
        }

        passwordInput.classList.remove('is-invalid');
        setDatabaseBackupState('progress');

        try {
            const response = await fetch('/admin/system/backup-database', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': <?= json_encode($csrfToken ?? '', JSON_UNESCAPED_SLASHES) ?>,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ password: password })
            });

            const contentType = response.headers.get('content-type') || '';
            if (!response.ok || contentType.includes('application/json')) {
                let payload = null;
                try { payload = await response.json(); } catch (e) { /* 응답 본문 없음 */ }
                throw new Error(payload?.message || '데이터베이스 백업에 실패했습니다.');
            }

            const blob = await response.blob();
            if (blob.size === 0) {
                throw new Error('생성된 백업 파일이 비어 있습니다.');
            }

            if (databaseBackupBlobUrl) URL.revokeObjectURL(databaseBackupBlobUrl);
            databaseBackupBlobUrl = URL.createObjectURL(blob);
            databaseBackupFileName = parseBackupFileName(response.headers.get('content-disposition'));
            saveBackupBlob();

            document.getElementById('databaseBackupCompleteMessage').textContent =
                databaseBackupFileName + ' · ' + formatBackupSize(blob.size);
            setDatabaseBackupState('complete');
        } catch (error) {
            setDatabaseBackupState('error', error?.message || '백업 중 오류가 발생했습니다.');
        }
    });

    document.getElementById('databaseBackupPassword')?.addEventListener('keydown', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            document.getElementById('databaseBackupConfirm').click();
        }
    });

    document.getElementById('databaseBackupDownloadAgain')?.addEventListener('click', saveBackupBlob);

    databaseBackupModalElement?.addEventListener('hidden.bs.modal', function() {
        if (databaseBackupBlobUrl) {
            URL.revokeObjectURL(databaseBackupBlobUrl);
            databaseBackupBlobUrl = null;
        }
    });

    // 캐시 초기화
    document.getElementById('btn-clear-cache')?.addEventListener('click', function() {
        const btn = this;
        MubloRequest.showConfirm('전체 캐시를 초기화하시겠습니까?', function() {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>초기화 중...';

            MubloRequest.requestJson('/admin/system/clearCache', {}, { method: 'POST' })
                .then(function(res) {
                    const el = document.getElementById('cache-result');
                    el.style.display = '';
                    el.innerHTML = '<div class="alert alert-success mb-0"><i class="bi bi-check-circle"></i> ' + (res.message || '캐시를 초기화했습니다.') + '</div>';
                    const sizeEl = document.getElementById('cache-size');
                    if (sizeEl) sizeEl.textContent = '0 B';
                })
                .catch(function() {
                    const el = document.getElementById('cache-result');
                    el.style.display = '';
                    el.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-x-circle"></i> 캐시 초기화 중 오류가 발생했습니다.</div>';
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-trash"></i> 전체 캐시 초기화';
                });
        }, { type: 'warning' });
    });

    // 임시파일 정리
    document.getElementById('btn-cleanup-temp')?.addEventListener('click', function() {
        const maxAge = document.getElementById('temp-max-age').value;
        const btn = this;
        MubloRequest.showConfirm(maxAge + '시간 이상 경과된 임시파일을 삭제하시겠습니까?', function() {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>정리 중...';

            MubloRequest.requestJson('/admin/system/cleanupTemp', { maxAgeHours: parseInt(maxAge) }, { method: 'POST' })
                .then(function(res) {
                    const el = document.getElementById('temp-result');
                    el.style.display = '';
                    el.innerHTML = '<div class="alert alert-success mb-0"><i class="bi bi-check-circle"></i> ' + (res.message || '정리 완료') + '</div>';
                    // 수치 갱신
                    if (res.data) {
                        document.getElementById('temp-editor-count').textContent = '0';
                        document.getElementById('temp-secure-count').textContent = '0';
                        document.getElementById('temp-total-count').textContent = '0';
                        document.getElementById('temp-editor-size').textContent = '0 B';
                        document.getElementById('temp-secure-size').textContent = '0 B';
                        document.getElementById('temp-total-size').textContent = '0 B';
                    }
                })
                .catch(function() {
                    const el = document.getElementById('temp-result');
                    el.style.display = '';
                    el.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-x-circle"></i> 임시파일 정리 중 오류가 발생했습니다.</div>';
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-trash"></i> 임시파일 정리';
                });
        }, { type: 'warning' });
    });

    // 마이그레이션 실행
    document.getElementById('btn-run-migration')?.addEventListener('click', function() {
        const btn = this;
        MubloRequest.showConfirm('미실행 마이그레이션을 실행하시겠습니까?\n실행 후 되돌릴 수 없습니다.', function() {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>실행 중...';

            MubloRequest.requestJson('/admin/system/runMigration', {}, { method: 'POST' })
                .then(function(res) {
                    const el = document.getElementById('migration-result');
                    el.style.display = '';
                    let html = '<div class="alert alert-success mb-0"><i class="bi bi-check-circle"></i> ' + (res.message || '마이그레이션 완료') + '</div>';
                    if (res.data && res.data.executed && res.data.executed.length > 0) {
                        html += '<ul class="list-unstyled mt-2 mb-0 small">';
                        res.data.executed.forEach(function(f) {
                            html += '<li><i class="bi bi-check text-success"></i> ' + f + '</li>';
                        });
                        html += '</ul>';
                    }
                    el.innerHTML = html;
                    // 2초 후 페이지 새로고침 (상태 갱신)
                    setTimeout(function() { location.reload(); }, 2000);
                })
                .catch(function() {
                    const el = document.getElementById('migration-result');
                    el.style.display = '';
                    el.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-x-circle"></i> 마이그레이션 실행 중 오류가 발생했습니다.</div>';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-play-circle"></i> 미실행 마이그레이션 실행';
                });
        }, { type: 'warning' });
    });

    // ===== 데이터 초기화 =====

    // 항목별 초기화 모달 열기
    document.querySelectorAll('.btn-reset-category').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var category = this.dataset.category;
            var label = this.dataset.label;
            var description = this.dataset.description;

            document.getElementById('resetCategoryModalTitle').textContent = '데이터 초기화: ' + label;
            document.getElementById('resetCategoryDesc').textContent = description;
            document.getElementById('resetCategoryKey').value = category;
            document.getElementById('resetCategoryPassword').value = '';

            var modal = new bootstrap.Modal(document.getElementById('resetCategoryModal'));
            modal.show();

            // 모달 열린 후 비밀번호 필드 포커스
            document.getElementById('resetCategoryModal').addEventListener('shown.bs.modal', function handler() {
                document.getElementById('resetCategoryPassword').focus();
                this.removeEventListener('shown.bs.modal', handler);
            });
        });
    });

    // 항목별 초기화 실행
    document.getElementById('resetCategoryConfirmBtn')?.addEventListener('click', function() {
        var category = document.getElementById('resetCategoryKey').value;
        var password = document.getElementById('resetCategoryPassword').value;

        if (!password) {
            MubloRequest.showAlert('비밀번호를 입력해주세요.', 'warning');
            return;
        }

        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>초기화 중...';

        MubloRequest.requestJson('/admin/system/resetData', {
            category: category,
            password: password
        }, { method: 'POST' })
            .then(function(res) {
                bootstrap.Modal.getInstance(document.getElementById('resetCategoryModal')).hide();
                MubloRequest.showToast(res.message || '초기화가 완료되었습니다.', 'success');
            })
            .catch(function() {})
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-trash"></i> 초기화 실행';
            });
    });

    // 비밀번호 필드 Enter 키 지원
    document.getElementById('resetCategoryPassword')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('resetCategoryConfirmBtn').click();
        }
    });

    // 전체 초기화 모달 열기
    document.getElementById('btn-reset-all')?.addEventListener('click', function() {
        document.getElementById('resetAllPassword').value = '';
        document.getElementById('resetAllConfirmText').value = '';
        document.getElementById('resetAllConfirmBtn').disabled = true;

        var modal = new bootstrap.Modal(document.getElementById('resetAllModal'));
        modal.show();

        document.getElementById('resetAllModal').addEventListener('shown.bs.modal', function handler() {
            document.getElementById('resetAllPassword').focus();
            this.removeEventListener('shown.bs.modal', handler);
        });
    });

    // 전체 초기화 확인 문구 검증 → 버튼 활성화
    document.getElementById('resetAllConfirmText')?.addEventListener('input', function() {
        var btn = document.getElementById('resetAllConfirmBtn');
        btn.disabled = this.value !== '전체 초기화';
    });

    // 전체 초기화 실행
    document.getElementById('resetAllConfirmBtn')?.addEventListener('click', function() {
        var password = document.getElementById('resetAllPassword').value;
        var confirmText = document.getElementById('resetAllConfirmText').value;

        if (!password) {
            MubloRequest.showAlert('비밀번호를 입력해주세요.', 'warning');
            return;
        }

        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>초기화 중...';

        MubloRequest.requestJson('/admin/system/resetAll', {
            password: password,
            confirmText: confirmText
        }, { method: 'POST' })
            .then(function(res) {
                bootstrap.Modal.getInstance(document.getElementById('resetAllModal')).hide();
                MubloRequest.showAlert(res.message || '전체 초기화가 완료되었습니다.', 'success', {
                    onClose: function() { location.reload(); }
                });
            })
            .catch(function() {})
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-radioactive"></i> 전체 초기화 실행';
            });
    });
});
</script>
