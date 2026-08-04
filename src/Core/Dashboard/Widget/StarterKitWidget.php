<?php
declare(strict_types=1);

namespace Mublo\Core\Dashboard\Widget;

use Closure;
use Mublo\Core\Dashboard\AbstractDashboardWidget;
use Mublo\Infrastructure\Database\Database;

/**
 * 시작 킷 위젯
 *
 * 번들 시작 킷(회사형/커뮤니티형/쇼핑몰형)을 카드로 보여주고
 * 블록 킷 화면으로 안내한다 — 설치 직후 "메인을 어떻게 채우지?"의 답.
 *
 * 킷 목록의 진실은 database/seeders/starter-kits/kits.php 하나다
 * (설치 시더와 공유). 매니페스트가 없거나 비어 있으면 위젯은 조용히 비운다.
 *
 * 썸네일은 시더가 구운 블록 킷 보관함 썸네일(block_kits.screenshot_path)을
 * 쓴다 — 킷 JSON 의 screenshot 이 단일 진실이고 그 파일은 산출물이다(#39
 * 원칙 1). 정적 이미지 사본을 따로 관리하지 않는다. 썸네일이 아직 없으면
 * (과거 굽기 실패 등) 킷 JSON 에서 data URI 를 직접 꺼내 보여준다.
 */
class StarterKitWidget extends AbstractDashboardWidget
{
    /** @param Closure(): ?int $domainIdResolver boot 시점에는 Context 미존재 → 지연 해석 */
    public function __construct(
        private Database $db,
        private Closure $domainIdResolver
    ) {
    }

    public function id(): string
    {
        return 'core.starter_kits';
    }

    public function title(): string
    {
        return '시작 킷';
    }

    public function render(): string
    {
        $kits = $this->manifest();
        if ($kits === []) {
            return '<p class="text-muted small mb-0">등록된 시작 킷이 없습니다.</p>';
        }

        $baked = $this->bakedScreenshots();

        $cards = '';
        foreach ($kits as $meta) {
            $name = htmlspecialchars((string) ($meta['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $summary = htmlspecialchars((string) ($meta['summary'] ?? ''), ENT_QUOTES, 'UTF-8');
            $src = $this->thumbnailSrc($meta, $baked);

            $thumb = $src !== ''
                ? '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . $name . '" class="rounded border" style="width:100%;height:90px;object-fit:cover;object-position:top;">'
                : '';

            $cards .= '<div class="col-4">'
                . $thumb
                . '<div class="fw-semibold small mt-2">' . $name . '</div>'
                . '<div class="text-muted" style="font-size:12px;">' . $summary . '</div>'
                . '</div>';
        }

        return '<div class="row g-3 mb-3">' . $cards . '</div>'
            . '<p class="text-muted small mb-2">메인화면 골격을 킷 하나로 적용하고, 게시판을 만들어 연결하면 됩니다.</p>'
            . '<a href="/admin/block-kit" class="btn btn-sm btn-outline-primary">'
            . '<i class="bi bi-box-seam"></i> 블록 킷에서 미리보고 적용하기</a>';
    }

    /**
     * @return array<string, array{name: string, summary: string, file: string}>
     */
    private function manifest(): array
    {
        $path = MUBLO_ROOT_PATH . '/database/seeders/starter-kits/kits.php';
        if (!is_file($path)) {
            return [];
        }

        $kits = require $path;

        return is_array($kits) ? $kits : [];
    }

    /**
     * 시더가 구운 썸네일 경로 맵 [kit_name => screenshot_path]
     *
     * 위젯은 부가 UI 다 — DB 문제로 대시보드가 죽으면 안 되므로 실패는 빈 맵.
     *
     * @return array<string, string>
     */
    private function bakedScreenshots(): array
    {
        $domainId = ($this->domainIdResolver)();
        if ($domainId === null) {
            return [];
        }

        try {
            $rows = $this->db->select(
                "SELECT kit_name, screenshot_path FROM block_kits
                 WHERE domain_id = ? AND source_type = 'bundled' AND is_deleted = 0",
                [$domainId]
            );
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $path = (string) ($row['screenshot_path'] ?? '');
            if ($path !== '') {
                $map[(string) $row['kit_name']] = $path;
            }
        }

        return $map;
    }

    /**
     * 카드 썸네일 src — 구운 파일 우선, 없으면 킷 JSON 의 data URI 폴백.
     */
    private function thumbnailSrc(array $meta, array $baked): string
    {
        $name = (string) ($meta['name'] ?? '');
        if ($name !== '' && isset($baked[$name])) {
            return $baked[$name];
        }

        $file = (string) ($meta['file'] ?? '');
        if ($file === '') {
            return '';
        }

        $kit = json_decode((string) @file_get_contents(MUBLO_ROOT_PATH . '/database/seeders/starter-kits/' . $file), true);
        $screenshot = is_array($kit) ? ($kit['screenshot'] ?? '') : '';

        return is_string($screenshot) && str_starts_with($screenshot, 'data:image/') ? $screenshot : '';
    }
}
