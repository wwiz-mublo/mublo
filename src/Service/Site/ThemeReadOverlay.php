<?php
declare(strict_types=1);

namespace Mublo\Service\Site;

use Mublo\Contract\Site\ThemeReadOverlayInterface;
use Mublo\Contract\Site\ThemeReadSnapshot;
use Mublo\Core\Result\Result;

/** 요청 메모리 안에서만 유지되는 사이트·테마 최종 설정 overlay. */
final class ThemeReadOverlay implements ThemeReadOverlayInterface
{
    private const MAX_DEPTH = 20;

    /** @var array<int, ThemeReadSnapshot> */
    private array $snapshots = [];

    public function replace(ThemeReadSnapshot $snapshot): Result
    {
        if ($snapshot->domainId <= 0) {
            return Result::failure('미리보기 도메인 ID가 올바르지 않습니다.');
        }
        foreach ([
            '사이트' => $snapshot->siteConfig,
            '회사' => $snapshot->companyConfig,
            'SEO' => $snapshot->seoConfig,
            '테마' => $snapshot->themeConfig,
        ] as $label => $config) {
            if (!$this->isPortable($config)) {
                return Result::failure($label . ' 미리보기 설정 형식이 올바르지 않습니다.');
            }
        }
        $this->snapshots[$snapshot->domainId] = $snapshot;
        return Result::success('사이트 테마 미리보기를 등록했습니다.');
    }

    public function snapshot(int $domainId): ?ThemeReadSnapshot
    {
        return $this->snapshots[$domainId] ?? null;
    }

    public function clear(): void
    {
        $this->snapshots = [];
    }

    private function isPortable(mixed $value, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!$this->isPortable($item, $depth + 1)) {
                return false;
            }
        }
        return true;
    }
}
