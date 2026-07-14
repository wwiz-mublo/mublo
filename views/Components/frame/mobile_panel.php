<?php
/**
 * 모바일 패널 컴포넌트 (오프캔버스: 검색 + GNB 세로 + 유틸리티)
 *
 * 파일 스킨(frame/{skin}/Header.php)과 프레임 템플릿 슬롯({{mobile_panel}})이 공유한다.
 * 토글 동작은 front.js가 #mubloPanel / #mubloPanelToggle / #mubloPanelClose 훅으로
 * 처리하므로, 오버라이드 header에도 토글 버튼과 이 패널이 함께 있어야 한다.
 *
 * @var array      $menus                   visibility 필터링된 메뉴 트리
 * @var string     $activeMenuCode          현재 활성 메뉴 코드
 * @var array      $utilityMenus            유틸리티 메뉴 목록 (원본 — 필터는 컴포넌트가)
 * @var array      $viewer                  $mublo.viewer 계약
 */
$menus = $menus ?? [];
$activeMenuCode = $activeMenuCode ?? '';
?>
<aside class="mublo-panel" id="mubloPanel">
    <div class="mublo-panel__dialog">
        <div class="mublo-panel__header">
            <span class="mublo-panel__title">Menu</span>
            <button class="mublo-panel__close" id="mubloPanelClose" aria-label="메뉴 닫기"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="mublo-panel__body">
            <!-- 검색 -->
<?= $this->component('frame/search', ['variant' => 'panel']) ?>
            <!-- GNB (세로) -->
            <nav class="mublo-panel__nav">
                <?= $this->menu($menus, $activeMenuCode) ?>
            </nav>
            <!-- 유틸리티 -->
<?= $this->component('frame/menu_utility', [
    'variant' => 'panel',
    'utilityMenus' => $utilityMenus ?? [],
    'viewer' => $viewer ?? [],
]) ?>
        </div>
    </div>
    <div class="mublo-panel__backdrop"></div>
</aside>
