<?php
/**
 * Front Error - 403 Forbidden
 *
 * 콘텐츠 영역용 에러 페이지 (Front 레이아웃 내에서 표시)
 *
 * @var string|null $message 컨트롤러가 넘긴 구체적 사유 (없으면 일반 문구)
 */
$this->assets->addCss('/serve/front/view/error/basic/css/error.css');

// 컨트롤러들은 '글쓰기 권한이 없습니다', '포인트가 부족합니다' 같은 사유를 넘긴다.
// 그걸 버리고 일반 문구만 띄우면 방문자는 무엇을 고쳐야 하는지 알 수 없다.
// 값은 서비스가 만든 문자열이지만 출력은 이스케이프한다.
$errorMessage = trim((string) ($message ?? ''));
?>
<div class="error-page error-page--403">
    <div class="error-page__content">
        <div class="error-page__icon error-page__icon--warning">
            <span>403</span>
        </div>
        <h1 class="error-page__title">접근 권한이 없습니다</h1>
        <p class="error-page__message">
            <?php if ($errorMessage !== ''): ?>
            <?= htmlspecialchars($errorMessage) ?>
            <?php else: ?>
            이 페이지에 접근할 권한이 없습니다.<br>
            로그인이 필요하거나 권한이 부족합니다.
            <?php endif; ?>
        </p>
        <div class="error-page__actions">
            <button type="button" class="btn btn--secondary" onclick="history.back()">뒤로 가기</button>
            <a href="/" class="btn btn--primary">홈으로</a>
        </div>
    </div>
</div>
