<?php
/**
 * Block Skin: outlogin/basic
 *
 * 로그인 위젯 기본 스킨
 *
 * MubloItemLayout 비적용: 단일 콘텐츠 블록
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Contract\Block\BlockColumnView $column 블록 칸 엔티티
 * @var bool $isLoggedIn 로그인 상태
 * @var array|null $member 회원 정보 (로그인 시)
 * @var string[] $loginFormExtras 로그인 폼 확장 HTML (소셜 로그인 등)
 * @var string $deviceClass PC/모바일 출력 제어 CSS 클래스
 */
$deviceCss = !empty($deviceClass) ? ' ' . $deviceClass : '';
$assets->addCss('/serve/block/outlogin/basic/style.css');
?>
<div class="block-outlogin block-outlogin--basic <?= $isLoggedIn ? 'block-outlogin--member' : 'block-outlogin--guest' ?><?= $deviceCss ?>">
    <?php include $titlePartial; ?>

    <div class="block-outlogin__content block-body">
        <?php if ($isLoggedIn): ?>
            <div class="block-outlogin__info">
                <span class="block-outlogin__name"><?= htmlspecialchars($member['name'] ?? '') ?></span>님 환영합니다.
                <?php if (!empty($member['level_title'])): ?>
                <span class="block-outlogin__level"><?= htmlspecialchars($member['level_title']) ?></span>
                <?php endif; ?>
            </div>
            <div class="block-outlogin__buttons">
                <a href="/mypage/profile" class="block-outlogin__btn block-outlogin__btn--profile">내정보</a>
                <a href="/logout" class="block-outlogin__btn block-outlogin__btn--logout">로그아웃</a>
            </div>
        <?php else: ?>
            <form id="outlogin-form" name="outlogin-frm">
                <div class="block-outlogin__field">
                    <input type="text" name="user_id" placeholder="아이디" class="block-outlogin__input" required>
                </div>
                <div class="block-outlogin__field">
                    <input type="password" name="password" placeholder="비밀번호" class="block-outlogin__input" required>
                </div>
                <button type="button" class="block-outlogin__btn block-outlogin__btn--login mublo-submit"
                        data-target="/login"
                        data-callback="outloginComplete">로그인</button>
                <div class="block-outlogin__links">
                    <a href="/member/register" class="block-outlogin__link">회원가입</a>
                    <a href="/find-account" class="block-outlogin__link">비밀번호 찾기</a>
                </div>
            </form>

            <?php if (!empty($loginFormExtras)): ?>
                <?php foreach ($loginFormExtras as $html): ?>
                    <?= $html ?>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
if (typeof window.outloginComplete === 'undefined') {
    window.outloginComplete = function(response) {
        if (response.result === 'success') {
            location.reload();
        }
    };

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        var form = e.target.closest('#outlogin-form');
        if (!form) return;
        e.preventDefault();
        var btn = form.querySelector('.mublo-submit');
        if (btn) btn.click();
    });
}
</script>
