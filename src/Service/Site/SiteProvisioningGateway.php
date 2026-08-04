<?php
declare(strict_types=1);

namespace Mublo\Service\Site;

use Mublo\Contract\Site\SiteProvisioningInterface;
use Mublo\Core\Result\Result;
use Mublo\Core\Theme\FrameOverride;
use Mublo\Entity\Block\BlockKit;
use Mublo\Service\Block\BlockKitApplier;
use Mublo\Service\Domain\DomainSettingsService;
use Mublo\Service\Menu\MenuService;

/**
 * SiteProvisioningGateway
 *
 * SiteProvisioningInterface(안정 API)의 코어 구현.
 *
 * 새 동작을 만들지 않는다 — 코어가 이미 갖고 관리자 화면이 쓰는 세 서비스에 위임한다.
 * 계약이 하는 일은 표면을 좁히는 것과, 확장 경로에 맞는 두 가지 판단을 고정하는 것뿐이다.
 *
 * 1. applyScreen 은 target.kind 를 검사한다 (page 킷은 BlockKitGatewayInterface 소관)
 * 2. applyScreen 은 allowScripts=false 로 고정한다 — 확장 경로에는 "이 사람이
 *    최고관리자인가"를 물을 사용자 문맥이 없으므로 가장 좁은 쪽으로 잠근다
 *
 * 설정 갱신은 DomainSettingsService 에 위임하므로 **부분 갱신(기존 값과 병합)** 과
 * DomainResolver 캐시 무효화가 그대로 적용된다. 하위 계층인
 * DomainRepository::update*Config() 는 버킷을 통째로 교체하므로 직접 쓰지 않는다.
 */
class SiteProvisioningGateway implements SiteProvisioningInterface
{
    public function __construct(
        private MenuService $menuService,
        private DomainSettingsService $domainSettings,
        private BlockKitApplier $kitApplier
    ) {
    }

    public function createMenuItem(int $domainId, array $data): Result
    {
        return $this->menuService->createItem($domainId, $data);
    }

    public function placeMenuItem(int $domainId, string $menuCode, ?string $parentCode = null): Result
    {
        return $this->menuService->addToTree($domainId, $menuCode, $parentCode);
    }

    public function ensureMenuItem(int $domainId, string $provisioningKey, array $data): Result
    {
        return $this->menuService->ensureItem($domainId, $provisioningKey, $data);
    }

    public function ensureMenuPlacement(int $domainId, string $menuCode, ?string $parentCode = null): Result
    {
        return $this->menuService->ensurePlacement($domainId, $menuCode, $parentCode);
    }

    public function ensurePageMenuPlacement(int $domainId, string $pageCode, ?string $parentCode = null): Result
    {
        return $this->menuService->ensurePageMenuPlacement($domainId, $pageCode, $parentCode);
    }

    public function setPackageFrameOverride(int $domainId, string $package, ?string $skin): Result
    {
        $package = strtolower(trim($package));
        if ($package === '') {
            return Result::failure('패키지 식별자는 필수입니다.');
        }

        // 최상위 얕은 병합이 frame_overrides 를 통째로 덮지 않도록,
        // 기존 theme_config 를 읽어 FrameOverride 규약으로 병합한 뒤 넘긴다.
        $themeConfig = $this->domainSettings->getThemeConfig($domainId);
        $merged = FrameOverride::apply($themeConfig, $package, $skin);

        return $this->domainSettings->updateThemeConfig($domainId, $merged);
    }

    public function updateCompanyConfig(int $domainId, array $values): Result
    {
        return $this->domainSettings->updateCompanyConfig($domainId, $values);
    }

    public function updateSiteConfig(int $domainId, array $values): Result
    {
        return $this->domainSettings->updateSiteConfig($domainId, $values);
    }

    public function updateThemeConfig(int $domainId, array $values): Result
    {
        return $this->domainSettings->updateThemeConfig($domainId, $values);
    }

    public function updateSeoConfig(int $domainId, array $values): Result
    {
        return $this->domainSettings->updateSeoConfig($domainId, $values);
    }

    public function applyScreen(int $domainId, array $kit, string $mode = self::MODE_REPLACE): Result
    {
        if (($kit['target']['kind'] ?? null) !== BlockKit::TARGET_SCREEN) {
            return Result::failure('screen 타깃 블록 킷이 아닙니다.');
        }

        if (!in_array($mode, [self::MODE_APPEND, self::MODE_REPLACE], true)) {
            return Result::failure("지원하지 않는 적용 모드입니다: {$mode}");
        }

        // applySiteSettings 는 넘기지 않는다 — kind=screen 이면 코어가 강제로 켠다
        // (메인화면은 슬롯 구성과 레이아웃 설정이 한 단위다).
        // allowScripts=false — 확장 경로에는 사용자 권한 문맥이 없다.
        $result = $this->kitApplier->apply($domainId, $kit, $mode, false, false);

        if (empty($result['ok'])) {
            return Result::failure(implode(' / ', $result['errors'] ?? ['블록 킷 적용에 실패했습니다.']));
        }

        return Result::success('메인화면 블록 킷을 적용했습니다.', [
            'summary' => $result['summary'] ?? [],
            'warnings' => $result['warnings'] ?? [],
        ]);
    }
}
