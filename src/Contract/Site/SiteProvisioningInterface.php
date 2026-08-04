<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

use Mublo\Core\Result\Result;

/**
 * SiteProvisioningInterface
 *
 * 사이트를 프로그래밍으로 구축하기 위한 안정 API.
 *
 * `ManagedSiteGatewayInterface` 가 사이트를 "만드는" 계약이라면 이것은 만든 사이트를
 * "세우는" 계약이다 — 메뉴를 붙이고, 도메인 설정을 채우고, 메인화면 킷을 앉힌다.
 * 설치 마법사·시작 킷 배포·테넌트 프로비저닝·사이트 복제·데모 시딩이 모두 같은 셋을 쓴다.
 *
 * 이 계약은 새 동작을 만들지 않는다. 코어가 이미 갖고 관리자 화면이 쓰는 동작을
 * 좁은 표면으로 노출할 뿐이다.
 *
 * ## 왜 계약이 필요한가
 *
 * - 메뉴: `BlockPageCreatedEvent` 가 menu_items 를 자동 등록하지만 menu_tree 배치는
 *   관리자 수동이다. 자동화 경로가 거기서 끊긴다.
 * - 설정: `CompanyInfoInterface` 는 읽기만 있다. 그리고 하위 계층인
 *   `DomainRepository::updateCompanyConfig()` 는 버킷을 통째로 교체하므로,
 *   확장이 직접 쓰면 필드 하나 넣으려다 나머지를 날린다. 이 계약은 **부분 갱신**을 보장한다.
 * - 메인화면: `BlockKitExporter::exportMainScreen()` 은 있는데 대칭인 apply 가
 *   계약에 없다. `BlockKitGatewayInterface::applyPage()` 는 page 타깃만 받는다.
 *
 * @see \Mublo\Contract\Site\ManagedSiteGatewayInterface 사이트 생성·확장 활성화
 * @see \Mublo\Contract\Site\CompanyInfoInterface 회사 정보 읽기
 * @see \Mublo\Contract\Block\BlockKitGatewayInterface 페이지 킷 적용·번들 킷 등록
 */
interface SiteProvisioningInterface
{
    public const MODE_APPEND = 'append';
    public const MODE_REPLACE = 'replace';

    /**
     * 메뉴 아이템 생성 (menu_items)
     *
     * `menu_code` 는 코어가 생성해 반환한다. 트리 배치는 별도로 placeMenuItem() 을 호출한다.
     *
     * **재시도가 있는 프로비저닝에는 ensureMenuItem() 을 쓴다.** 이 메서드는 부를 때마다
     * 새 아이템을 만든다.
     *
     * @param array $data label(필수) · url · icon · target · visibility · min_level ·
     *                    show_on_pc · show_on_mobile · show_in_utility · show_in_footer 등
     * @return Result 성공 data: {menu_code: string, item_id: int}
     */
    public function createMenuItem(int $domainId, array $data): Result;

    /**
     * 메뉴 트리에 배치 (menu_tree)
     *
     * depth · path_code(`부모>자식`) · path_name · sort_order 는 코어가 계산한다.
     *
     * **재시도가 있는 프로비저닝에는 ensureMenuPlacement() 를 쓴다.** 이 메서드는
     * 기존 배치를 확인하지 않고 항상 추가한다.
     *
     * @param string|null $parentCode null 이면 1차 메뉴
     */
    public function placeMenuItem(int $domainId, string $menuCode, ?string $parentCode = null): Result;

    /**
     * 프로비저닝 키로 메뉴 아이템을 멱등 보장
     *
     * 같은 키로 다시 부르면 기존 아이템을 반환하고 **라벨을 덮지 않는다** — 운영자가
     * 고친 이름을 재시도가 되돌리면 안 된다.
     *
     * 재조회는 기존 확장들과 같은 방식이다 — `(provider_type, provider_name)` 로
     * 그 확장이 만든 목록을 가져와 URL 로 고른다. 키는 `url` 이 비었을 때
     * `#{키}` 로 실린다. **전용 스키마를 두지 않는다.**
     *
     * @param array $data label(필수) · provider_type(필수: core|plugin|package) ·
     *                    provider_name(core 외 필수, 소문자로 정규화됨) · url 등
     * @return Result 성공 data: {menu_code: string, item_id: int, created: bool}
     */
    public function ensureMenuItem(int $domainId, string $provisioningKey, array $data): Result;

    /**
     * 트리 배치를 멱등 보장
     *
     * 같은 위치에 이미 있으면 성공(no-op)이다. **다른 위치의 같은 메뉴는 건드리지
     * 않는다** — 코어가 허용한 복수 배치이거나 운영자 편집일 수 있다. 메뉴 구조를
     * 바꾸는 것은 별도의 명시적 편집 작업이다.
     *
     * @param string|null $parentCode 부모 노드의 path_code (null 이면 1차 메뉴)
     * @return Result 성공 data: {node_id: int, path_code: string, created: bool}
     */
    public function ensureMenuPlacement(int $domainId, string $menuCode, ?string $parentCode = null): Result;

    /**
     * 블록 페이지의 자동 등록 메뉴를 트리에 멱등 배치
     *
     * `applyPage()` 는 `menu_code` 를 반환하지 않고, 자동 등록은 `BlockPageMenuSubscriber`
     * 안에서 일어나 반환값이 버려진다. 그래서 페이지 코드로 배치한다 — 구독자와 같은
     * 조회 경로(`provider=core/blockpage`, `url='/p/{pageCode}'`)로 아이템을 찾는다.
     *
     * @return Result 성공 data: {node_id: int, path_code: string, menu_code: string, created: bool}
     */
    public function ensurePageMenuPlacement(int $domainId, string $pageCode, ?string $parentCode = null): Result;

    /**
     * 패키지 프레임 오버라이드 설정 (theme_config)
     *
     * `updateThemeConfig()` 는 최상위 얕은 병합이라 `frame_overrides` 키를 통째로
     * 덮어 다른 패키지의 오버라이드를 지운다. 이 메서드는 기존 값을 읽어
     * `Core\Theme\FrameOverride::apply()` 로 병합한다.
     *
     * @param string $package 패키지 식별자 — 소문자 canonical name 으로 정규화된다
     * @param string|null $skin 스킨명. **null·빈 문자열이면 오버라이드 제거**(코어 프레임 복귀)
     */
    public function setPackageFrameOverride(int $domainId, string $package, ?string $skin): Result;

    /**
     * 회사 정보 부분 갱신 — 넘긴 키만 덮고 나머지는 보존한다.
     */
    public function updateCompanyConfig(int $domainId, array $values): Result;

    /**
     * 사이트 설정 부분 갱신 — 넘긴 키만 덮고 나머지는 보존한다.
     */
    public function updateSiteConfig(int $domainId, array $values): Result;

    /**
     * 테마 설정 부분 갱신 — 넘긴 키만 덮고 나머지는 보존한다.
     *
     * 패키지 프레임 오버라이드는 `Mublo\Core\Theme\FrameOverride::apply()` 로
     * 버킷을 만들어 넘긴다.
     */
    public function updateThemeConfig(int $domainId, array $values): Result;

    /**
     * SEO 설정 부분 갱신 — 넘긴 키만 덮고 나머지는 보존한다.
     */
    public function updateSeoConfig(int $domainId, array $values): Result;

    /**
     * 메인화면(screen) 타깃 블록 킷 적용
     *
     * - `target.kind` 가 'screen' 이어야 한다. page 타깃은
     *   `BlockKitGatewayInterface::applyPage()` 를 쓴다.
     * - 메인화면은 슬롯 구성과 레이아웃 설정이 한 단위이므로 코어가 킷의 `site_settings`
     *   를 같은 트랜잭션에서 `domain_config.site_config` 에 병합한다(되돌리기 스냅샷 포함).
     * - **직접 실행 JS 가 포함된 킷은 거부한다.** 확장 경로에는 사용자 권한 문맥이 없으므로
     *   계약은 가장 좁은 쪽으로 고정한다.
     *
     * @param array $kit 디코딩된 킷 JSON (mublo-starter-kit 형식)
     * @return Result 성공 data: {summary: array, warnings: string[]}
     */
    public function applyScreen(int $domainId, array $kit, string $mode = self::MODE_REPLACE): Result;
}
