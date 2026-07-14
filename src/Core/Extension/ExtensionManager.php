<?php
namespace Mublo\Core\Extension;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;

/**
 * ExtensionManager
 *
 * 플러그인/패키지 확장 로딩 관리자
 *
 * 책임:
 * - 활성화된 플러그인/패키지 로딩
 * - Provider 인스턴스화 및 실행
 * - 각 Provider가 자신의 이벤트 구독자를 boot()에서 등록
 *
 * 사용 시점:
 * - Application::run() 에서 Context 생성 및 도메인 검증 후 호출
 */
class ExtensionManager
{
    private DependencyContainer $container;
    private string $pluginPath;
    private string $packagePath;
    private ?ExtensionLoadDiagnostics $diagnostics;

    /**
     * 로딩된 Provider 인스턴스
     * @var array<int, array{provider: ExtensionProviderInterface, type: string, name: string, parent: ?string, owner: string, booted: bool}>
     */
    private array $loadedProviders = [];

    /** @var array<string, true> register 또는 boot에 실패한 Package */
    private array $failedPackages = [];

    /** @var array<string, true> 이 요청에서 활성화된 Package */
    private array $enabledPackageSet = [];

    /**
     * 로딩된 확장 정보
     * @var array
     */
    private array $loadedExtensions = [
        'plugins' => [],
        'packages' => [],
    ];

    /**
     * 확장 로딩/부팅 실패 정보.
     *
     * @var array<int, array{type: string, name: string, stage: string, message: string}>
     */
    private array $loadErrors = [];

    public function __construct(DependencyContainer $container, ?ExtensionLoadDiagnostics $diagnostics = null)
    {
        $this->container = $container;
        $this->diagnostics = $diagnostics;

        // 경로 설정 (plugins/, packages/)
        $this->pluginPath = MUBLO_PLUGIN_PATH;
        $this->packagePath = MUBLO_PACKAGE_PATH;
    }

    /**
     * 활성화된 확장 로딩
     *
     * @param Context $context 현재 요청 Context
     * @param array $enabledPlugins 활성화된 플러그인 목록
     * @param array $enabledPackages 활성화된 패키지 목록
     */
    public function loadExtensions(
        Context $context,
        array $enabledPlugins,
        array $enabledPackages
    ): void {
        $this->loadedProviders = [];
        $this->loadedExtensions = ['plugins' => [], 'packages' => []];
        $this->loadErrors = [];
        $this->failedPackages = [];
        ExtensionRegistrationScope::reset();
        $this->diagnostics?->clear();
        $this->enabledPackageSet = array_fill_keys($enabledPackages, true);

        // 동층위(package↔package, plugin↔plugin) 로딩 순서는 보장하지 않는다 — 의도적 정책.
        // 확장 간 상호 직접 참조는 금지이고(계약은 lazy resolve, 이벤트 구독은 boot 완료 후
        // dispatch), 원칙을 지키는 확장에게 순서는 관찰 불가능하다. 순서를 보장해 주면
        // 순서 의존(=원칙 위반) 코드가 안정 동작해 생태계에 잘못된 신호를 주므로,
        // 코어는 위상정렬을 제공하지 않는다. requires 는 활성화 검증(findIncompatible) 전용.
        try {
            // 1. 부모 패키지를 먼저 register한다.
            foreach ($enabledPackages as $packageName) {
                $this->loadPackage($packageName, $context);
            }

            // 2. 독립 플러그인과 패키지 종속 플러그인을 register한다.
            foreach ($enabledPlugins as $pluginName) {
                $this->loadPlugin($pluginName, $context);
            }

            // 3. 모든 Provider boot() 호출
            // 각 Provider가 boot()에서 모든 이벤트 구독자를 등록
            $this->bootProviders($context);
        } catch (\Throwable $e) {
            // debug/critical 확장의 예외가 전파될 때도 이전 provider의 보류 상태를 제거한다.
            foreach ($this->loadedProviders as $loaded) {
                $this->rollbackRegistration($loaded['owner'], $loaded['type'], $loaded['name']);
            }
            ExtensionRegistrationScope::reset();
            throw $e;
        }
    }

    /**
     * 플러그인 로딩
     *
     * @param string $pluginName 플러그인 이름
     * @param Context $context
     */
    private function loadPlugin(string $pluginName, Context $context): void
    {
        $parent = NestedPlugin::parentPackage($pluginName);
        if ($parent !== null
            && (!isset($this->enabledPackageSet[$parent]) || isset($this->failedPackages[$parent]))) {
            $this->recordDependencySkip('plugin', $pluginName, $parent, 'register');
            return;
        }

        // 패키지 종속 플러그인("{Pkg}/{Name}")은 부모 패키지 네임스페이스 아래 산다
        $providerClass = NestedPlugin::isNested($pluginName)
            ? NestedPlugin::providerClass($pluginName)
            : "Mublo\\Plugin\\{$pluginName}\\{$pluginName}Provider";

        if ($providerClass === null) {
            return;
        }

        if (!class_exists($providerClass)) {
            // Provider가 없으면 스킵 (간단한 플러그인)
            $this->loadedExtensions['plugins'][] = [
                'name' => $pluginName,
                'hasProvider' => false,
            ];
            return;
        }

        $owner = $this->scopeOwner('plugin', $pluginName);
        ExtensionRegistrationScope::begin($owner);

        try {
            /** @var ExtensionProviderInterface $provider */
            $provider = new $providerClass();

            // register() 호출
            $provider->register($this->container);
            ExtensionRegistrationScope::suspend($owner);

            $this->loadedProviders[] = [
                'provider' => $provider,
                'type' => 'plugin',
                'name' => $pluginName,
                'parent' => $parent,
                'owner' => $owner,
                'booted' => false,
            ];
            $this->loadedExtensions['plugins'][] = [
                'name' => $pluginName,
                'hasProvider' => true,
                'providerClass' => $providerClass,
            ];
        } catch (\Throwable $e) {
            $this->rollbackRegistration($owner, 'plugin', $pluginName);
            $this->handleExtensionError('plugin', $pluginName, 'load', $e);
        }
    }

    /**
     * 패키지 로딩
     *
     * @param string $packageName 패키지 이름
     * @param Context $context
     */
    private function loadPackage(string $packageName, Context $context): void
    {
        $providerClass = "Mublo\\Packages\\{$packageName}\\{$packageName}Provider";

        if (!class_exists($providerClass)) {
            $this->loadedExtensions['packages'][] = [
                'name' => $packageName,
                'hasProvider' => false,
            ];
            return;
        }

        $owner = $this->scopeOwner('package', $packageName);
        ExtensionRegistrationScope::begin($owner);

        try {
            /** @var ExtensionProviderInterface $provider */
            $provider = new $providerClass();

            // register() 호출
            $provider->register($this->container);
            ExtensionRegistrationScope::suspend($owner);

            $this->loadedProviders[] = [
                'provider' => $provider,
                'type' => 'package',
                'name' => $packageName,
                'parent' => null,
                'owner' => $owner,
                'booted' => false,
            ];
            $this->loadedExtensions['packages'][] = [
                'name' => $packageName,
                'hasProvider' => true,
                'providerClass' => $providerClass,
            ];
        } catch (\Throwable $e) {
            $this->rollbackRegistration($owner, 'package', $packageName);
            $this->failedPackages[$packageName] = true;
            $this->handleExtensionError('package', $packageName, 'load', $e);
        }
    }

    /**
     * 모든 로딩된 Provider의 boot() 호출
     *
     * @param Context $context
     */
    private function bootProviders(Context $context): void
    {
        foreach ($this->loadedProviders as $index => $loaded) {
            $provider = $loaded['provider'];
            $parent = $loaded['parent'];
            if ($parent !== null && isset($this->failedPackages[$parent])) {
                $this->rollbackRegistration($loaded['owner'], $loaded['type'], $loaded['name']);
                $this->recordDependencySkip($loaded['type'], $loaded['name'], $parent, 'boot');
                continue;
            }

            ExtensionRegistrationScope::resume($loaded['owner']);
            try {
                $provider->boot($this->container, $context);
                ExtensionRegistrationScope::suspend($loaded['owner']);
                ExtensionRegistrationScope::commit($loaded['owner']);
                $this->loadedProviders[$index]['booted'] = true;
            } catch (\Throwable $e) {
                $this->rollbackRegistration($loaded['owner'], $loaded['type'], $loaded['name']);
                if ($loaded['type'] === 'package') {
                    $this->failedPackages[$loaded['name']] = true;
                }
                $this->handleExtensionError($loaded['type'], $loaded['name'], 'boot', $e);
            }
        }
    }

    private function scopeOwner(string $type, string $name): string
    {
        return "{$type}:{$name}";
    }

    private function rollbackRegistration(string $owner, string $type, string $name): void
    {
        // 롤백된 확장은 loadedExtensions 에서도 제거한다. register 성공 후 boot 실패로 롤백되면
        // 이미 추가돼 있으므로, isPluginLoaded/isPackageLoaded 가 '로드됨'으로 오보하지 않게 뺀다.
        $bucket = $type === 'package' ? 'packages' : 'plugins';
        $this->loadedExtensions[$bucket] = array_values(array_filter(
            $this->loadedExtensions[$bucket],
            static fn(array $e): bool => ($e['name'] ?? null) !== $name
        ));

        foreach (ExtensionRegistrationScope::rollback($owner) as $error) {
            $this->loadErrors[] = [
                'type' => $type,
                'name' => $name,
                'stage' => 'rollback',
                'message' => $error->getMessage(),
            ];
            $this->diagnostics?->record($type, $name, 'rollback', $error);
            error_log("Extension rollback error [{$type}:{$name}]: " . $error->getMessage());
        }
    }

    private function recordDependencySkip(
        string $type,
        string $name,
        string $parent,
        string $stage
    ): void {
        $message = "Parent package '{$parent}' is unavailable";
        $this->loadErrors[] = [
            'type' => $type,
            'name' => $name,
            'stage' => 'dependency',
            'message' => $message,
        ];
        $this->diagnostics?->record(
            $type,
            $name,
            'dependency',
            new \RuntimeException("{$message}; skipped during {$stage}")
        );
        error_log("Extension dependency skip [{$type}:{$name}]: {$message}; stage={$stage}");
    }

    /**
     * 확장 실패 처리.
     *
     * 개발 모드 또는 manifest.json의 critical=true 확장은 즉시 예외를 전파한다.
     */
    private function handleExtensionError(string $type, string $name, string $stage, \Throwable $e): void
    {
        $this->loadErrors[] = [
            'type' => $type,
            'name' => $name,
            'stage' => $stage,
            'message' => $e->getMessage(),
        ];
        $this->diagnostics?->record($type, $name, $stage, $e);

        if ($this->shouldRethrow($type, $name)) {
            // critical 확장 실패는 상위(Application)가 운영 모드에서 흡수해 계속 서빙할 수 있다.
            // 그 경우 조용히 묻히지 않도록, rethrow 전에 CRITICAL 로 명확히 남긴다(관측 강화).
            if ($this->isCriticalExtension($type, $name)) {
                error_log("[EXTENSION][CRITICAL] {$stage} failed [{$type}:{$name}]: " . $e->getMessage());
            }
            throw new \RuntimeException(
                "Extension {$stage} failed [{$type}:{$name}]: " . $e->getMessage(),
                0,
                $e
            );
        }

        error_log("Extension {$stage} error [{$type}:{$name}]: " . $e->getMessage());
    }

    private function shouldRethrow(string $type, string $name): bool
    {
        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            return true;
        }

        return $this->isCriticalExtension($type, $name);
    }

    private function isCriticalExtension(string $type, string $name): bool
    {
        $basePath = match ($type) {
            'plugin' => $this->pluginPath,
            'package' => $this->packagePath,
            default => null,
        };

        if ($basePath === null) {
            return false;
        }

        $extDir = ($type === 'plugin' && NestedPlugin::isNested($name))
            ? NestedPlugin::dir($name)
            : $basePath . '/' . $name;

        $manifestPath = $extDir . '/manifest.json';
        if (!is_file($manifestPath)) {
            return false;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        return is_array($manifest) && ($manifest['critical'] ?? false) === true;
    }

    /**
     * 로딩된 확장 정보 반환
     *
     * @return array
     */
    public function getLoadedExtensions(): array
    {
        return $this->loadedExtensions;
    }

    /**
     * register/boot가 완료된 Provider 인스턴스를 반환한다.
     *
     * 데이터 초기화처럼 Provider가 register 단계에서 받은 계약을 사용해야 하는 후속 작업은
     * 새 Provider를 생성하지 않고 이 인스턴스를 재사용해야 한다.
     */
    public function getLoadedProvider(string $type, string $name): ?ExtensionProviderInterface
    {
        if (!in_array($type, ['plugin', 'package'], true)) {
            return null;
        }

        foreach ($this->loadedProviders as $loaded) {
            if ($loaded['type'] === $type && $loaded['name'] === $name && $loaded['booted']) {
                return $loaded['provider'];
            }
        }

        return null;
    }

    /**
     * 확장 로딩/부팅 실패 정보 반환.
     */
    public function getLoadErrors(): array
    {
        return $this->loadErrors;
    }

    /**
     * 특정 플러그인이 로딩되었는지 확인
     *
     * @param string $pluginName
     * @return bool
     */
    public function isPluginLoaded(string $pluginName): bool
    {
        foreach ($this->loadedExtensions['plugins'] as $plugin) {
            if ($plugin['name'] === $pluginName) {
                return true;
            }
        }
        return false;
    }

    /**
     * 특정 패키지가 로딩되었는지 확인
     *
     * @param string $packageName
     * @return bool
     */
    public function isPackageLoaded(string $packageName): bool
    {
        foreach ($this->loadedExtensions['packages'] as $package) {
            if ($package['name'] === $packageName) {
                return true;
            }
        }
        return false;
    }
}
