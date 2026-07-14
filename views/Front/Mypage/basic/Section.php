<?php
/**
 * 마이페이지 섹션 셸 (액자) — 코어 소유
 *
 * 패키지가 등록한 콘텐츠 partial($partialPath)을 끼워 _layout(사이드바+콘텐츠)으로 감싼다.
 * partial은 이 스코프의 변수(withData로 주입된 $items/$pagination 등)에 접근한다.
 *
 * @var string $partialPath   패키지 콘텐츠 partial 절대 경로
 * @var array  $mypageMenus   사이드바 메뉴
 * @var array  $user          로그인 사용자
 * @var string $sectionLabel  섹션 라벨
 */
ob_start();
if (!empty($partialPath) && is_file($partialPath)) {
    include $partialPath;
} else {
    echo '<p class="text-muted text-center py-5">콘텐츠를 불러올 수 없습니다.</p>';
}
$content = ob_get_clean();

include __DIR__ . '/_layout.php';
