<?php
/**
 * 설치 시작 전 라이선스 동의
 *
 * @var string|null $licenseText
 * @var string $licenseToken
 * @var string|null $licenseError
 */
?>

<div class="content license-content">
    <div class="license-heading">
        <div class="license-emblem" aria-hidden="true">MIT</div>
        <div>
            <span class="license-kicker">MUBLO SETUP</span>
            <h2>라이선스 동의</h2>
            <p>간단한 이용 조건을 확인하면 바로 설치를 시작할 수 있습니다.</p>
        </div>
    </div>

    <div class="license-summary" aria-label="MIT 라이선스 요약">
        <div class="license-summary-item">
            <span class="license-summary-number">01</span>
            <div>
                <strong>자유로운 사용</strong>
                <span>상업적 이용·수정·배포 가능</span>
            </div>
        </div>
        <div class="license-summary-item">
            <span class="license-summary-number">02</span>
            <div>
                <strong>고지 유지</strong>
                <span>저작권 및 라이선스 고지 포함</span>
            </div>
        </div>
        <div class="license-summary-item">
            <span class="license-summary-number">03</span>
            <div>
                <strong>보증 없음</strong>
                <span>소프트웨어는 현 상태로 제공</span>
            </div>
        </div>
    </div>

    <?php if ($licenseText === null): ?>
        <div class="alert alert-error">
            <strong>라이선스 파일을 읽을 수 없습니다.</strong><br>
            배포 파일의 루트에 <code>LICENSE</code>가 있는지 확인한 후 다시 시도하세요.
        </div>
    <?php else: ?>
        <div class="license-document-shell">
            <div class="license-document-header">
                <div>
                    <span class="license-status-dot" aria-hidden="true"></span>
                    <strong>MIT License</strong>
                </div>
                <span>라이선스 전문</span>
            </div>
            <textarea class="license-document"
                      aria-label="MIT 라이선스 전문"
                      readonly
                      spellcheck="false"><?= htmlspecialchars($licenseText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            <p class="license-third-party">
                제3자 라이브러리는 각 라이브러리의 라이선스 조건을 따릅니다.
            </p>
        </div>

        <?php if ($licenseError !== null): ?>
            <div class="alert alert-error" role="alert">
                <?= htmlspecialchars($licenseError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="license-form">
            <input type="hidden" name="action" value="accept_license">
            <input type="hidden" name="license_token" value="<?= htmlspecialchars($licenseToken, ENT_QUOTES, 'UTF-8') ?>">

            <label class="license-agreement">
                <input type="checkbox" name="license_agree" value="1" required>
                <span>
                    <strong>라이선스 내용에 동의합니다</strong>
                    <small>MIT 라이선스 전문과 제3자 라이브러리 안내를 확인했습니다.</small>
                </span>
            </label>

            <button type="submit" class="btn btn-primary license-submit">설치 시작 <span aria-hidden="true">→</span></button>
        </form>
    <?php endif; ?>
</div>
