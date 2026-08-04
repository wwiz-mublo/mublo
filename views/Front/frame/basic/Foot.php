<?php
$seoConfig = $mublo['site']['seo'];
/**
 * Front Foot Partial (basic skin)
 *
 * JS 실행, </body></html>
 */
$customBody = trim($seoConfig['custom_body_script'] ?? '');
?>

<!-- AOS (Animate On Scroll) — 에디터 미리보기에서는 초기화하지 않는다.
     스크롤 전에는 섹션이 숨어 있어 편집 오버레이 좌표가 어긋난다(블록 에디터 설계 5.3). -->
<script src="/assets/lib/aos/2/dist/aos.js"></script>
<?php if (!is_editor_preview()): ?>
<script>AOS.init();</script>
<?php endif; ?>

<?php if ($customBody): ?>
<?= $customBody ?>
<?php endif; ?>

<!-- Slot JS: default (body 끝 — 슬롯 없는 addJs + 마커 없는 슬롯 폴백) -->
<!-- MUBLO_JS -->
</body>
</html>
