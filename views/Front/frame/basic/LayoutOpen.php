<?php
/**
 * Front Layout Open (basic skin)
 *
 * 레이아웃 외부 래퍼 시작
 *
 * 사이드바/콘텐츠 내부 배치는 FrontViewRenderer가 제어.
 * LayoutOpen/Close는 <main>, 컨테이너, flex 래퍼만 담당.
 *
 * @var int $layoutType 레이아웃 타입 (1:전체, 2:좌측사이드바, 3:우측사이드바, 4:3단)
 * @var int $useFullpage 넓이 모드 (0:최대넓이, 1:와이드, 2:사용자지정)
 * @var int $customWidth 사용자 지정 넓이 (px, useFullpage=2일 때)
 */

$layoutType = (int) ($layoutType ?? 1);
$useFullpage = (int) ($useFullpage ?? 0);
$customWidth = (int) ($customWidth ?? 0);

$containerClass = 'mublo-content';
$containerStyle = '';
if ($useFullpage === 1) {
    $containerClass = 'mublo-content--wide';
} elseif ($useFullpage === 2 && $customWidth > 0) {
    $containerStyle = ' style="max-width:' . $customWidth . 'px"';
}
?>
<main class="mublo-main">
    <div class="<?= $containerClass ?>"<?= $containerStyle ?>>
        <div class="mublo-layout">
