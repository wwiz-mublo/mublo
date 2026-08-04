<?php
/**
 * 번들 시작 킷 매니페스트
 *
 * 설치 시더(003_seed_starter_kits.php) · 대시보드 시작 킷 위젯(StarterKitWidget) ·
 * 설치 마법사(step3 시작 킷 선택)가 함께 쓰는 단일 진실이다.
 * 킷을 추가/제거하면 여기만 고치면 된다.
 *
 * - file: 이 디렉토리의 킷 JSON 파일명
 * - extensions: 이 킷의 블록이 필요로 하는 확장. 설치 마법사가 활성 목록에
 *   등록하면 첫 부팅의 reconcileDefaultExtensions() 가 마이그레이션+install 을 수행한다.
 *
 * 미리보기 이미지의 단일 진실은 킷 JSON 의 screenshot(data URI)이다(#39 원칙 1).
 * 정적 이미지 사본을 따로 두지 않는다 — 설치 마법사는 킷 JSON 에서 추출해 쓰고,
 * 대시보드 위젯은 시더가 구운 썸네일(block_kits.screenshot_path)을 쓴다.
 */
return [
    'company' => [
        'name' => '회사 홈페이지 시작 킷',
        'summary' => 'CSS 히어로 · 서비스 소개 · 공지 · FAQ/문의',
        'file' => 'company.json',
        'extensions' => [
            'packages' => ['Board'],
            'plugins' => ['Faq'],
        ],
    ],
    'community' => [
        'name' => '커뮤니티 시작 킷',
        'summary' => 'CSS 히어로 · 이원 스트림 · 우측 사이드바 위젯',
        'file' => 'community.json',
        'extensions' => [
            'packages' => ['Board'],
            'plugins' => ['Survey', 'Faq', 'VisitorStats'],
        ],
    ],
    'shop' => [
        'name' => '쇼핑몰 시작 킷',
        'summary' => 'CSS 히어로 · 신상품 자동 진열 · 구매후기',
        'file' => 'shop.json',
        'extensions' => [
            'packages' => ['Board', 'Shop'],
            'plugins' => [],
        ],
    ],
];
