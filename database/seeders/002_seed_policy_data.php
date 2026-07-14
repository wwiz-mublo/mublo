<?php
/**
 * Mublo Core - 이용약관 시드 데이터
 *
 * 설치 시 기본 약관(이용약관, 개인정보처리방침) 등록
 * + 푸터 메뉴 아이템으로 등록
 *
 * 템플릿 파일:
 *   database/seeders/templates/terms.html   - 이용약관
 *   database/seeders/templates/privacy.html  - 개인정보처리방침
 *
 * 치환 변수 (약관 등록폼과 동일):
 *   {#회사명} - 회사/상호명
 *   {#대표자} - 대표자명
 *   {#사이트명} - 사이트 이름
 *   {#홈페이지} - 홈페이지 URL
 *   {#전화번호} - 대표 전화번호
 *   {#이메일} - 대표 이메일
 *   {#책임자} - 개인정보 보호책임자
 */

return function (PDO $pdo): void {
    $templateDir = __DIR__ . '/templates';

    $termsHtml = file_get_contents($templateDir . '/terms.html');
    $privacyHtml = file_get_contents($templateDir . '/privacy.html');

    if ($termsHtml === false || $privacyHtml === false) {
        throw new RuntimeException('약관 템플릿 파일을 읽을 수 없습니다: ' . $templateDir);
    }

    // 기본 약관 등록 (재시도 안전)
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO policies (domain_id, slug, policy_type, title, content, version, is_required, is_active, sort_order, show_in_register)
         VALUES (:domain_id, :slug, :policy_type, :title, :content, :version, :is_required, :is_active, :sort_order, :show_in_register)'
    );

    $stmt->execute([
        'domain_id' => 1,
        'slug' => 'terms',
        'policy_type' => 'terms',
        'title' => '이용약관',
        'content' => $termsHtml,
        'version' => '1.0',
        'is_required' => 1,
        'is_active' => 1,
        'sort_order' => 1,
        'show_in_register' => 1,
    ]);

    $stmt->execute([
        'domain_id' => 1,
        'slug' => 'privacy',
        'policy_type' => 'privacy',
        'title' => '개인정보처리방침',
        'content' => $privacyHtml,
        'version' => '1.0',
        'is_required' => 1,
        'is_active' => 1,
        'sort_order' => 2,
        'show_in_register' => 1,
    ]);

    // 시더는 PolicyService를 거치지 않으므로 현재 본문을 최초 불변 개정본으로 동기화한다.
    $pdo->exec(
        "INSERT IGNORE INTO policy_revisions
            (policy_id, domain_id, version, title, content, content_hash, published_at, created_at)
         SELECT policy_id, domain_id, version, title, content, SHA2(content, 256), created_at, created_at
         FROM policies WHERE domain_id = 1"
    );
    $pdo->exec(
        "UPDATE policies p
         INNER JOIN policy_revisions r ON r.policy_id = p.policy_id AND r.version = p.version
         SET p.current_revision_id = r.revision_id
         WHERE p.domain_id = 1 AND p.current_revision_id IS NULL"
    );

    // 푸터 메뉴 아이템 등록 (재시도 안전)
    $menuStmt = $pdo->prepare(
        'INSERT IGNORE INTO menu_items (domain_id, menu_code, label, url, icon, show_in_footer, footer_order, is_system, provider_type, provider_name, is_active)
         VALUES (:domain_id, :menu_code, :label, :url, NULL, :show_in_footer, :footer_order, :is_system, :provider_type, NULL, :is_active)'
    );

    $menuCodes = ['pT3kM8vR', 'qN5xJ2wF'];

    $menuStmt->execute([
        'domain_id' => 1,
        'menu_code' => $menuCodes[0],
        'label' => '이용약관',
        'url' => '/terms',
        'show_in_footer' => 1,
        'footer_order' => 2,
        'is_system' => 1,
        'provider_type' => 'core',
        'is_active' => 1,
    ]);

    $menuStmt->execute([
        'domain_id' => 1,
        'menu_code' => $menuCodes[1],
        'label' => '개인정보처리방침',
        'url' => '/privacy',
        'show_in_footer' => 1,
        'footer_order' => 3,
        'is_system' => 1,
        'provider_type' => 'core',
        'is_active' => 1,
    ]);

    // unique_codes 등록 (재시도 안전)
    $codeStmt = $pdo->prepare(
        'INSERT IGNORE INTO unique_codes (domain_id, code_type, code, reference_table)
         VALUES (:domain_id, :code_type, :code, :reference_table)'
    );

    foreach ($menuCodes as $code) {
        $codeStmt->execute([
            'domain_id' => 1,
            'code_type' => 'menu',
            'code' => $code,
            'reference_table' => 'menu_items',
        ]);
    }
};
