<?php
/**
 * 테마 설정 섹션 — 스킨 타일 UI
 *
 * Admin 프레임: views/Admin/frame/{스킨명}
 * Front 프레임: views/Front/frame/{스킨명}
 * Front 콘텐츠: views/Front/{Group}/{스킨명}
 *
 * 선택값은 페이지 JS 가 themeConfig 로 채우므로 selected 마크는 하지 않고,
 * "커스텀" 배지는 값 주입 이후·변경 시 스크립트로 동기화한다.
 *
 * @var array $skinOptions 컴포넌트별 스킨 목록
 */

// 스킨 select 옵션 출력 헬퍼
$renderSkinOptions = function(string $component) use ($skinOptions): string {
    $skins = $skinOptions[$component] ?? ['basic'];
    $html = '';
    foreach ($skins as $skin) {
        $html .= '<option value="' . htmlspecialchars($skin) . '">' . htmlspecialchars($skin) . '</option>';
    }
    return $html;
};

// 타일 렌더 헬퍼
$renderSkinTile = function(string $component, string $label, string $icon, string $path) use ($renderSkinOptions): string {
    $id = 'theme_' . $component;
    return '
                    <label class="theme-skin-tile" for="' . $id . '" title="' . htmlspecialchars($path) . '">
                        <span class="theme-skin-tile__head">
                            <span class="theme-skin-tile__icon"><i class="bi ' . htmlspecialchars($icon) . '"></i></span>
                            <span>
                                <span class="theme-skin-tile__title">' . htmlspecialchars($label) . '</span>
                                <span class="theme-skin-tile__path">' . htmlspecialchars($path) . '</span>
                            </span>
                            <span class="theme-skin-badge">커스텀</span>
                        </span>
                        <select name="formData[theme][' . $component . ']" id="' . $id . '" class="form-select form-select-sm theme-skin-select">
                            ' . $renderSkinOptions($component) . '
                        </select>
                    </label>';
};
?>
<style>
/* 색은 토큰으로만 — 라이트는 :root 디폴트, 다크는 [data-bs-theme="dark"] 오버라이드 */
:root {
    --theme-skin-accent: #3b6fd4;
    --theme-skin-card-bg: linear-gradient(180deg, #f7faff 0%, #ffffff 70%);
    --theme-skin-card-border: #d8e3f8;
    --theme-skin-tile-bg: #fff;
    --theme-skin-tile-border: #e4e9f2;
    --theme-skin-tile-hover-border: #9db8e8;
    --theme-skin-tile-shadow: rgba(30, 64, 175, 0.1);
    --theme-skin-icon-bg: #eef3fd;
    --theme-skin-badge-bg: #e7effd;
    --theme-skin-custom-bg: linear-gradient(180deg, #f5f9ff, #fff);
    --theme-skin-on-accent: #fff;
}
[data-bs-theme="dark"] {
    --theme-skin-accent: #60a5fa;
    --theme-skin-card-bg: linear-gradient(180deg, #1f2937 0%, var(--surface-bg, #27272a) 70%);
    --theme-skin-card-border: #37476b;
    --theme-skin-tile-bg: #1f1f23;
    --theme-skin-tile-border: #3f3f46;
    --theme-skin-tile-hover-border: #4b6ea8;
    --theme-skin-tile-shadow: rgba(0, 0, 0, 0.45);
    --theme-skin-icon-bg: #26303f;
    --theme-skin-badge-bg: #23334a;
    --theme-skin-custom-bg: linear-gradient(180deg, #1d2635, #1f1f23);
    --theme-skin-on-accent: #0b1220;
}
.theme-skin-card { border: 1px solid var(--theme-skin-card-border); background: var(--theme-skin-card-bg); }
.theme-skin-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.theme-skin-tile {
    display: block; margin: 0; padding: 12px; cursor: pointer;
    background: var(--theme-skin-tile-bg); border: 1px solid var(--theme-skin-tile-border); border-radius: 12px;
    transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
}
.theme-skin-tile:hover { border-color: var(--theme-skin-tile-hover-border); box-shadow: 0 4px 14px var(--theme-skin-tile-shadow); transform: translateY(-1px); }
.theme-skin-tile__head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.theme-skin-tile__icon {
    width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--theme-skin-icon-bg); color: var(--theme-skin-accent); font-size: 0.9rem;
    transition: background 0.15s, color 0.15s;
}
.theme-skin-tile__title { display: block; font-weight: 600; font-size: 0.85rem; color: var(--bs-body-color); line-height: 1.25; }
.theme-skin-tile__path { display: block; font-size: 0.68rem; color: var(--bs-secondary-color); }
.theme-skin-badge {
    margin-left: auto; display: none; padding: 2px 8px; border-radius: 999px;
    font-size: 0.65rem; font-weight: 700; color: var(--theme-skin-accent); background: var(--theme-skin-badge-bg); white-space: nowrap;
}
.theme-skin-accent-text { color: var(--theme-skin-accent); }
.theme-skin-tile.is-custom { border-color: var(--theme-skin-accent); background: var(--theme-skin-custom-bg); }
.theme-skin-tile.is-custom .theme-skin-tile__icon { background: var(--theme-skin-accent); color: var(--theme-skin-on-accent); }
.theme-skin-tile.is-custom .theme-skin-badge { display: inline-block; }
</style>

<!-- 프레임 스킨 (관리자 / 프론트) -->
<div class="card mb-4 theme-skin-card">
    <div class="card-hero">
        <i class="bi bi-window-desktop text-pastel-blue"></i>
        <span>프레임 스킨</span>
    </div>
    <div class="card-body">
        <div class="form-text mb-3">사이트 전체 셸(헤더·푸터·레이아웃)을 결정하는 스킨입니다. <code>basic</code> 이외의 스킨을 쓰는 항목에는 <span class="fw-semibold theme-skin-accent-text">커스텀</span> 배지가 표시됩니다.</div>
        <div class="theme-skin-grid">
<?= $renderSkinTile('admin', '관리자 프레임', 'bi-gear', 'views/Admin/frame') ?>
<?= $renderSkinTile('frame', '프론트 프레임', 'bi-layout-text-window', 'views/Front/frame') ?>
        </div>
    </div>
</div>

<!-- Front 콘텐츠 스킨 설정 -->
<div class="card mb-4 theme-skin-card">
    <div class="card-hero">
        <i class="bi bi-palette text-pastel-purple"></i>
        <span>프론트 콘텐츠 스킨</span>
    </div>
    <div class="card-body">
        <div class="form-text mb-3">화면 영역별 콘텐츠 스킨입니다. 폴더를 추가하면 선택지에 자동 노출됩니다.</div>
        <div class="theme-skin-grid">
<?= $renderSkinTile('index', '메인 (Index)', 'bi-house', 'views/Front/Index') ?>
<?= $renderSkinTile('member', '회원 (Member)', 'bi-people', 'views/Front/Member') ?>
<?= $renderSkinTile('auth', '로그인/가입 (Auth)', 'bi-shield-lock', 'views/Front/Auth') ?>
<?= $renderSkinTile('policy', '약관 (Policy)', 'bi-file-earmark-text', 'views/Front/Policy') ?>
<?= $renderSkinTile('mypage', '마이페이지 (Mypage)', 'bi-person-badge', 'views/Front/Mypage') ?>
<?= $renderSkinTile('search', '검색 (Search)', 'bi-search', 'views/Front/Search') ?>
        </div>
    </div>
</div>

<script>
(function () {
    // "커스텀" 배지 동기화 — 페이지 JS 가 themeConfig 값을 주입한 뒤에도 잡히도록
    // 초기 몇 차례 지연 동기화 + change 이벤트를 함께 사용한다.
    function syncTile(sel) {
        var tile = sel.closest('.theme-skin-tile');
        if (tile) tile.classList.toggle('is-custom', sel.value !== 'basic' && sel.value !== '');
    }
    function syncAll() {
        document.querySelectorAll('.theme-skin-select').forEach(syncTile);
    }
    document.querySelectorAll('.theme-skin-select').forEach(function (sel) {
        sel.addEventListener('change', function () { syncTile(sel); });
    });
    document.addEventListener('DOMContentLoaded', syncAll);
    window.addEventListener('load', syncAll);
    setTimeout(syncAll, 300);
    setTimeout(syncAll, 1000);
})();
</script>
