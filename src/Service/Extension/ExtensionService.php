<?php
declare(strict_types=1);
namespace Mublo\Service\Extension;

use Mublo\Core\Result\Result;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Core\App\Application;
use Mublo\Core\App\Router;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Extension\NestedPlugin;

/**
 * ExtensionService
 *
 * 플러그인/패키지 확장 관리 서비스
 *
 * 책임:
 * - manifest.json 스캔 및 파싱
 * - 플러그인/패키지 메타데이터 제공
 * - 도메인별 활성화 상태 관리
 */
class ExtensionService
{
    private DomainRepository $domainRepository;
    private DomainCache $domainCache;
    private Database $db;
    private ExtensionCompatibility $compatibility;
    private ?CacheInterface $cache;
    private string $pluginPath;
    private string $packagePath;

    /** reconcile 설치 실패 후 다음 재시도까지의 backoff(초) — 매 요청 재시도·로그 스팸 방지 */
    private const RECONCILE_BACKOFF_TTL = 300;

    /**
     * 요청 스코프 도메인 by-id 메모 캐시 [domainId => ?Domain].
     *
     * getExtensionConfig()·applySuperOnlyPlugins()가 한 요청에서 getEnabledPlugins/Packages
     * (Application·Router에서 각 2회) + super_only + reconcile 경로로 도메인을 by-id로 8회가량
     * 재조회했다. ExtensionService는 컨테이너 오토와이어 싱글톤(요청당 1개)이라 인스턴스 캐시가
     * 요청 스코프로 동작한다. 쓰기(saveExtensionConfig/installDefaultExtension) 시 해당 항목을 무효화한다.
     *
     * @var array<int, ?object>
     */
    private array $domainByIdCache = [];

    public function __construct(
        DomainRepository $domainRepository,
        DomainCache $domainCache,
        Database $db,
        ExtensionCompatibility $compatibility,
        ?CacheInterface $cache = null
    ) {
        $this->domainRepository = $domainRepository;
        $this->domainCache = $domainCache;
        $this->db = $db;
        $this->compatibility = $compatibility;
        $this->cache = $cache;

        // 경로 설정 (plugins/, packages/)
        $this->pluginPath = MUBLO_PLUGIN_PATH;
        $this->packagePath = MUBLO_PACKAGE_PATH;
    }

    /**
     * 모든 플러그인 manifest 조회
     *
     * 독립 플러그인(plugins/{Name})과 패키지 종속 플러그인
     * (packages/{Pkg}/Plugins/{Name}, 키 "{Pkg}/{Name}")을 함께 반환한다.
     *
     * @return array<string, array> ['PluginName' => manifest data, ...]
     */
    public function getPluginManifests(): array
    {
        return $this->scanManifests($this->pluginPath, 'plugin')
            + $this->scanNestedPluginManifests();
    }

    /**
     * 패키지 종속 플러그인 manifest 스캔
     *
     * 코어는 패키지 내부를 스스로 읽지 않는다 — Provider 가
     * PluginHostInterface 를 구현한 패키지에게 목록을 묻고(discover),
     * 패키지가 답한 위치의 manifest 만 읽는다. 부모 패키지 의존은
     * requires 에 자동 주입되므로 플러그인 manifest 에 적을 필요가 없다.
     *
     * @return array<string, array>
     */
    private function scanNestedPluginManifests(): array
    {
        $manifests = [];

        foreach (glob($this->packagePath . '/*', GLOB_ONLYDIR) ?: [] as $packageDir) {
            $package = basename($packageDir);

            foreach (NestedPlugin::discover($package) as $pluginName => $info) {
                $key = $package . '/' . $pluginName;
                $manifest = $this->readManifest($info['dir'], $key, 'plugin');
                if ($manifest === null) {
                    continue;
                }

                // manifest 의 parent 선언은 배포물의 자기 기술 — 실제 위치(진실)와
                // 다르면 엉뚱한 패키지에 풀어넣은 배포물이므로 조용히 오동작하는
                // 대신 로드를 거부한다.
                if (isset($manifest['parent']) && $manifest['parent'] !== $package) {
                    error_log(
                        "[EXTENSION] 종속 플러그인 '{$key}' 의 manifest parent('{$manifest['parent']}')가"
                        . " 실제 위치('{$package}')와 다릅니다 — 설치 위치를 확인하세요. 로드하지 않습니다."
                    );
                    continue;
                }

                $manifest['parent'] = $package;
                // 종속은 구조가 진실 — 활성화 검증(requires)에도 자동 반영한다.
                $manifest['requires']["package:{$package}"] ??= '*';

                $manifests[$key] = $manifest;
            }
        }

        return $manifests;
    }

    /**
     * 모든 패키지 manifest 조회
     *
     * @return array<string, array> ['PackageName' => manifest data, ...]
     */
    public function getPackageManifests(): array
    {
        return $this->scanManifests($this->packagePath, 'package');
    }

    /**
     * 모든 확장(플러그인+패키지) manifest 조회
     *
     * @return array ['plugins' => [...], 'packages' => [...]]
     */
    public function getAllManifests(): array
    {
        return [
            'plugins' => $this->getPluginManifests(),
            'packages' => $this->getPackageManifests(),
        ];
    }

    /**
     * 도메인의 활성화된 플러그인 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return array 활성화된 플러그인 이름 배열
     */
    public function getEnabledPlugins(int $domainId): array
    {
        $config = $this->getExtensionConfig($domainId);
        $plugins = $config['plugins'] ?? [];

        // mandatory는 "끌 수 없음(잠금)"만 의미한다 — 설치/활성을 강제하지 않는다.
        // 활성 여부는 영속 목록(packages/plugins)이 진실. (잠금은 저장/표시 단계에서 처리)

        // "super_only" 플러그인: SUPER 도메인에서 활성이면 하위에서도 강제 활성
        $this->applySuperOnlyPlugins($domainId, $plugins);

        // 패키지 종속 플러그인: 부모 패키지가 비활성이면 함께 잠든다.
        // 활성화 검증(requires)이 정상 경로를 막지만, 검증을 우회한 설정
        // (DB 직접 수정 등)에서도 구조적 종속이 지켜지도록 런타임에서도 거른다.
        $packages = $config['packages'] ?? [];
        $plugins = array_values(array_filter($plugins, function ($name) use ($packages) {
            $parent = is_string($name) ? NestedPlugin::parentPackage($name) : null;
            return $parent === null || in_array($parent, $packages, true);
        }));

        return $plugins;
    }

    /**
     * super_only 플러그인 강제 활성화
     *
     * SUPER 도메인(domain_group의 첫 세그먼트)에서 해당 플러그인이 활성이면
     * 하위 도메인에서도 자동 활성화한다.
     */
    private function applySuperOnlyPlugins(int $domainId, array &$plugins): void
    {
        $superOnlyPlugins = [];
        foreach ($this->getPluginManifests() as $name => $manifest) {
            if (!empty($manifest['super_only']) && !in_array($name, $plugins)) {
                $superOnlyPlugins[] = $name;
            }
        }

        if (empty($superOnlyPlugins)) {
            return;
        }

        // SUPER 도메인 ID 결정 (domain_group의 첫 세그먼트)
        $domain = $this->resolveDomain($domainId);
        if (!$domain) {
            return;
        }

        $group = $domain->getDomainGroup() ?? '';
        $rootId = (int) explode('/', $group)[0];

        if ($rootId <= 0 || $rootId === $domainId) {
            // 자기 자신이 SUPER 면 자기 config에 있는지만 확인 (이미 $plugins에 포함)
            return;
        }

        // SUPER 도메인의 활성 플러그인 조회
        $rootConfig = $this->getExtensionConfig($rootId);
        $rootPlugins = $rootConfig['plugins'] ?? [];

        foreach ($superOnlyPlugins as $name) {
            if (in_array($name, $rootPlugins)) {
                $plugins[] = $name;
            }
        }
    }

    /**
     * 도메인의 활성화된 패키지 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return array 활성화된 패키지 이름 배열
     */
    public function getEnabledPackages(int $domainId): array
    {
        $config = $this->getExtensionConfig($domainId);
        $packages = $config['packages'] ?? [];

        // mandatory는 "끌 수 없음(잠금)"만 의미한다 — 설치/활성을 강제하지 않는다.
        // 활성 여부는 영속 packages가 진실. (잠금은 저장/표시 단계에서 처리)

        return $packages;
    }

    /**
     * 도메인의 extension_config 조회
     *
     * @param int $domainId 도메인 ID
     * @return array
     */
    public function getExtensionConfig(int $domainId): array
    {
        $domain = $this->resolveDomain($domainId);
        return $domain
            ? array_merge($this->getDefaultExtensionConfig(), $domain->getExtensionConfig())
            : $this->getDefaultExtensionConfig();
    }

    /**
     * 도메인 by-id 조회를 요청 내 1회로 메모이즈한다.
     * (config 조회·super_only SUPER 판정 등 여러 경로가 이 헬퍼를 공유해 중복 쿼리를 제거)
     */
    private function resolveDomain(int $domainId): ?object
    {
        if (array_key_exists($domainId, $this->domainByIdCache)) {
            return $this->domainByIdCache[$domainId];
        }
        return $this->domainByIdCache[$domainId] = $this->domainRepository->find($domainId);
    }

    /**
     * 기본 extension_config
     *
     * @return array
     */
    public function getDefaultExtensionConfig(): array
    {
        return [
            'plugins' => [],
            'packages' => [],
            'installed' => [
                'plugins' => [],
                'packages' => [],
            ],
        ];
    }

    /**
     * 도메인의 확장 설정 저장
     *
     * container + context 전달 시 install/uninstall 라이프사이클 자동 실행
     *
     * @param int $domainId 도메인 ID
     * @param array $config 설정 배열 ['plugins' => [...], 'packages' => [...]]
     * @param DependencyContainer|null $container 라이프사이클 실행용 (null이면 생략)
     * @param Context|null $context 라이프사이클 실행용 (null이면 생략)
     * @return Result
     */
    public function saveExtensionConfig(
        int $domainId,
        array $config,
        ?DependencyContainer $container = null,
        ?Context $context = null
    ): Result {
        // 유효성 검증
        // mandatory/super_only 보정 전에는 구조·manifest·호환성만 확인한다.
        // 라우트는 아래에서 실제 저장될 최종 조합을 기준으로 한 번만 검증한다.
        $validated = $this->validateExtensionConfig($config, false);
        if (!$validated['valid']) {
            return Result::failure($validated['message']);
        }

        // 기존 config 조회 (diff 비교용)
        $oldConfig = $this->getExtensionConfig($domainId);

        // 정규화
        $sanitized = $this->sanitizeExtensionConfig($config);
        $this->stripManagedSuperOnlyPluginsFromChildDomain($domainId, $sanitized);

        // installed 상태 유지 (기존 값에서 가져옴)
        $sanitized['installed'] = $oldConfig['installed'] ?? ['plugins' => [], 'packages' => []];

        // mandatory 잠금: 현재 활성인 mandatory 확장을 끄는 변경은 차단(되살림).
        // mandatory는 "끌 수 없음"만 의미 — 체크박스 disabled로 폼에서 빠져도 여기서 되살린다.
        $this->enforceMandatoryLock($oldConfig, $sanitized);

        $validated = $this->validateExtensionConfig($sanitized);
        if (!$validated['valid']) {
            return Result::failure($validated['message']);
        }

        // 라이프사이클 실행 (container + context가 있을 때만)
        if ($container && $context) {
            $sanitized = $this->executeLifecycle($domainId, $oldConfig, $sanitized, $container, $context);
        }

        // 저장
        $result = $this->domainRepository->updateExtensionConfig($domainId, $sanitized);

        // 메모 캐시 무효화 (같은 요청 내 이후 조회가 방금 저장한 값을 보도록)
        unset($this->domainByIdCache[$domainId]);

        if ($result) {
            $this->invalidateCaches($domainId);
            return Result::success('확장 설정이 저장되었습니다.');
        }
        return Result::failure('확장 설정 저장에 실패했습니다.');
    }

    /**
     * mandatory 잠금 강제
     *
     * 현재 활성(old)인 mandatory 확장이 새 설정에서 빠지면 되살린다.
     * mandatory는 "끌 수 없음(잠금)"만 의미하므로, 켜져 있던 것을 끄는 변경을 차단한다.
     * (설치/활성 강제는 하지 않는다 — 애초에 활성이 아니었으면 건드리지 않음.)
     */
    private function enforceMandatoryLock(array $oldConfig, array &$newConfig): void
    {
        $targets = [
            'plugins'  => $this->getPluginManifests(),
            'packages' => $this->getPackageManifests(),
        ];

        foreach ($targets as $key => $manifests) {
            foreach ($oldConfig[$key] ?? [] as $name) {
                if (!empty($manifests[$name]['mandatory'])
                    && !in_array($name, $newConfig[$key] ?? [], true)) {
                    $newConfig[$key][] = $name; // 잠금: 끄기 차단
                }
            }
        }
    }

    /**
     * 도메인 캐시 + 라우터 캐시 무효화
     *
     * @param int $domainId 도메인 ID
     */
    private function invalidateCaches(int $domainId): void
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return;
        }

        $domainName = $domain->getDomain();
        $this->domainCache->delete($domainName);
        Router::clearRouteCache($domainName);
    }

    /**
     * 플러그인 활성화/비활성화 토글
     *
     * @param int $domainId 도메인 ID
     * @param string $pluginName 플러그인 이름
     * @param bool $enabled 활성화 여부
     * @return Result
     */
    public function togglePlugin(int $domainId, string $pluginName, bool $enabled): Result
    {
        $config = $this->getExtensionConfig($domainId);
        $plugins = $config['plugins'] ?? [];

        if ($enabled) {
            // 플러그인 존재 확인
            $manifests = $this->getPluginManifests();
            if (!isset($manifests[$pluginName])) {
                return Result::failure("플러그인 '{$pluginName}'을(를) 찾을 수 없습니다.");
            }
            if (!in_array($pluginName, $plugins)) {
                $plugins[] = $pluginName;
            }
        } else {
            $plugins = array_values(array_filter($plugins, fn($p) => $p !== $pluginName));
        }

        $config['plugins'] = $plugins;
        return $this->saveExtensionConfig($domainId, $config);
    }

    /**
     * 패키지 활성화/비활성화 토글
     *
     * @param int $domainId 도메인 ID
     * @param string $packageName 패키지 이름
     * @param bool $enabled 활성화 여부
     * @return Result
     */
    public function togglePackage(int $domainId, string $packageName, bool $enabled): Result
    {
        $config = $this->getExtensionConfig($domainId);
        $packages = $config['packages'] ?? [];

        if ($enabled) {
            // 패키지 존재 확인
            $manifests = $this->getPackageManifests();
            if (!isset($manifests[$packageName])) {
                return Result::failure("패키지 '{$packageName}'을(를) 찾을 수 없습니다.");
            }
            if (!in_array($packageName, $packages)) {
                $packages[] = $packageName;
            }
        } else {
            $packages = array_values(array_filter($packages, fn($p) => $p !== $packageName));
        }

        $config['packages'] = $packages;
        return $this->saveExtensionConfig($domainId, $config);
    }

    /**
     * 플러그인이 활성화되어 있는지 확인
     *
     * @param int $domainId 도메인 ID
     * @param string $pluginName 플러그인 이름
     * @return bool
     */
    public function isPluginEnabled(int $domainId, string $pluginName): bool
    {
        $enabled = $this->getEnabledPlugins($domainId);
        return in_array($pluginName, $enabled);
    }

    /**
     * 패키지가 활성화되어 있는지 확인
     *
     * @param int $domainId 도메인 ID
     * @param string $packageName 패키지 이름
     * @return bool
     */
    public function isPackageEnabled(int $domainId, string $packageName): bool
    {
        $enabled = $this->getEnabledPackages($domainId);
        return in_array($packageName, $enabled);
    }

    /**
     * 활성화된 확장 목록에 manifest 정보 포함하여 반환
     * (Admin UI용)
     *
     * @param int $domainId 도메인 ID
     * @return array ['plugins' => [...], 'packages' => [...]]
     */
    public function getExtensionsWithManifests(int $domainId): array
    {
        $allManifests = $this->getAllManifests();

        // 체크박스 표시는 "운영자가 실제로 켠 상태"(영속 packages/plugins)만 근거로 한다.
        // mandatory/super_only 같은 강제 주입은 표시에 섞지 않는다 — 강제는 기능 레이어
        // (getEnabled*)에서만 일어난다. 그래야 운영자가 "꺼진 설정"을 정직하게 인지한다.
        // 예: mandatory:true 패키지를 운영자가 안 켰으면 체크박스는 OFF로 보이고,
        //     기능적 활성화는 getEnabledPackages의 강제로 따로 동작한다.
        $config = $this->getExtensionConfig($domainId);
        $enabledPlugins = $config['plugins'] ?? [];
        $enabledPackages = $config['packages'] ?? [];

        // 활성화된 항목을 저장 순서대로 먼저, 비활성화 항목을 뒤에 배치
        $plugins = $this->sortByEnabledOrder($allManifests['plugins'], $enabledPlugins);
        $packages = $this->sortByEnabledOrder($allManifests['packages'], $enabledPackages);

        return [
            'plugins' => $plugins,
            'packages' => $packages,
        ];
    }

    /**
     * 활성화 순서 유지 정렬
     *
     * 활성화된 항목은 enabledList 배열 순서대로 먼저 배치하고,
     * 비활성화 항목은 뒤에 이름순으로 배치한다.
     */
    private function sortByEnabledOrder(array $manifests, array $enabledList): array
    {
        $enabled = [];
        $disabled = [];

        // 활성화된 항목을 enabledList 순서대로 수집
        foreach ($enabledList as $name) {
            if (isset($manifests[$name])) {
                $enabled[$name] = array_merge($manifests[$name], ['enabled' => true]);
            }
        }

        // 비활성화 항목 수집
        foreach ($manifests as $name => $manifest) {
            if (!in_array($name, $enabledList)) {
                $disabled[$name] = array_merge($manifest, ['enabled' => false]);
            }
        }

        return $enabled + $disabled;
    }

    /**
     * manifest.json 파일 스캔
     *
     * @param string $basePath 스캔할 기본 경로
     * @param string $type 'plugin' 또는 'package'
     * @return array
     */
    private function scanManifests(string $basePath, string $type): array
    {
        $manifests = [];

        if (!is_dir($basePath)) {
            error_log("[EXTENSION] 확장 경로가 없습니다: {$basePath}");
            return $manifests;
        }

        $dirs = glob($basePath . '/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $name = basename($dir);

            // 이름 규칙은 설치기(zip 업로드)와 동일한 진실을 쓴다.
            // 로더가 이름으로 Provider FQCN 을 조립하므로, 규칙을 어기는
            // 디렉토리(예: FTP 로 넣은 my-plugin)를 목록에 노출하면
            // 활성화 후 로드만 조용히 깨진다 — 스캔 단계에서 걸러 알린다.
            if (!NestedPlugin::isValidName($name)) {
                error_log("[EXTENSION] 디렉토리명 '{$name}' 은(는) 확장 이름으로 쓸 수 없습니다"
                    . " (영문으로 시작하는 영문/숫자/_ 만 허용) — 건너뜁니다: {$dir}");
                continue;
            }

            $manifest = $this->readManifest($dir, $name, $type);
            if ($manifest !== null) {
                $manifests[$name] = $manifest;
            }
        }

        return $manifests;
    }

    /**
     * 확장 디렉토리 하나의 manifest.json 을 읽고 정규화한다.
     *
     * @param string $dir 확장 디렉토리 절대 경로
     * @param string $name 확장 이름 (독립: 디렉토리명, 종속: "{Pkg}/{Name}")
     * @param string $type 'plugin' 또는 'package'
     * @return array|null manifest 가 없거나 깨졌으면 null
     */
    private function readManifest(string $dir, string $name, string $type): ?array
    {
        $manifestFile = $dir . '/manifest.json';

        if (!is_file($manifestFile)) {
            return null;
        }

        $content = file_get_contents($manifestFile);
        $manifest = json_decode($content, true);

        // 커뮤니티 zip 배포에서는 깨진 manifest 가 들어올 수 있다.
        // 그 확장 하나만 건너뛰고 관리자 화면은 살아 있어야 한다.
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($manifest)) {
            error_log("[EXTENSION] manifest.json 파싱 실패 ({$dir}): " . json_last_error_msg());
            return null;
        }

        // name 은 디렉토리(구조)가 진실이다.
        // 로딩은 이름으로 Provider 클래스를 조립하고(ExtensionManager::loadPlugin),
        // 활성 목록도 이름을 키로 저장한다. manifest 가 다른 name 을 적으면
        // 조회(ExtensionManager L263,279)만 어긋나 조용히 깨지므로 덮어쓰기를 막는다.
        if (isset($manifest['name']) && $manifest['name'] !== NestedPlugin::basename($name)) {
            error_log(
                "[EXTENSION] manifest.json 의 name('{$manifest['name']}')이 디렉토리명('{$name}')과 다릅니다"
                . " — 디렉토리명을 사용합니다."
            );
        }
        unset($manifest['name']);

        // 기본값 설정
        $merged = array_merge([
            'label' => NestedPlugin::basename($name),
            'description' => '',
            'version' => '1.0.0',
            'author' => '',
            'author_url' => '',
            'icon' => 'bi-grid',
            'type' => $type,
            'hidden' => false,
            'super_only' => false,
            // 배포자 식별자. 커뮤니티 zip/git 배포에서 같은 이름의 확장을 구분한다.
            // 값이 없으면 익명 배포로 간주하고 id 는 name 만 갖는다.
            'vendor' => '',
            // 통일 스키마: 모든 manifest가 항상 두 키를 갖도록 정규화
            'default' => false,    // 설치 시 자동 활성화 여부
            'mandatory' => false,  // true면 끌 수 없음(항상 강제 ON). 누락/null → false
            'requires' => [],
        ], $manifest);

        $merged['name'] = $name;
        $merged['vendor'] = $this->normalizeVendor($merged['vendor']);
        // 커뮤니티 배포에서 확장을 전역으로 지목하는 이름. 예: mublo/Banner
        $merged['id'] = $merged['vendor'] !== '' ? "{$merged['vendor']}/{$name}" : $name;

        return $merged;
    }

    /**
     * vendor 값 정규화
     *
     * 소문자 영숫자·하이픈만 허용한다. id 가 "vendor/name" 형태이므로 슬래시가 섞이면
     * 식별자가 깨진다. 규칙에 맞지 않으면 익명('')으로 떨어뜨린다.
     */
    private function normalizeVendor(mixed $vendor): string
    {
        if (!is_string($vendor)) {
            return '';
        }

        $vendor = strtolower(trim($vendor));

        return preg_match('/^[a-z0-9][a-z0-9-]{0,38}$/', $vendor) === 1 ? $vendor : '';
    }

    /**
     * extension_config 유효성 검증
     *
     * @param array $config
     * @return array ['valid' => bool, 'message' => string]
     */
    private function validateExtensionConfig(array $config, bool $validateRoutes = true): array
    {
        // plugins가 배열인지 확인
        if (isset($config['plugins']) && !is_array($config['plugins'])) {
            return ['valid' => false, 'message' => 'plugins는 배열이어야 합니다.'];
        }

        // packages가 배열인지 확인
        if (isset($config['packages']) && !is_array($config['packages'])) {
            return ['valid' => false, 'message' => 'packages는 배열이어야 합니다.'];
        }

        // 플러그인 manifest 존재 검증 (패키지 종속 플러그인은 패키지 안에서 찾는다)
        foreach ($config['plugins'] ?? [] as $name) {
            if (!is_string($name) || empty(trim($name))) {
                continue;
            }
            $extDir = NestedPlugin::isNested($name)
                ? NestedPlugin::dir($name)
                : $this->pluginPath . '/' . $name;
            if ($extDir === null || !file_exists($extDir . '/manifest.json')) {
                return ['valid' => false, 'message' => "플러그인 '{$name}'의 manifest.json을 찾을 수 없습니다."];
            }
        }

        // 패키지 manifest 존재 검증
        foreach ($config['packages'] ?? [] as $name) {
            if (!is_string($name) || empty(trim($name))) {
                continue;
            }
            $manifestPath = $this->packagePath . '/' . $name . '/manifest.json';
            if (!file_exists($manifestPath)) {
                return ['valid' => false, 'message' => "패키지 '{$name}'의 manifest.json을 찾을 수 없습니다."];
            }
        }

        // requires 검증 — composer 해결자가 없으므로 코어가 직접 막는다.
        // 활성화하는 순간이 유일한 게이트다.
        if ($incompatible = $this->findIncompatible($config)) {
            return ['valid' => false, 'message' => $incompatible];
        }

        if ($validateRoutes) {
            // 저장 전에 실제 Core/Plugin/Package 라우트를 구성해 충돌을 차단한다.
            $routeValidation = (new Router())->validateExtensionConfiguration(
                $config,
                $this->pluginPath,
                $this->packagePath
            );
            if (!$routeValidation['valid']) {
                return $routeValidation;
            }
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * 활성화하려는 확장 중 requires 를 만족하지 못하는 것을 찾는다.
     *
     * @param array $config 활성화 대상 extension_config
     * @return string|null 사람이 읽는 사유. 모두 호환이면 null.
     */
    private function findIncompatible(array $config): ?string
    {
        $manifests = $this->getPluginManifests() + $this->getPackageManifests();

        // 이 설정을 적용한 뒤의 설치 상태를 기준으로 판단한다.
        $installedVersions = [];
        foreach (['plugin' => 'plugins', 'package' => 'packages'] as $kind => $key) {
            foreach ($config[$key] ?? [] as $name) {
                if (is_string($name) && isset($manifests[$name])) {
                    $installedVersions["{$kind}:{$name}"] = (string) $manifests[$name]['version'];
                }
            }
        }

        $coreVersion = Application::VERSION;

        foreach (['plugins', 'packages'] as $key) {
            foreach ($config[$key] ?? [] as $name) {
                if (!is_string($name) || !isset($manifests[$name])) {
                    continue;
                }

                $reasons = $this->compatibility->check($manifests[$name], $coreVersion, $installedVersions);
                if ($reasons !== []) {
                    return "'{$name}'을(를) 활성화할 수 없습니다. " . implode(' ', $reasons);
                }
            }
        }

        return null;
    }

    /**
     * extension_config 정규화
     *
     * @param array $config
     * @return array
     */
    private function sanitizeExtensionConfig(array $config): array
    {
        $sanitized = [
            'plugins' => array_values(array_filter(
                $config['plugins'] ?? [],
                fn($v) => is_string($v) && !empty(trim($v))
            )),
            'packages' => array_values(array_filter(
                $config['packages'] ?? [],
                fn($v) => is_string($v) && !empty(trim($v))
            )),
        ];

        // installed 키가 있으면 보존
        if (isset($config['installed'])) {
            $sanitized['installed'] = $config['installed'];
        }

        return $sanitized;
    }

    /**
     * 하위 도메인에서 super_only 플러그인 직접 제어를 제거한다.
     *
     * super_only 플러그인은 SUPER 도메인이 켜고 끄며,
     * 하위 도메인은 저장 요청으로 이를 변경하지 않는다.
     */
    private function stripManagedSuperOnlyPluginsFromChildDomain(int $domainId, array &$config): void
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return;
        }

        $group = $domain->getDomainGroup() ?? '';
        $rootId = (int) explode('/', $group)[0];

        if ($rootId <= 0 || $rootId === $domainId) {
            return;
        }

        $superOnlyNames = [];
        foreach ($this->getPluginManifests() as $name => $manifest) {
            if (!empty($manifest['super_only'])) {
                $superOnlyNames[] = $name;
            }
        }

        if (empty($superOnlyNames)) {
            return;
        }

        $config['plugins'] = array_values(array_filter(
            $config['plugins'] ?? [],
            fn($name) => !in_array($name, $superOnlyNames, true)
        ));
    }

    // =========================================================================
    // Install 상태 추적
    // =========================================================================

    /**
     * 확장이 설치(install)된 적 있는지 확인
     */
    public function isInstalled(int $domainId, string $type, string $name): bool
    {
        $config = $this->getExtensionConfig($domainId);
        $installed = $config['installed'][$type . 's'] ?? [];
        return in_array($name, $installed);
    }

    /**
     * 확장을 설치 완료 상태로 마킹
     */
    private function markInstalled(array &$config, string $type, string $name): void
    {
        $key = $type . 's'; // 'plugin' → 'plugins', 'package' → 'packages'
        if (!isset($config['installed'][$key])) {
            $config['installed'][$key] = [];
        }
        if (!in_array($name, $config['installed'][$key])) {
            $config['installed'][$key][] = $name;
        }
    }

    /**
     * 확장의 설치 상태 마킹 해제
     */
    private function markUninstalled(array &$config, string $type, string $name): void
    {
        $key = $type . 's';
        if (isset($config['installed'][$key])) {
            $config['installed'][$key] = array_values(
                array_filter($config['installed'][$key], fn($n) => $n !== $name)
            );
        }
    }

    // =========================================================================
    // Install/Uninstall 라이프사이클
    // =========================================================================

    /**
     * 설정 변경 시 install/uninstall 라이프사이클 실행
     *
     * @param int $domainId 도메인 ID
     * @param array $oldConfig 기존 설정
     * @param array $newConfig 새 설정 (installed 키 포함, 참조로 수정됨)
     * @param DependencyContainer $container
     * @param Context $context
     * @return array 수정된 newConfig
     */
    private function executeLifecycle(
        int $domainId,
        array $oldConfig,
        array $newConfig,
        DependencyContainer $container,
        Context $context
    ): array {
        // 시작은 부모 Package → 자식 Plugin 순서다. Package 설치가 실패해
        // 활성 목록에서 제거되면 아래 Plugin 단계가 같은 config를 보고 자식을 제외한다.
        $this->processActivatedForType('package', $oldConfig, $newConfig, $container, $context);
        $this->processActivatedForType('plugin', $oldConfig, $newConfig, $container, $context);

        // 종료는 역순이다. 자식이 먼저 연결을 정리한 뒤 부모를 비활성화한다.
        $this->processDeactivatedForType('plugin', $oldConfig, $newConfig, $container, $context);
        $this->processDeactivatedForType('package', $oldConfig, $newConfig, $container, $context);

        return $newConfig;
    }

    /**
     * 타입별 활성화 라이프사이클 처리
     */
    private function processActivatedForType(
        string $type,
        array $oldConfig,
        array &$newConfig,
        DependencyContainer $container,
        Context $context
    ): void {
        $key = $type . 's'; // 'plugins' or 'packages'
        $oldList = $oldConfig[$key] ?? [];
        $newList = $newConfig[$key] ?? [];
        $installedList = $newConfig['installed'][$key] ?? [];

        $activated = array_diff($newList, $oldList);
        foreach ($activated as $name) {
            if ($type === 'plugin' && !$this->hasActiveParent($name, $newConfig)) {
                error_log("Extension dependency error [plugin:{$name}]: parent package is not active in this domain");
                $newConfig[$key] = array_values(
                    array_filter($newConfig[$key], fn($n) => $n !== $name)
                );
                continue;
            }

            // 미설치 상태이면 install 실행
            if (!in_array($name, $installedList, true)) {
                $provider = $this->resolveProvider($type, $name);
                if ($provider instanceof InstallableExtensionInterface) {
                    try {
                        $provider->register($container);
                        $this->runMigrationsOrFail($type, $name, $container);
                        $provider->install($container, $context);
                        $this->markInstalled($newConfig, $type, $name);
                    } catch (\Throwable $e) {
                        error_log("Extension install error [{$type}:{$name}]: " . $e->getMessage());
                        // install 실패 시 활성 목록에서 제거 (불일치 방지)
                        $newConfig[$key] = array_values(
                            array_filter($newConfig[$key], fn($n) => $n !== $name)
                        );
                    }
                }
            }
        }
    }

    /**
     * 타입별 비활성화 라이프사이클 처리
     */
    private function processDeactivatedForType(
        string $type,
        array $oldConfig,
        array &$newConfig,
        DependencyContainer $container,
        Context $context
    ): void {
        $key = $type . 's';
        $oldList = $oldConfig[$key] ?? [];
        $newList = $newConfig[$key] ?? [];
        $deactivated = array_diff($oldList, $newList);
        foreach ($deactivated as $name) {
            $provider = $this->resolveProvider($type, $name);
            if ($provider instanceof InstallableExtensionInterface) {
                try {
                    $provider->register($container);
                    $provider->uninstall($container, $context);
                    $this->markUninstalled($newConfig, $type, $name);
                } catch (\Throwable $e) {
                    error_log("Extension uninstall error [{$type}:{$name}]: " . $e->getMessage());
                    // uninstall 실패 시 활성 목록에 복원 (불일치 방지)
                    $newConfig[$key][] = $name;
                }
            }
        }
    }

    /**
     * Nested Plugin의 부모는 같은 도메인에서 활성 상태여야 한다.
     */
    private function hasActiveParent(string $name, array $config): bool
    {
        $parent = NestedPlugin::parentPackage($name);
        return $parent === null || in_array($parent, $config['packages'] ?? [], true);
    }

    private function extensionDirectory(string $type, string $name): ?string
    {
        if ($type === 'plugin' && NestedPlugin::isNested($name)) {
            return NestedPlugin::dir($name);
        }

        $base = $type === 'plugin' ? $this->pluginPath : $this->packagePath;
        return $base . '/' . $name;
    }

    /**
     * MigrationRunner의 Result 실패를 예외로 승격해 install/installed 처리를 중단한다.
     */
    private function runMigrationsOrFail(
        string $type,
        string $name,
        DependencyContainer $container
    ): void {
        $extensionDir = $this->extensionDirectory($type, $name);
        if ($extensionDir === null) {
            throw new \RuntimeException("확장 경로를 찾을 수 없습니다: {$type}:{$name}");
        }

        $migrationPath = $extensionDir . '/database/migrations';
        if (!is_dir($migrationPath) || !$container->has(MigrationRunner::class)) {
            return;
        }

        $result = $container->get(MigrationRunner::class)->run($type, $name, $migrationPath);
        if (($result['success'] ?? false) !== true) {
            $message = (string) ($result['error'] ?? '알 수 없는 Migration 오류');
            throw new \RuntimeException("Migration failed [{$type}:{$name}]: {$message}");
        }
    }

    /**
     * Provider 인스턴스 생성
     *
     * @param string $type 'plugin' 또는 'package'
     * @param string $name 확장 이름
     * @return ExtensionProviderInterface|null
     */
    /**
     * 새 도메인의 default 패키지 시드 데이터 실행
     *
     * 도메인 생성 직후 Controller에서 호출.
     * extension_config에 등록된 패키지의 database/seeders/ PHP 파일을 실행.
     * 시더 함수에 $pdo, $domainId를 전달하여 도메인별 시드 가능.
     */
    public function seedDefaultExtensions(int $domainId): void
    {
        $config = $this->getExtensionConfig($domainId);
        $packages = $config['installed']['packages'] ?? [];

        if (empty($packages)) {
            return;
        }

        $pdo = $this->db->getPdo();

        foreach ($packages as $name) {
            $seederPath = $this->packagePath . '/' . $name . '/database/seeders';
            if (!is_dir($seederPath)) {
                continue;
            }

            $files = glob($seederPath . '/*.php') ?: [];
            sort($files);

            foreach ($files as $file) {
                try {
                    $seederFn = require $file;
                    if (is_callable($seederFn)) {
                        $seederFn($pdo, $domainId);
                    }
                } catch (\Throwable $e) {
                    error_log("Extension seed error [package:{$name}]: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * 지정한 확장들의 install() 시드를 재실행한다 (마이그레이션 실행 직후 호출).
     *
     * 활성화 시점엔 테이블이 없어 건너뛴 시드(예: Shop 배송 템플릿)를
     * 마이그레이션으로 테이블이 생긴 뒤 마무리하기 위함.
     *
     * 주의: "이번 실행에서 실제로 마이그레이션이 돈 확장"만 넘겨야 한다.
     * 활성 확장 전체에 무차별로 install()을 재호출하면, install()이 자체 마이그레이션을
     * 수행하는(=시스템 관리 시점엔 pending이 없는) 확장까지 재실행되어 시드가 중복될 수 있다.
     * install()은 멱등이어야 하며, 실패는 로깅만 하고 넘어간다.
     *
     * @param array $extensions [['type' => 'plugin'|'package', 'name' => string], ...]
     * @param DependencyContainer $container
     * @param Context $context
     */
    public function seedExtensions(
        array $extensions,
        DependencyContainer $container,
        Context $context
    ): void {
        foreach ($extensions as $ext) {
            $type = $ext['type'] ?? '';
            $name = $ext['name'] ?? '';
            if ($type === '' || $name === '') {
                continue;
            }

            $provider = $this->resolveProvider($type, $name);
            if (!$provider instanceof InstallableExtensionInterface) {
                continue;
            }
            try {
                $provider->register($container);
                $provider->install($container, $context);
            } catch (\Throwable $e) {
                error_log("Extension post-migration seed error [{$type}:{$name}]: " . $e->getMessage());
            }
        }
    }

    /**
     * 활성 목록(packages/plugins)에 있으나 아직 설치되지 않은 확장을 install()로 1회 수렴시킨다 (부팅 시 호출).
     *
     * 설치기/서브도메인 시드는 default 패키지를 활성 목록에 넣되 install()은 못 돌린다
     * (mysqli 단계라 컨테이너/Context 없음). 그래서 "활성인데 미설치"인 갭이 생기고,
     * 이 메서드가 부팅 후 컨테이너가 갖춰진 상태에서 그 갭을 install()로 메운다.
     *
     * - 활성 목록 − installed 차집합만 설치. mandatory는 트리거가 아니다(설치 강제 안 함).
     * - 항목별 멱등(install() 멱등 전제) — 여러 요청/경로에서 중복 호출돼도 안전.
     * - 실패 시 installed 마킹을 보류 → 다음 요청에 재시도.
     * - 활성 목록은 건드리지 않는다(운영자가 끈 항목을 되살리지 않음).
     */
    public function reconcileDefaultExtensions(
        int $domainId,
        DependencyContainer $container,
        Context $context
    ): void {
        if ($domainId <= 0) {
            return;
        }

        // 영속된 활성 목록(주입 전 원본) — 운영자가 끈 항목은 여기에 없다.
        $config = $this->getExtensionConfig($domainId);

        $targets = [
            'package' => $this->getPackageManifests(),
            'plugin'  => $this->getPluginManifests(),
        ];

        foreach ($targets as $type => $manifests) {
            $enabledList = $type === 'plugin'
                ? $this->getEnabledPlugins($domainId)
                : $this->getEnabledPackages($domainId);
            foreach ($manifests as $name => $manifest) {
                // 설치 대상: 활성 목록에 있는 것만(설치기/서브도메인 시드 · 운영자 활성화).
                // mandatory는 설치를 트리거하지 않는다("끌 수 없음"만 의미) → default:false면 미설치.
                // default만으론 설치 안 함 → 운영자가 끈 default 패키지를 되살리지 않음.
                if (!in_array($name, $enabledList, true) || $this->isInstalled($domainId, $type, $name)) {
                    continue;
                }

                // 직전 설치 실패로 backoff 중이면 이번 요청은 재시도하지 않는다(매 요청 재시도·로그 스팸 방지).
                if ($this->cache?->get("reconcile_backoff:{$domainId}:{$type}:{$name}") !== null) {
                    continue;
                }

                if ($type === 'plugin' && !$this->hasActiveParent($name, $config)) {
                    continue;
                }
                $this->installDefaultExtension($type, $name, $domainId, $container, $context);
            }
        }
    }

    /**
     * default 확장 1건을 설치(마이그레이션 → install())하고 installed로 마킹한다.
     * 실패 시 마킹을 보류해 다음 요청에 재시도된다.
     */
    private function installDefaultExtension(
        string $type,
        string $name,
        int $domainId,
        DependencyContainer $container,
        Context $context
    ): void {
        $provider = $this->resolveProvider($type, $name);
        if (!$provider instanceof InstallableExtensionInterface) {
            return;
        }

        try {
            // 마이그레이션 선행(멱등) — install()이 자체 수행해도 schema_migrations로 중복 방지.
            $this->runMigrationsOrFail($type, $name, $container);

            $provider->register($container);
            $provider->install($container, $context);

            // 성공 시에만 installed 마킹 + 영속화
            $config = $this->getExtensionConfig($domainId);
            $this->markInstalled($config, $type, $name);
            $this->domainRepository->updateExtensionConfig($domainId, $config);

            // 메모 캐시 무효화 (같은 요청의 이후 조회가 installed 마킹을 보도록)
            unset($this->domainByIdCache[$domainId]);
        } catch (\Throwable $e) {
            error_log("Reconcile install error [{$type}:{$name}]: " . $e->getMessage());
            // 마킹 보류 → 재시도 대상이지만, backoff 마커로 다음 재시도를 늦춘다.
            // (마이그레이션 지속 실패 등에서 매 요청 전체 재시도 + 로그 스팸을 막는다. 캐시 미주입 시 기존 동작.)
            $this->cache?->set("reconcile_backoff:{$domainId}:{$type}:{$name}", 1, self::RECONCILE_BACKOFF_TTL);
        }
    }

    private function resolveProvider(string $type, string $name): ?ExtensionProviderInterface
    {
        if ($type === 'plugin' && NestedPlugin::isNested($name)) {
            $providerClass = NestedPlugin::providerClass($name);
        } else {
            $namespace = $type === 'plugin' ? 'Plugin' : 'Packages';
            $providerClass = "Mublo\\{$namespace}\\{$name}\\{$name}Provider";
        }

        if ($providerClass === null || !class_exists($providerClass)) {
            return null;
        }

        try {
            return new $providerClass();
        } catch (\Throwable $e) {
            error_log("Provider resolve error [{$type}:{$name}]: " . $e->getMessage());
            return null;
        }
    }
}
