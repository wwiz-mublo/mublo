<?php
/**
 * Front Error - 404 Not Found
 *
 * 콘텐츠 영역용 에러 페이지 (Front 레이아웃 내에서 표시)
 */
$this->assets->addCss('/serve/front/view/error/basic/css/error.css');
?>
<div class="error-page error-page--404">
    <div class="error-page__content">
        <div class="error-page__icon">
            <span>404</span>
        </div>
        <h1 class="error-page__title">페이지를 찾을 수 없습니다</h1>
        <p class="error-page__message">
            요청하신 페이지가 존재하지 않거나 이동되었습니다.<br>
            주소를 다시 확인해 주세요.
        </p>
        <div class="error-page__actions">
            <button type="button" class="btn btn--secondary" onclick="history.back()">뒤로 가기</button>
            <a href="/" class="btn btn--primary">홈으로</a>
        </div>
    </div>
</div>
