<?php
/**
 * Mublo Core - 시작 킷 보관함 시드
 *
 * 번들 시작 킷(회사형/커뮤니티형/쇼핑몰형)을 블록 킷 보관함(block_kits)에 등록한다.
 * 관리자는 대시보드의 "시작 킷" 위젯 → 블록 킷 화면에서 미리보고 적용할 수 있다.
 *
 * BlockKitLibrary::store() 대신 직접 INSERT 하는 이유:
 * 설치 시점에는 확장 블록 타입이 레지스트리에 없어 dryRun 요약이 불완전하고,
 * 서비스 체인(Applier/Screenshot 등)을 설치기에서 조립할 수 없다. 번들 킷은
 * 저장소에서 검증을 거친 산출물이며, 적용 시점에 어차피 dryRun 이 다시 돈다.
 *
 * 멱등: (domain_id, kit_name, source_type='bundled') 존재 시 스킵.
 */
return function (PDO $pdo): void {
    $kitDir = __DIR__ . '/starter-kits';
    $manifest = require $kitDir . '/kits.php';
    $now = date('Y-m-d H:i:s');
    $domainId = 1;

    $exists = $pdo->prepare(
        "SELECT kit_id, screenshot_path FROM block_kits
         WHERE domain_id = :domain_id AND kit_name = :kit_name AND source_type = 'bundled' AND is_deleted = 0"
    );

    // 킷 JSON 에 임베드된 스크린샷(data URI)을 썸네일 파일로 굽는다.
    // 관리자 업로드와 같은 경로(BlockKitScreenshot — 검증 + GD 재인코딩)를 쓴다.
    $screenshot = new \Mublo\Service\Block\BlockKitScreenshot(
        new \Mublo\Infrastructure\Image\ImageProcessor()
    );
    $bakeScreenshot = function (int $kitId, array $kit) use ($pdo, $screenshot, $domainId): void {
        $path = $screenshot->store($kit['screenshot'] ?? null, $domainId, $kitId);
        if ($path !== null) {
            $stmt = $pdo->prepare('UPDATE block_kits SET screenshot_path = :path WHERE kit_id = :kit_id');
            $stmt->execute(['path' => $path, 'kit_id' => $kitId]);
        }
    };

    $insert = $pdo->prepare(
        "INSERT INTO block_kits
            (domain_id, kit_name, kit_description, kit_version, kit_author, kit_author_url,
             target_kind, target_position, target_menu_code, target_page_code,
             export_mode, contains_script, row_count, column_count,
             kit_json, screenshot_path, source_type, is_deleted, created_at, updated_at)
         VALUES
            (:domain_id, :kit_name, :kit_description, :kit_version, :kit_author, :kit_author_url,
             'screen', NULL, NULL, NULL,
             'distribution', 0, :row_count, :column_count,
             :kit_json, NULL, 'bundled', 0, :created_at, :updated_at)"
    );

    foreach ($manifest as $slug => $meta) {
        // 보관함 시딩은 부가 기능 — 킷 파일 하나가 손상돼도 설치를 막지 않고
        // 해당 킷만 건너뛴다 (같은 파일을 쓰는 Step 3 골격 시딩은 Installer 가 별도 폴백).
        try {
            $path = $kitDir . '/' . $meta['file'];
            $json = @file_get_contents($path);
            if ($json === false) {
                throw new RuntimeException("시작 킷 파일을 읽을 수 없습니다: {$path}");
            }

            $kit = json_decode($json, true);
            if (!is_array($kit) || ($kit['target']['kind'] ?? null) !== 'screen') {
                throw new RuntimeException("시작 킷 형식이 올바르지 않습니다: {$meta['file']}");
            }
        } catch (\Throwable $e) {
            error_log("[Mublo] 시작 킷 보관함 시드 건너뜀 ({$slug}): " . $e->getMessage());
            continue;
        }

        $exists->execute([
            'domain_id' => $domainId,
            'kit_name' => $kit['name'] ?? $meta['name'],
        ]);
        if ($row = $exists->fetch()) {
            // 백필: 스크린샷 없이 등록된 기존 번들 킷(구버전 시더)에 스크린샷 보강
            if (empty($row['screenshot_path']) && !empty($kit['screenshot'])) {
                $stmt = $pdo->prepare('UPDATE block_kits SET kit_json = :kit_json, updated_at = :now WHERE kit_id = :kit_id');
                $stmt->execute(['kit_json' => $json, 'now' => $now, 'kit_id' => $row['kit_id']]);
                $bakeScreenshot((int) $row['kit_id'], $kit);
            }
            continue;
        }

        $rows = is_array($kit['rows'] ?? null) ? $kit['rows'] : [];
        $columnCount = 0;
        foreach ($rows as $row) {
            $columnCount += count($row['columns'] ?? []);
        }

        $insert->execute([
            'domain_id' => $domainId,
            'kit_name' => mb_substr((string) ($kit['name'] ?? $meta['name']), 0, 100),
            'kit_description' => mb_substr((string) ($kit['description'] ?? ''), 0, 500),
            'kit_version' => mb_substr((string) ($kit['version'] ?? '1.0.0'), 0, 20),
            'kit_author' => mb_substr((string) ($kit['author'] ?? ''), 0, 100),
            'kit_author_url' => mb_substr((string) ($kit['author_url'] ?? ''), 0, 255),
            'row_count' => count($rows),
            'column_count' => $columnCount,
            'kit_json' => $json,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $bakeScreenshot((int) $pdo->lastInsertId(), $kit);
    }
};
