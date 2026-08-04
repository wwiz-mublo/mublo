<?php
/**
 * Include 블록 샘플 파일
 *
 * 이 디렉토리(views/Block/include/)에 PHP 파일을 추가하면
 * 관리자 블록 편집에서 셀렉트 박스로 선택할 수 있습니다.
 *
 * ─────────────────────────────────────────────────
 * 사용 가능한 변수
 * ─────────────────────────────────────────────────
 *
 * @var array $mublo                 공통 Front 뷰 계약
 * @var array $contentConfig          블록 content_config 전체
 * @var array $params                 content_config['params'] 값
 *
 * ─────────────────────────────────────────────────
 * 사용 예시
 * ─────────────────────────────────────────────────
 *
 * <?php
 * $member = $mublo['viewer']['member'];
 * $domainId = $mublo['site']['domainId'];
 * $isLoggedIn = $mublo['viewer']['authenticated'];
 * ?>
 *
 * <?php if ($isLoggedIn): ?>
 *     <p><?= htmlspecialchars($member['nickname']) ?>님 환영합니다.</p>
 * <?php else: ?>
 *     <p><a href="/auth/login">로그인</a></p>
 * <?php endif; ?>
 *
 * ─────────────────────────────────────────────────
 * 주의사항
 * ─────────────────────────────────────────────────
 *
 * - 이 파일은 블록 렌더링 시 include 됩니다
 * - echo / <?= ?> 로 출력한 내용이 블록 HTML이 됩니다
 * - 외부 파일 include, eval 등은 보안상 사용하지 마세요
 * - 파일명은 영문/숫자/하이픈/언더스코어만 권장합니다
 */
?>

<div style="padding: 20px; text-align: center; color: #607086;">
    <p>Include 블록 샘플입니다. 이 파일을 복사하여 새 페이지를 만드세요.</p>
    <p><code>views/Block/include/</code> 디렉토리에 PHP 파일을 추가하면 관리자에서 선택할 수 있습니다.</p>
</div>
