<?php
/**
 * Front Error - 403 Forbidden
 *
 * 콘텐츠 영역용 에러 페이지 (Front 레이아웃 내에서 표시)
 */
$this->assets->addCss('/serve/front/view/error/basic/css/error.css');
?>
<div class="error-page error-page--403">
    <div class="error-page__content">
        <div class="error-page__icon error-page__icon--warning">
            <span>403</span>
        </div>
        <h1 class="error-page__title">접근 권한이 없습니다</h1>
        <p class="error-page__message">
            이 페이지에 접근할 권한이 없습니다.<br>
            로그인이 필요하거나 권한이 부족합니다.
        </p>
        <div class="error-page__actions">
            <button type="button" class="btn btn--secondary" onclick="history.back()">뒤로 가기</button>
            <a href="/" class="btn btn--primary">홈으로</a>
        </div>
    </div>
</div>
