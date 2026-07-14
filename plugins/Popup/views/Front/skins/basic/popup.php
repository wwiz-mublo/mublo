<?php
/**
 * Popup 스킨 — basic
 *
 * 메인 페이지에서만 출력됩니다. 마크업은 popup.js가 API 응답으로 동적 생성하고,
 * 이 뷰는 스킨 자산(CSS/JS)만 AssetManager에 등록한다.
 *
 * $this = ViewContext
 */

$skinUrl = '/serve/plugin/Popup/views/Front/skins/basic';
$this->assets->addCss($skinUrl . '/style.css');
$this->assets->addJs($skinUrl . '/popup.js');
