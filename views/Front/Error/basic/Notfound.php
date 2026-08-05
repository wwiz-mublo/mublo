<?php
/**
 * Front Error - 404 Not Found
 *
 * 콘텐츠 영역용 에러 페이지 (Front 레이아웃 내에서 표시)
 *
 * @var string|null $message 컨트롤러가 넘긴 구체적 사유 (없으면 일반 문구)
 */
$this->assets->addCss('/serve/front/view/error/basic/css/error.css');

// 컨트롤러들은 '게시판을 찾을 수 없습니다' 처럼 무엇이 없는지 넘긴다. 그걸 버리면
// 방문자는 주소를 잘못 친 건지 글이 지워진 건지 구분할 수 없다.
// 값은 서비스가 만든 문자열이지만 출력은 이스케이프한다.
$errorMessage = trim((string) ($message ?? ''));
?>
<div class="error-page error-page--404">
    <div class="error-page__content">
        <div class="error-page__icon">
            <span>404</span>
        </div>
        <h1 class="error-page__title">페이지를 찾을 수 없습니다</h1>
        <p class="error-page__message">
            <?php if ($errorMessage !== ''): ?>
            <?= htmlspecialchars($errorMessage) ?>
            <?php else: ?>
            요청하신 페이지가 존재하지 않거나 이동되었습니다.<br>
            주소를 다시 확인해 주세요.
            <?php endif; ?>
        </p>
        <div class="error-page__actions">
            <button type="button" class="btn btn--secondary" onclick="history.back()">뒤로 가기</button>
            <a href="/" class="btn btn--primary">홈으로</a>
        </div>
    </div>
</div>
