<?php
/**
 * 테마 모드 스위치 컴포넌트 (라이트/시스템/다크)
 *
 * 파일 스킨(frame/{skin}/Footer.php)과 프레임 템플릿 슬롯({{theme_switch}})이 공유한다.
 * 마크업 + 동작 JS + localStorage 로직이 한 몸 — 분리하면 스위치가 동작하지 않는다.
 * JS는 세그먼트형(.mublo-theme-switch)/단일형(.mublo-theme-toggle) 어느 마크업이든
 * 함께 동작하도록 짜여 있어, 위젯 UI 교체 시에도 이 컴포넌트만 갱신하면 된다.
 */
?>
            <!-- 테마 모드: 세그먼트형 스위치 (스킨 자체 소유) -->
            <div class="mublo-theme-switch" role="group" aria-label="테마 모드">
                <button type="button" class="mublo-theme-switch__btn" data-theme-value="light" aria-label="라이트" aria-pressed="false">
                    <svg fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="5"></circle><path d="M12 1v2"></path><path d="M12 21v2"></path><path d="M4.22 4.22l1.42 1.42"></path><path d="M18.36 18.36l1.42 1.42"></path><path d="M1 12h2"></path><path d="M21 12h2"></path><path d="M4.22 19.78l1.42-1.42"></path><path d="M18.36 5.64l1.42-1.42"></path></svg>
                    <span class="visually-hidden">Light</span>
                </button>
                <button type="button" class="mublo-theme-switch__btn" data-theme-value="auto" aria-label="시스템" aria-pressed="false">
                    <svg fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><path d="M8 21h8"></path><path d="M12 17v4"></path></svg>
                    <span class="visually-hidden">Auto</span>
                </button>
                <button type="button" class="mublo-theme-switch__btn" data-theme-value="dark" aria-label="다크" aria-pressed="false">
                    <svg fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path></svg>
                    <span class="visually-hidden">Dark</span>
                </button>
            </div>
<script>
// 테마 모드 컨트롤 — 폼 무관(.mublo-theme-switch 세그먼트형 / .mublo-theme-toggle 단일형
// 어느 쪽을 끼워도 동작). 값: light | auto(시스템추종) | dark. 훅 [data-theme-value].
// 초기 테마 적용은 Head.php 무플래시 스크립트, 여기선 선택/표시 동기화만.
(function() {
    var comps = document.querySelectorAll('.mublo-theme-switch, .mublo-theme-toggle');
    if (!comps.length) return;
    var root = document.documentElement;
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    function stored() { try { return localStorage.getItem('mublo-theme') || 'auto'; } catch (_) { return 'auto'; } }
    function resolve(v) { return (v === 'dark' || (v !== 'light' && mq.matches)) ? 'dark' : 'light'; }
    function applyResolved(v) {
        if (resolve(v) === 'dark') { root.classList.add('dark'); root.setAttribute('data-theme', 'dark'); }
        else { root.classList.remove('dark'); root.removeAttribute('data-theme'); }
    }
    function sync() {
        var sel = stored();                                  // 선택 모드(light/auto/dark)
        var next = resolve(sel) === 'dark' ? 'light' : 'dark';
        comps.forEach(function(comp) {
            var isToggle = comp.classList.contains('mublo-theme-toggle');
            comp.querySelectorAll('[data-theme-value]').forEach(function(b) {
                var v = b.getAttribute('data-theme-value');
                if (isToggle) {                              // 단일형: 전환대상만 노출(light↔dark)
                    b.classList.toggle('is-hidden', v !== next);
                    b.setAttribute('aria-pressed', 'false');
                } else {                                     // 세그먼트형: 선택 모드 활성표시
                    var on = v === sel;
                    b.classList.toggle('is-active', on);
                    b.setAttribute('aria-pressed', on ? 'true' : 'false');
                }
            });
        });
    }
    comps.forEach(function(comp) {
        comp.querySelectorAll('[data-theme-value]').forEach(function(b) {
            b.addEventListener('click', function() {
                var v = b.getAttribute('data-theme-value');
                try { localStorage.setItem('mublo-theme', v); } catch (_) {}
                applyResolved(v);
                sync();
            });
        });
    });
    mq.addEventListener('change', function() {               // 시스템 변경 시 auto면 추종
        if (stored() === 'auto') { applyResolved('auto'); sync(); }
    });
    sync();
})();
</script>
