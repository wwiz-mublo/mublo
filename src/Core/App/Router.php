<?php
declare(strict_types=1);
namespace Mublo\Core\App;

use FastRoute\RouteCollector;
use FastRoute\Dispatcher as FastRouteDispatcher;
use FastRoute\BadRouteException;
use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteParser\Std;
use Mublo\Core\Http\Request;
use Mublo\Core\Context\Context;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Extension\ExtensionLoadDiagnostics;
use Mublo\Core\Extension\NestedPlugin;
use Mublo\Service\Extension\ExtensionService;
use Mublo\Exception\HttpNotFoundException;
use function FastRoute\simpleDispatcher;
use function FastRoute\cachedDispatcher;

/**
 * Class Router
 *
 * ============================================================
 * Router – FastRoute 기반 URL 라우팅 시스템
 * ============================================================
 *
 * 이 클래스는 HTTP 요청의 URL을 분석하여
 * 적절한 Controller와 Method를 결정하는 역할을 담당한다.
 *
 * ------------------------------------------------------------
 * [핵심 기능]
 * ------------------------------------------------------------
 *
 * 1. 명시적 라우트 매칭 (FastRoute 기반)
 * 2. Plugin/Package 라우트 자동 로드
 * 3. Admin 미매칭 시 HTTP method가 제한된 Controller/Method 매핑 (autoResolve)
 * 4. 도메인별 라우트 캐싱 (프로덕션 환경 최적화)
 *
 * ------------------------------------------------------------
 * [도메인별 라우트 캐싱 시스템]
 * ------------------------------------------------------------
 *
 * 멀티 도메인 환경에서 각 도메인은 서로 다른 Plugin/Package를
 * 활성화할 수 있으므로, 캐시 파일을 도메인별로 분리한다.
 *
 * 캐시 파일 위치:
 * - storage/cache/routes/{domain}.{signature}.cache.php
 * - 예: storage/cache/routes/shop.example.com.a1b2c3d4e5f6.cache.php
 *
 * 캐시 활성화 조건:
 * - APP_DEBUG=false (프로덕션 모드)
 *
 * 캐시 무효화 방법:
 * - 특정 도메인: Router::clearRouteCache('shop.example.com')
 * - 전체: Router::clearAllRouteCache()
 * - 인스턴스: $router->clearCache()
 *
 * ------------------------------------------------------------
 * [책임]
 * ------------------------------------------------------------
 *
 * - URL → Controller/Method 매핑
 * - 라우트 파라미터 추출
 * - 미들웨어 정보 전달
 *
 * ------------------------------------------------------------
 * [금지 사항]
 * ------------------------------------------------------------
 *
 * - Controller 실행 (Dispatcher의 역할)
 * - 인증/권한 검사 (Middleware의 역할)
 * - 비즈니스 로직
 * - HTML 출력
 *
 * ------------------------------------------------------------
 */
class Router
{
    /**
     * 라우트 캐시 디렉토리 경로
     *
     * 도메인별 캐시 파일이 저장될 디렉토리
     * 자동 생성됨
     */
    private const CACHE_DIR = MUBLO_STORAGE_PATH . '/cache/routes';

    /**
     * Admin autoResolve에서 GET/HEAD로 노출할 수 있는 조회 action.
     *
     * 상태 변경 action은 이 목록에 넣지 않는다. 새 public Controller 메서드는
     * 명시적으로 검토해 추가하기 전까지 GET 엔드포인트로 자동 노출되지 않는다.
     */
    private const ADMIN_AUTO_RESOLVE_READ_ACTIONS = [
        'AdminPermissionsController' => ['index'],
        'AiSettingsController' => ['index', 'assetDetail'],
        'BlockKitController' => ['index', 'show', 'download'],
        'BlockEditorController' => [
            'index',
            'contexts',
            'rows',
            'rowData',
            'aiAssets',
            'aiAssetFile',
            'frameSkins',
            'frameStatus',
            'frameAiHistory',
            'aiRecord',
        ],
        'BlockPageController' => ['index', 'create', 'edit'],
        'BlockRowController' => ['index', 'create', 'edit', 'getContentItems', 'previewRow', 'revisions', 'deletedRevisions'],
        'DashboardController' => ['index'],
        'DomainsController' => ['index', 'create', 'edit'],
        'ExtensionsController' => ['index'],
        'GuideController' => ['index'],
        'MemberController' => ['index', 'create', 'edit'],
        'MemberFieldController' => ['index', 'create', 'edit'],
        'MemberLevelsController' => ['index', 'create', 'edit'],
        'PointController' => ['index', 'adjust'],
        'MenuController' => ['index', 'itemView'],
        'ProfileController' => ['index'],
        'SystemController' => ['index'],
        'PolicyController' => ['index', 'create', 'edit'],
        'SettingsController' => ['index'],
    ];

    /**
     * 캐시 사용 여부
     *
     * true: 프로덕션 모드 (캐시 활성화)
     *       - 라우트 정보를 파일에 캐시하여 성능 향상
     *       - 활성 확장 목록/routes.php 변경 시 시그니처가 바뀌어 자동 재생성
     *
     * false: 개발 모드 (캐시 비활성화)
     *        - 매 요청마다 라우트 재구성
     *        - routes.php 변경 즉시 반영
     */
    private bool $useCache;

    /**
     * 현재 요청의 도메인
     *
     * 캐시 파일 경로 결정에 사용
     * dispatch() 호출 시 Context에서 설정됨
     */
    private ?string $currentDomain = null;

    /**
     * DI 컨테이너
     *
     * ExtensionService 등 서비스 접근에 사용
     */
    private ?DependencyContainer $container = null;

    /**
     * Router 생성자
     *
     * 환경 변수를 확인하여 캐시 사용 여부를 결정한다.
     * APP_DEBUG=true이면 개발 모드로 캐시를 사용하지 않는다.
     *
     * @param DependencyContainer|null $container DI 컨테이너 (활성화된 확장 필터링용)
     */
    public function __construct(?DependencyContainer $container = null)
    {
        $this->container = $container;

        // ------------------------------------------------
        // APP_DEBUG 환경 변수 확인
        //
        // 'true' 문자열이면 개발 모드 → 캐시 비활성화
        // 그 외 (false, 미설정 등)면 프로덕션 → 캐시 활성화
        // ------------------------------------------------
        $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

        $this->useCache = !$isDebug;
    }

    /**
     * 도메인 확장 설정을 저장하기 전에 실제 routes.php를 같은 순서로 구성해 검증한다.
     *
     * 설치 경로가 분리된 테스트와 관리 도구에서도 재사용할 수 있도록 경로를 선택 인자로 받는다.
     * 런타임 방어는 별도로 유지하므로 FTP·DB 직접 변경도 전체 라우팅 장애로 이어지지 않는다.
     *
     * @param array{plugins?: array, packages?: array} $config
     * @return array{valid: bool, message: string}
     */
    public function validateExtensionConfiguration(
        array $config,
        ?string $pluginPath = null,
        ?string $packagePath = null
    ): array {
        $pluginPath ??= MUBLO_PLUGIN_PATH;
        $packagePath ??= MUBLO_PACKAGE_PATH;
        $enabledPlugins = array_values(array_filter(
            $config['plugins'] ?? [],
            static fn(mixed $name): bool => is_string($name) && $name !== ''
        ));
        $enabledPackages = array_values(array_filter(
            $config['packages'] ?? [],
            static fn(mixed $name): bool => is_string($name) && $name !== ''
        ));

        $collector = new RouteCollector(new Std(), new GroupCountBased());
        $registeredRoutes = [];

        try {
            $coreRoutes = new BufferedRouteCollector();
            $this->registerCoreRoutes($coreRoutes);
            $this->commitRoutes($collector, $coreRoutes->routes(), $registeredRoutes);

            $pluginDirs = glob(rtrim($pluginPath, '/\\') . '/*', GLOB_ONLYDIR) ?: [];
            sort($pluginDirs, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($pluginDirs as $dir) {
                $name = basename($dir);
                if (!$this->isExtensionEnabled($name, $enabledPlugins)) {
                    continue;
                }

                $this->registerConfiguredRouteFile(
                    $collector,
                    $registeredRoutes,
                    $dir . '/routes.php',
                    $this->buildRoutePrefix($name),
                    'plugin',
                    $name
                );
            }

            $packageDirs = glob(rtrim($packagePath, '/\\') . '/*', GLOB_ONLYDIR) ?: [];
            sort($packageDirs, SORT_NATURAL | SORT_FLAG_CASE);

            // 런타임과 동일하게 종속 Plugin의 정적 라우트를 부모 Package보다 먼저 등록한다.
            foreach ($packageDirs as $packageDir) {
                $package = basename($packageDir);
                $nestedPlugins = $this->discoverNestedPluginsForValidation(
                    $package,
                    $packageDir,
                    $packagePath
                );

                foreach ($nestedPlugins as $pluginName => $info) {
                    $nestedName = $package . '/' . $pluginName;
                    if (!$this->isExtensionEnabled($nestedName, $enabledPlugins)) {
                        continue;
                    }

                    $suffix = $pluginName;
                    if (str_starts_with($suffix, $package) && strlen($suffix) > strlen($package)) {
                        $suffix = substr($suffix, strlen($package));
                    }

                    $this->registerConfiguredRouteFile(
                        $collector,
                        $registeredRoutes,
                        $info['dir'] . '/routes.php',
                        $this->buildRoutePrefix($package) . '/' . $this->buildRoutePrefix($suffix),
                        'plugin',
                        $nestedName
                    );
                }
            }

            foreach ($packageDirs as $dir) {
                $name = basename($dir);
                if (!$this->isExtensionEnabled($name, $enabledPackages)) {
                    continue;
                }

                $this->registerConfiguredRouteFile(
                    $collector,
                    $registeredRoutes,
                    $dir . '/routes.php',
                    $this->buildRoutePrefix($name),
                    'package',
                    $name
                );
            }
        } catch (\Throwable $e) {
            return ['valid' => false, 'message' => $e->getMessage()];
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * 실제 설치 경로에서는 Package Provider의 발견 결과를 사용한다. 테스트·관리 도구가
     * 별도 경로를 넘긴 경우에만 표준 Plugins 디렉토리를 읽어 같은 검증을 수행한다.
     *
     * @return array<string, array{dir: string, providerClass: string}>
     */
    private function discoverNestedPluginsForValidation(
        string $package,
        string $packageDir,
        string $packagePath
    ): array {
        $configuredPath = rtrim(str_replace('\\', '/', $packagePath), '/');
        $defaultPath = rtrim(str_replace('\\', '/', MUBLO_PACKAGE_PATH), '/');

        if ($configuredPath === $defaultPath) {
            return NestedPlugin::discover($package);
        }

        $plugins = [];
        foreach (glob($packageDir . '/Plugins/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $plugins[basename($dir)] = [
                'dir' => $dir,
                'providerClass' => '',
            ];
        }

        return $plugins;
    }

    /**
     * 라우트 디스패치 (메인 진입점)
     *
     * HTTP 요청을 분석하여 실행할 Controller/Method 정보를 반환한다.
     *
     * ----------------------------------------------------
     * [처리 순서]
     * ----------------------------------------------------
     *
     * 1. Context에서 도메인 정보 추출
     * 2. FastRoute Dispatcher 생성 (도메인별 캐시 또는 실시간)
     * 3. 명시적 라우트 매칭 시도
     * 4. 매칭 성공 → Controller/Method/Params 반환
     * 5. 매칭 실패 → Admin 조회 allowlist 또는 CSRF 보호 method로 제한된 autoResolve
     * 6. Method Not Allowed → 예외 발생
     *
     * ----------------------------------------------------
     *
     * @param Request $request HTTP 요청 객체
     * @param Context $context 애플리케이션 컨텍스트
     * @return array{
     *   controller: class-string,  // Controller 클래스명 (FQCN)
     *   method: string,            // 실행할 메서드명
     *   params: array,             // URL 파라미터 (예: ['id' => 123])
     *   middleware: array          // 적용할 미들웨어 목록
     * }
     * @throws \RuntimeException 라우팅 실패 시
     */
    public function dispatch(Request $request, Context $context): array
    {
        // ==================================================
        // 1. 현재 도메인 설정 (캐시 파일 키)
        //
        // 캐시 파일 키는 '해석된 정식 도메인'을 쓴다. raw Host($context->getDomain())를
        // 그대로 쓰면, 서브도메인 폴백(demo.example.com → example.com)으로 아래
        // hasDomainInfo 게이트는 통과하면서도 raw Host 마다 별도 캐시 파일이 생겨
        // (공격자가 임의 서브도메인을 대량 전송) 게이트가 막으려던 디스크·inode 고갈이
        // 그대로 열린다. 정식 도메인으로 키를 좁혀 한 등록 도메인의 모든 Host 변형을
        // 하나의 캐시 파일로 합친다. 미해결(미등록) Host 는 캐시하지 않으므로 raw 폴백 무해.
        // ==================================================
        $domainInfo = $context->getDomainInfo();
        $this->currentDomain = $domainInfo !== null
            ? $domainInfo->getDomain()
            : ($context->getDomain() ?? 'default');

        // ==================================================
        // 2. FastRoute Dispatcher 생성
        //
        // 프로덕션: cachedDispatcher 사용 (도메인별 캐시)
        //          - 캐시 파일이 있으면 로드
        //          - 없으면 생성 후 캐시
        //
        // 개발: simpleDispatcher 사용
        //       - 매번 라우트 테이블 재구성
        //       - routes.php 변경 즉시 반영
        // ==================================================
        $dispatcher = $this->createDispatcher($context);

        // ==================================================
        // 3. FastRoute 라우트 매칭 실행
        //
        // dispatch()는 다음 중 하나를 반환:
        // - [FOUND, handler, params]
        // - [NOT_FOUND]
        // - [METHOD_NOT_ALLOWED, allowedMethods]
        // ==================================================
        // Trailing slash 정규화: /products/ → /products (루트 '/' 제외)
        $path = $request->getPath();
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $routeInfo = $dispatcher->dispatch(
            $request->getMethod(),
            $path
        );

        // ==================================================
        // 4. FastRoute 매칭 성공
        //
        // 명시적 라우트에서 찾음
        // handler 배열에서 controller, method, middleware 추출
        // ==================================================
        if ($routeInfo[0] === FastRouteDispatcher::FOUND) {
            return [
                'controller' => $routeInfo[1]['controller'],
                'method'     => $routeInfo[1]['method'],
                'params'     => array_merge($routeInfo[1]['defaults'] ?? [], $routeInfo[2] ?? []),
                'middleware' => $routeInfo[1]['middleware'] ?? [],
            ];
        }

        // ==================================================
        // 5. FastRoute 미매칭 → 제한된 자동 매핑 (Admin 영역 한정)
        //
        // 명시적 라우트에 없는 URL을 Controller/Method로 자동 결정하되,
        // GET/HEAD는 Controller별 조회 action allowlist만 허용한다.
        // 단, Admin 영역만 허용한다 — autoResolve는 미들웨어를 라우트별로
        // 부여하지 못하므로(Front=[], Admin=AdminMiddleware), Front까지 허용하면
        // 명시 라우트의 AuthMiddleware가 비정규 경로(예: 대소문자 변형)로 우회되고,
        // 의도치 않은 public 메서드가 미인증 엔드포인트로 노출된다.
        // Front 엔드포인트는 전부 명시 라우트로 등록되어 있으므로 autoResolve 불필요.
        // ==================================================
        if ($routeInfo[0] === FastRouteDispatcher::NOT_FOUND) {
            // 정적 에셋 경로는 PHP가 처리하지 않음
            // ErrorDocument 404 /index.php 설정으로 유입된 경우 조용히 404 반환
            $path = $request->getPath();
            if (str_starts_with($path, '/assets/') || str_starts_with($path, '/serve/')) {
                throw new HttpNotFoundException('Not Found');
            }

            if ($context->isAdmin()) {
                return $this->autoResolve($path, $context, $request->getMethod());
            }

            // Front: 명시 라우트에 없으면 404 (자동 노출 차단)
            throw new HttpNotFoundException('Not Found');
        }

        // ==================================================
        // 6. Method Not Allowed
        //
        // URL은 존재하지만 HTTP Method가 맞지 않음
        // 예: POST /login 라우트만 있는데 GET /login 요청
        // ==================================================
        if ($routeInfo[0] === FastRouteDispatcher::METHOD_NOT_ALLOWED) {
            throw new \RuntimeException('405 Method Not Allowed');
        }

        // ==================================================
        // 7. 예상치 못한 라우팅 결과
        // ==================================================
        throw new \RuntimeException('Routing error');
    }

    /**
     * FastRoute Dispatcher 생성
     *
     * 환경과 도메인에 따라 적절한 Dispatcher를 생성하여 반환한다.
     *
     * ----------------------------------------------------
     * [프로덕션 모드 (useCache = true)]
     * ----------------------------------------------------
     *
     * cachedDispatcher를 사용하여 라우트 정보를 도메인별로 캐시한다.
     *
     * 캐시 파일: storage/cache/routes/{domain}.{signature}.cache.php
     *
     * 1. 캐시 파일 존재 시:
     *    - 파일에서 라우트 데이터 로드 (매우 빠름)
     *    - routes.php 파싱 없음
     *    - 정규식 컴파일 없음
     *
     * 2. 캐시 파일 미존재 시:
     *    - 모든 라우트 정의 실행
     *    - 결과를 캐시 파일에 저장
     *    - 다음 요청부터 캐시 사용
     *
     * ----------------------------------------------------
     * [개발 모드 (useCache = false)]
     * ----------------------------------------------------
     *
     * simpleDispatcher를 사용하여 매번 라우트를 구성한다.
     *
     * - routes.php 변경 즉시 반영
     * - 디버깅 용이
     * - 성능은 다소 떨어짐
     *
     * ----------------------------------------------------
     *
     * @param Context $context 애플리케이션 컨텍스트
     * @return FastRouteDispatcher
     */
    private function createDispatcher(Context $context): FastRouteDispatcher
    {
        // ------------------------------------------------
        // 라우트 정의 콜백
        //
        // 이 콜백 안에서 모든 라우트가 정의된다:
        // - Core 라우트 (Front, Admin)
        // - Plugin 라우트 (도메인별 활성화된 것만)
        // - Package 라우트 (도메인별 활성화된 것만)
        // ------------------------------------------------
        $routeDefinitionCallback = function (RouteCollector $r) use ($context) {
            /** @var array<int, array{method: string|string[], route: string, handler: mixed}> $registeredRoutes */
            $registeredRoutes = [];

            // Core도 같은 선언 목록에 기록해 확장이 Core 라우트를 침범하는지 검증한다.
            $coreRoutes = new BufferedRouteCollector();
            $this->registerCoreRoutes($coreRoutes);
            $this->commitRoutes($r, $coreRoutes->routes(), $registeredRoutes);

            $this->loadPluginRoutes($r, $context, $registeredRoutes);
            $this->loadPackageRoutes($r, $context, $registeredRoutes);
        };

        // ------------------------------------------------
        // 프로덕션 모드: cachedDispatcher 사용 (도메인별)
        //
        // 캐시 파일은 '등록된 도메인'에 대해서만 생성한다. currentDomain 은 임의 조작 가능한
        // Host 헤더에서 오고, /serve·/csrf 같은 public API 경로는 도메인 검증을 건너뛰므로,
        // 미등록 Host 로도 라우터까지 도달한다. 도메인 화이트리스트(hasDomainInfo) 없이 캐시하면
        // 공격자가 임의 Host 를 대량 전송해 도메인별 캐시 파일을 무한 생성(디스크·inode 고갈)할 수
        // 있다. 미등록 도메인은 캐시하지 않고 매 요청 라우트 테이블을 구성한다(정상 트래픽 아님).
        // ------------------------------------------------
        if ($this->useCache && $context->hasDomainInfo()) {
            // 캐시 디렉토리 확인 및 생성
            $this->ensureCacheDirectoryExists();

            // 도메인별 + 활성 확장/routes.php 시그니처 기반 캐시 파일 경로
            $cacheFile = $this->getCacheFilePath($context);

            // 오래된 캐시 파일 정리
            $this->invalidateStaleCacheFile($cacheFile);

            // 이 시그니처 캐시가 아직 없다 = 곧 새로 생성됨(활성 확장/라우트 변경 후 첫 요청).
            // 이때만 같은 도메인의 '구 시그니처' 형제 파일을 정리한다 — 매 요청 glob 비용을
            // 피하면서, 참조되지 않아 영영 안 지워지는 낡은 캐시의 누적을 막는다.
            if (!file_exists($cacheFile)) {
                $this->purgeSupersededCacheFiles($cacheFile);
            }

            return cachedDispatcher($routeDefinitionCallback, [
                'cacheFile' => $cacheFile,
                'cacheDisabled' => false,
            ]);
        }

        // ------------------------------------------------
        // 개발 모드: simpleDispatcher 사용
        //
        // 매 요청마다 라우트 테이블을 새로 구성
        // routes.php 수정 시 즉시 반영됨
        // ------------------------------------------------
        return simpleDispatcher($routeDefinitionCallback);
    }

    /**
     * 도메인별 캐시 파일 경로 반환
     *
     * 도메인명에서 파일시스템에 안전한 이름을 생성한다.
     * 특수문자는 유지하되, 경로 구분자가 될 수 있는 문자만 치환
     *
     * @return string 캐시 파일 전체 경로
     */
    private function getCacheFilePath(?Context $context = null): string
    {
        // ------------------------------------------------
        // 도메인명을 파일명으로 변환
        //
        // shop.example.com → shop.example.com.cache.php
        // localhost:8080 → localhost_8080.cache.php
        //
        // 파일시스템에서 문제가 될 수 있는 문자 치환:
        // - : (포트 구분자) → _
        // - / (경로 구분자) → _ (혹시 모를 상황 대비)
        // ------------------------------------------------
        $safeDomain = str_replace([':', '/'], '_', $this->currentDomain);
        $signature = $context !== null ? $this->buildRouteCacheSignature($context) : 'legacy';

        return self::CACHE_DIR . '/' . $safeDomain . '.' . $signature . '.cache.php';
    }

    /**
     * 활성 확장 목록, routes.php 수정 시간, 코어 버전을 반영한 캐시 시그니처.
     */
    private function buildRouteCacheSignature(Context $context): string
    {
        $enabledPlugins = $this->getEnabledPluginNames($context);
        $enabledPackages = $this->getEnabledPackageNames($context);
        $signaturePlugins = $enabledPlugins ?? ['*'];
        $signaturePackages = $enabledPackages ?? ['*'];
        sort($signaturePlugins);
        sort($signaturePackages);

        $routeFiles = [];

        foreach ($signaturePlugins as $pluginName) {
            if ($pluginName === '*') {
                foreach (glob(MUBLO_PLUGIN_PATH . '/*/routes.php') ?: [] as $file) {
                    $routeFiles[] = $file;
                }
                // 패키지 종속 플러그인 — 호스트 패키지의 발견 응답 기준
                foreach (glob(MUBLO_PACKAGE_PATH . '/*', GLOB_ONLYDIR) ?: [] as $packageDir) {
                    foreach (NestedPlugin::discover(basename($packageDir)) as $info) {
                        if (is_file($info['dir'] . '/routes.php')) {
                            $routeFiles[] = $info['dir'] . '/routes.php';
                        }
                    }
                }
                continue;
            }

            $file = NestedPlugin::isNested($pluginName)
                ? (NestedPlugin::dir($pluginName) ?? '') . '/routes.php'
                : MUBLO_PLUGIN_PATH . '/' . $pluginName . '/routes.php';
            if (is_file($file)) {
                $routeFiles[] = $file;
            }
        }

        foreach ($signaturePackages as $packageName) {
            if ($packageName === '*') {
                foreach (glob(MUBLO_PACKAGE_PATH . '/*/routes.php') ?: [] as $file) {
                    $routeFiles[] = $file;
                }
                continue;
            }

            $file = MUBLO_PACKAGE_PATH . '/' . $packageName . '/routes.php';
            if (is_file($file)) {
                $routeFiles[] = $file;
            }
        }

        sort($routeFiles);
        $routeStats = array_map(
            fn (string $file): string => $file . ':' . (filemtime($file) ?: 0),
            $routeFiles
        );

        return substr(sha1(json_encode([
            'version' => Application::VERSION,
            'plugins' => $signaturePlugins,
            'packages' => $signaturePackages,
            'routes' => $routeStats,
        ], JSON_UNESCAPED_SLASHES)), 0, 12);
    }

    /**
     * Core 라우트 등록
     *
     * 프레임워크 기본 라우트를 정의한다.
     * Plugin/Package 라우트보다 먼저 등록되어 우선순위가 높다.
     *
     * ----------------------------------------------------
     * [라우트 그룹]
     * ----------------------------------------------------
     *
     * 1. Front 라우트: 사용자 페이지
     *    - 메인 페이지, 게시판 등
     *
     * 2. Admin 라우트: 관리자 페이지
     *    - 로그인, 대시보드 등
     *    - AdminMiddleware 적용
     *
     * 3. API 라우트: 시스템 API
     *    - CSRF 토큰, 정적 파일 서빙 등
     *
     * ----------------------------------------------------
     *
     * @param RouteCollector $r FastRoute RouteCollector
     */
    private function registerCoreRoutes(RouteCollector $r): void
    {
        // =============================================
        // Front 라우트 - 사용자 영역
        // =============================================

        $authMiddleware = [\Mublo\Core\Middleware\AuthMiddleware::class];

        // robots.txt (도메인 설정 우선, 없으면 public/robots.txt 폴백)
        $r->addRoute('GET', '/robots.txt', [
            'controller' => \Mublo\Controller\Front\RobotsController::class,
            'method'     => 'index',
            'middleware' => [],
        ]);

        // sitemap.xml (메뉴 + 블록페이지 + 확장 Provider 기여분)
        $r->addRoute('GET', '/sitemap.xml', [
            'controller' => \Mublo\Controller\Front\SitemapController::class,
            'method'     => 'index',
            'middleware' => [],
        ]);

        // 메인 페이지
        $r->addRoute('GET', '/', [
            'controller' => \Mublo\Controller\Front\IndexController::class,
            'method'     => 'index',
            'middleware' => [],
        ]);

        // 전체 검색
        $r->addRoute('GET', '/search', [
            'controller' => \Mublo\Controller\Front\SearchController::class,
            'method'     => 'index',
            'middleware' => [],
        ]);

        // --------------------------------------------
        // 블록 페이지 라우트
        //
        // {code}: 페이지 코드 (영소문자, 숫자, 하이픈)
        // --------------------------------------------
        $r->addRoute('GET', '/p/{code}', [
            'controller' => \Mublo\Controller\Front\PageController::class,
            'method'     => 'view',
            'middleware' => [],
        ]);

        // --------------------------------------------
        // 약관/정책 열람 라우트
        // --------------------------------------------
        $r->addRoute('GET', '/policy/view/{slug}', [
            'controller' => \Mublo\Controller\Front\PolicyController::class,
            'method'     => 'view',
            'middleware' => [],
        ]);
        $r->addRoute('GET', '/terms', [
            'controller' => \Mublo\Controller\Front\PolicyController::class,
            'method'     => 'view',
            'defaults'   => ['slug' => 'terms'],
            'middleware' => [],
        ]);
        $r->addRoute('GET', '/privacy', [
            'controller' => \Mublo\Controller\Front\PolicyController::class,
            'method'     => 'view',
            'defaults'   => ['slug' => 'privacy'],
            'middleware' => [],
        ]);

        // --------------------------------------------
        // 회원 라우트
        //
        // 회원가입 3단계: 약관동의 → 정보입력 → 가입완료
        // --------------------------------------------
        $r->addRoute('GET', '/member/register', [
            'controller' => \Mublo\Controller\Front\MemberController::class,
            'method'     => 'registerAgree',
            'middleware' => [],
        ]);
        $r->addRoute('POST', '/member/register/agree', [
            'controller' => \Mublo\Controller\Front\MemberController::class,
            'method'     => 'registerAgreeProcess',
            'middleware' => [],
        ]);
        $r->addRoute('GET', '/member/register/form', [
            'controller' => \Mublo\Controller\Front\MemberController::class,
            'method'     => 'registerForm',
            'middleware' => [],
        ]);
        $r->addRoute('POST', '/member/register/form', [
            'controller' => \Mublo\Controller\Front\MemberController::class,
            'method'     => 'register',
            'middleware' => [],
        ]);
        $r->addRoute('GET', '/member/register/complete', [
            'controller' => \Mublo\Controller\Front\MemberController::class,
            'method'     => 'registerComplete',
            'middleware' => [],
        ]);
        $r->addRoute('GET', '/member/register/pending', [
            'controller' => \Mublo\Controller\Front\MemberController::class,
            'method'     => 'registerPending',
            'middleware' => [],
        ]);
        $r->addRoute('POST', '/member/check-userid', [
            'controller' => \Mublo\Controller\Front\MemberController::class,
            'method'     => 'checkUserId',
            'middleware' => [],
        ]);
        $r->addRoute('POST', '/member/check-nickname', [
            'controller' => \Mublo\Controller\Front\MemberController::class,
            'method'     => 'checkNickname',
            'middleware' => [],
        ]);
        $r->addRoute('POST', '/member/upload-field-file', [
            'controller' => \Mublo\Controller\Front\MemberController::class,
            'method'     => 'uploadFieldFile',
            'middleware' => [],
        ]);

        // --------------------------------------------
        // 보안 파일 다운로드
        // --------------------------------------------
        $r->addRoute('GET', '/download/{token}', [
            'controller' => \Mublo\Controller\Api\DownloadController::class,
            'method'     => 'download',
            'middleware' => [],
        ]);

        // --------------------------------------------
        // 프론트 마이페이지 라우트 (로그인 필수)
        // --------------------------------------------
        $r->addRoute('GET', '/mypage', [
            'controller' => \Mublo\Controller\Front\MypageController::class,
            'method'     => 'index',
            'middleware' => $authMiddleware,
        ]);
        $r->addRoute('GET', '/mypage/profile', [
            'controller' => \Mublo\Controller\Front\MypageController::class,
            'method'     => 'profile',
            'middleware' => $authMiddleware,
        ]);
        $r->addRoute('POST', '/mypage/profile', [
            'controller' => \Mublo\Controller\Front\MypageController::class,
            'method'     => 'updateProfile',
            'middleware' => $authMiddleware,
        ]);
        $r->addRoute('GET', '/mypage/balance', [
            'controller' => \Mublo\Controller\Front\MypageController::class,
            'method'     => 'balance',
            'middleware' => $authMiddleware,
        ]);
        $r->addRoute('GET', '/mypage/notifications', [
            'controller' => \Mublo\Controller\Front\MypageController::class,
            'method'     => 'notifications',
            'middleware' => $authMiddleware,
        ]);
        $r->addRoute('POST', '/mypage/notifications/open', [
            'controller' => \Mublo\Controller\Front\MypageController::class,
            'method'     => 'openNotification',
            'middleware' => $authMiddleware,
        ]);
        $r->addRoute('POST', '/mypage/notifications/read-all', [
            'controller' => \Mublo\Controller\Front\MypageController::class,
            'method'     => 'markAllNotificationsRead',
            'middleware' => $authMiddleware,
        ]);
        // /mypage/articles, /mypage/comments → Board 패키지 마이페이지 섹션으로 이관
        // (/mypage/board/articles, /mypage/board/comments — 제네릭 라우트가 처리)
        $r->addRoute('GET', '/mypage/withdraw', [
            'controller' => \Mublo\Controller\Front\MypageController::class,
            'method'     => 'withdraw',
            'middleware' => $authMiddleware,
        ]);
        $r->addRoute('POST', '/mypage/withdraw', [
            'controller' => \Mublo\Controller\Front\MypageController::class,
            'method'     => 'withdraw',
            'middleware' => $authMiddleware,
        ]);
        // 패키지 마이페이지 허브 (정적 /mypage/* 보다 뒤 → 정적/예약섹션 우선 매칭).
        // /mypage/{provider} → registerHub로 등록된 요약 허브 렌더. 예: /mypage/shop(마이쇼핑).
        $r->addRoute('GET', '/mypage/{provider}', [
            'controller' => \Mublo\Controller\Front\MypageSectionController::class,
            'method'     => 'hub',
            'middleware' => $authMiddleware,
        ]);
        // 패키지 섹션 제네릭 라우트 (정적 /mypage/* 보다 뒤 → 정적 우선 매칭).
        // /mypage/{provider}/{section} → MypageSectionRegistry 조회 후 셸+partial 렌더.
        $r->addRoute('GET', '/mypage/{provider}/{section}', [
            'controller' => \Mublo\Controller\Front\MypageSectionController::class,
            'method'     => 'view',
            'middleware' => $authMiddleware,
        ]);

        // --------------------------------------------
        // 프론트 인증 라우트
        //
        // 로그인/로그아웃 (인증 불필요)
        // --------------------------------------------
        $r->addRoute('GET', '/login', [
            'controller' => \Mublo\Controller\Front\AuthController::class,
            'method'     => 'loginForm',
            'middleware' => [],
        ]);
        $r->addRoute('POST', '/login', [
            'controller' => \Mublo\Controller\Front\AuthController::class,
            'method'     => 'login',
            'middleware' => [],
        ]);
        $r->addRoute(['GET', 'POST'], '/logout', [
            'controller' => \Mublo\Controller\Front\AuthController::class,
            'method'     => 'logout',
            'middleware' => [],
        ]);
        $r->addRoute('GET', '/find-account', [
            'controller' => \Mublo\Controller\Front\AuthController::class,
            'method'     => 'findAccountForm',
            'middleware' => [],
        ]);
        $r->addRoute('POST', '/find-account/find-userid', [
            'controller' => \Mublo\Controller\Front\AuthController::class,
            'method'     => 'findUserId',
            'middleware' => [],
        ]);
        $r->addRoute('POST', '/find-account/request-reset', [
            'controller' => \Mublo\Controller\Front\AuthController::class,
            'method'     => 'requestReset',
            'middleware' => [],
        ]);
        $r->addRoute('GET', '/find-account/reset-password', [
            'controller' => \Mublo\Controller\Front\AuthController::class,
            'method'     => 'resetPasswordForm',
            'middleware' => [],
        ]);
        $r->addRoute('POST', '/find-account/reset-password', [
            'controller' => \Mublo\Controller\Front\AuthController::class,
            'method'     => 'resetPassword',
            'middleware' => [],
        ]);

        // =============================================
        // Admin 라우트 - 관리자 영역
        // =============================================

        // --------------------------------------------
        // 관리자 인증 라우트
        //
        // 로그인/로그아웃은 인증 미들웨어 적용 안 함
        // (비로그인 상태에서 접근해야 하므로)
        // --------------------------------------------
        $r->addRoute('GET', '/admin/login', [
            'controller' => \Mublo\Controller\Admin\AuthController::class,
            'method'     => 'loginForm',
            'middleware' => [],  // 인증 불필요
        ]);

        $r->addRoute('POST', '/admin/login', [
            'controller' => \Mublo\Controller\Admin\AuthController::class,
            'method'     => 'login',
            'middleware' => [],  // 인증 불필요
        ]);

        $r->addRoute('POST', '/admin/logout', [
            'controller' => \Mublo\Controller\Admin\AuthController::class,
            'method'     => 'logout',
            'middleware' => [],  // 인증 불필요 (로그아웃 처리)
        ]);

        // 대리 로그인 (상위 관리자 → 하위 도메인)
        $r->addRoute('GET', '/admin/proxy-login', [
            'controller' => \Mublo\Controller\Admin\AuthController::class,
            'method'     => 'proxyLoginVerify',
            'middleware' => [],  // 인증 불필요 (토큰 기반 인증)
        ]);

        // --------------------------------------------
        // 관리자 대시보드 (루트 경로)
        //
        // /admin → /admin/dashboard 와 동일하게 처리
        // 이 외 Admin 라우트는 HTTP method 제한이 적용된 autoResolve에서 처리
        // --------------------------------------------
        $r->addRoute('GET', '/admin', [
            'controller' => \Mublo\Controller\Admin\DashboardController::class,
            'method'     => 'index',
            'middleware' => [\Mublo\Core\Middleware\AdminMiddleware::class],
        ]);

        // --------------------------------------------
        // 관리자 대시보드 API
        // --------------------------------------------
        $adminMiddleware = [\Mublo\Core\Middleware\AdminMiddleware::class];

        $r->addRoute('POST', '/admin/dashboard/widget/hide', [
            'controller' => \Mublo\Controller\Admin\DashboardController::class,
            'method'     => 'hideWidget',
            'middleware' => $adminMiddleware,
        ]);

        $r->addRoute('POST', '/admin/dashboard/widget/show', [
            'controller' => \Mublo\Controller\Admin\DashboardController::class,
            'method'     => 'showWidget',
            'middleware' => $adminMiddleware,
        ]);

        $r->addRoute('POST', '/admin/dashboard/widget/move', [
            'controller' => \Mublo\Controller\Admin\DashboardController::class,
            'method'     => 'moveWidget',
            'middleware' => $adminMiddleware,
        ]);

        $r->addRoute('POST', '/admin/dashboard/layout/reset', [
            'controller' => \Mublo\Controller\Admin\DashboardController::class,
            'method'     => 'resetLayout',
            'middleware' => $adminMiddleware,
        ]);

        $r->addRoute('POST', '/admin/dashboard/layout/reorder', [
            'controller' => \Mublo\Controller\Admin\DashboardController::class,
            'method'     => 'reorderWidgets',
            'middleware' => $adminMiddleware,
        ]);

        // --------------------------------------------
        // 관리자 리포트 API
        // --------------------------------------------

        $r->addRoute('POST', '/admin/report/{reportName}/download', [
            'controller' => \Mublo\Controller\Admin\ReportController::class,
            'method'     => 'download',
            'middleware' => $adminMiddleware,
        ]);

        $r->addRoute('POST', '/admin/report/{reportName}/chunks', [
            'controller' => \Mublo\Controller\Admin\ReportController::class,
            'method'     => 'chunks',
            'middleware' => $adminMiddleware,
        ]);

        $r->addRoute('POST', '/admin/report/{reportName}/merge', [
            'controller' => \Mublo\Controller\Admin\ReportController::class,
            'method'     => 'merge',
            'middleware' => $adminMiddleware,
        ]);

        $r->addRoute('GET', '/admin/report/files/{fileId}', [
            'controller' => \Mublo\Controller\Admin\ReportController::class,
            'method'     => 'file',
            'middleware' => $adminMiddleware,
        ]);

        // SUPER 관리자 데이터베이스 백업 — 자동 라우팅에 맡기지 않고 POST로 제한한다.
        $r->addRoute('POST', '/admin/system/backup-database', [
            'controller' => \Mublo\Controller\Admin\SystemController::class,
            'method'     => 'backupDatabase',
            'middleware' => $adminMiddleware,
        ]);

        // =============================================
        // API 라우트 - 시스템 API
        // =============================================

        // --------------------------------------------
        // CSRF 토큰 API (v1)
        //
        // MubloRequest.js에서 AJAX 요청 시 사용
        // API 버전 관리: /api/v1/csrf/...
        // --------------------------------------------
        $r->addRoute('GET', '/api/v1/csrf/token', [
            'controller' => \Mublo\Controller\Api\CsrfController::class,
            'method'     => 'token',
            'middleware' => [],
        ]);

        $r->addRoute('POST', '/api/v1/csrf/regenerate', [
            'controller' => \Mublo\Controller\Api\CsrfController::class,
            'method'     => 'regenerate',
            'middleware' => [],
        ]);

        // --------------------------------------------
        // 도메인 도달 확인(프로브) 응답
        //
        // 호스트명 변경 전 검증에서 서버가 "새 호스트명"으로 자신에게 요청을 보낸다.
        // 그 호스트는 아직 등록 전이라 도메인 검증을 우회한다
        // (Application::isPublicApiRequest 참조). 발급된 nonce가 없으면 404.
        // --------------------------------------------
        $r->addRoute('GET', \Mublo\Service\Domain\DomainVerificationService::PROBE_PATH, [
            'controller' => \Mublo\Controller\Api\DomainVerifyController::class,
            'method'     => 'verify',
            'middleware' => [],
        ]);

        // --------------------------------------------
        // 에디터 이미지 업로드 API
        //
        // MubloEditor 및 호환 에디터에서 본문 이미지 업로드
        // CSRF 미들웨어를 경유하므로 X-CSRF-Token 헤더 필요
        // --------------------------------------------
        $r->addRoute('POST', '/api/v1/editor/upload', [
            'controller' => \Mublo\Controller\Api\EditorUploadController::class,
            'method'     => 'upload',
            'middleware' => [],
        ]);

        // --------------------------------------------
        // 정적 파일 서빙 (ServeController)
        //
        // Plugin, Package, Views의 정적 파일 제공
        // {name}: Plugin/Package 이름
        // {path:.+}: 파일 경로 (슬래시 포함 허용)
        // --------------------------------------------
        $r->addRoute('GET', '/serve/package/{name}/{path:.+}', [
            'controller' => \Mublo\Controller\Api\ServeController::class,
            'method'     => 'package',
            'middleware' => [],
        ]);

        $r->addRoute('GET', '/serve/plugin/{name}/{path:.+}', [
            'controller' => \Mublo\Controller\Api\ServeController::class,
            'method'     => 'plugin',
            'middleware' => [],
        ]);

        // Block 스킨 에셋 서빙
        // /serve/block/{type}/{skin}/{path} → views/Block/{type}/{skin}/{path}
        $r->addRoute('GET', '/serve/block/{type}/{skin}/{path:.+}', [
            'controller' => \Mublo\Controller\Api\ServeController::class,
            'method'     => 'blockSkinAsset',
            'middleware' => [],
        ]);

        $r->addRoute('GET', '/serve/views/admin/{path:.+}', [
            'controller' => \Mublo\Controller\Api\ServeController::class,
            'method'     => 'viewsAdmin',
            'middleware' => [],
        ]);

        // Admin 스킨 에셋 서빙
        // /serve/admin/{skin}/{path} → views/Admin/{skin}/_assets/{path}
        $r->addRoute('GET', '/serve/admin/{skin}/{path:.+}', [
            'controller' => \Mublo\Controller\Api\ServeController::class,
            'method'     => 'adminSkinAsset',
            'middleware' => [],
        ]);

        // Front Content View 스킨 에셋 서빙
        // /serve/front/view/{group}/{skin}/{path} → views/Front/{Group}/{skin}/_assets/{path}
        $r->addRoute('GET', '/serve/front/view/{group}/{skin}/{path:.+}', [
            'controller' => \Mublo\Controller\Api\ServeController::class,
            'method'     => 'frontViewSkinAsset',
            'middleware' => [],
        ]);

        // Front 프레임 스킨 에셋 서빙
        // /serve/front/{skin}/{path} → views/Front/frame/{skin}/_assets/{path}
        $r->addRoute('GET', '/serve/front/{skin}/{path:.+}', [
            'controller' => \Mublo\Controller\Api\ServeController::class,
            'method'     => 'frontSkinAsset',
            'middleware' => [],
        ]);

        $r->addRoute('GET', '/serve/views/front/{path:.+}', [
            'controller' => \Mublo\Controller\Api\ServeController::class,
            'method'     => 'viewsFront',
            'middleware' => [],
        ]);
    }

    /**
     * Plugin routes.php 로드
     *
     * 각 Plugin 디렉토리의 routes.php를 찾아 로드한다.
     * routes.php는 PrefixedRouteCollector를 받는 콜백을 반환해야 한다.
     *
     * ----------------------------------------------------
     * [도메인별 활성화 체크]
     * ----------------------------------------------------
     *
     * ExtensionService를 통해 도메인별 활성화된 Plugin만 로드한다.
     * 비활성화된 Plugin의 라우트는 등록되지 않는다.
     *
     * ----------------------------------------------------
     * [URL 접두사 자동 적용]
     * ----------------------------------------------------
     *
     * PrefixedRouteCollector를 통해 URL 접두사가 자동 적용된다.
     *
     * - Front: /{plugin_name}/...
     * - Admin: /admin/{plugin_name}/...
     *
     * 예) MemberPoint 플러그인:
     *     /history      → /memberpoint/history
     *     /admin/list   → /admin/memberpoint/list
     *
     * ----------------------------------------------------
     * [routes.php 형식]
     * ----------------------------------------------------
     *
     * ```php
     * return function (PrefixedRouteCollector $r): void {
     *     $r->addRoute('GET', '/history', [...]);
     *     $r->addRoute('GET', '/admin/list', [...]);
     * };
     * ```
     *
     * ----------------------------------------------------
     *
     * @param RouteCollector $r FastRoute RouteCollector
     * @param Context $context 애플리케이션 컨텍스트
     */
    private function loadPluginRoutes(RouteCollector $r, Context $context, array &$registeredRoutes): void
    {
        $pluginDir = MUBLO_PLUGIN_PATH;

        // Plugin 디렉토리가 없으면 스킵
        if (!is_dir($pluginDir)) {
            return;
        }

        // ------------------------------------------------
        // 도메인별 활성화된 Plugin 목록 조회
        // ------------------------------------------------
        $enabledPlugins = $this->getEnabledPluginNames($context);

        // 모든 Plugin 디렉토리 탐색
        $plugins = glob($pluginDir . '/*', GLOB_ONLYDIR);

        foreach ($plugins as $pluginPath) {
            $pluginName = basename($pluginPath);

            // ----------------------------------------
            // Plugin 활성화 체크
            //
            // 비활성화된 Plugin은 스킵
            // (enabledPlugins가 null이면 전체 로드 - 컨테이너 없는 경우)
            // ----------------------------------------
            if ($enabledPlugins !== null && !$this->isExtensionEnabled($pluginName, $enabledPlugins)) {
                continue;
            }

            // super_only 플러그인: 하위 도메인에서 라우트 차단
            if ($this->isSuperOnlyPluginOnSubSite($pluginPath, $context)) {
                continue;
            }

            $routesFile = $pluginPath . '/routes.php';

            // routes.php가 있는 Plugin만 처리
            if (!file_exists($routesFile)) {
                continue;
            }

            // ----------------------------------------
            // Plugin 이름에서 URL 접두사 생성
            //
            // 디렉토리명: MemberPoint
            // 접두사: memberpoint (소문자)
            // ----------------------------------------
            $prefix = $this->buildRoutePrefix($pluginName);

            // ----------------------------------------
            // routes.php 로드
            //
            // 콜백 함수를 반환해야 함
            // function(PrefixedRouteCollector $r): void
            // ----------------------------------------
            $this->loadExtensionRouteFile(
                $r,
                $registeredRoutes,
                $routesFile,
                $prefix,
                'plugin',
                $pluginName
            );
        }

        // ------------------------------------------------
        // 패키지 종속 플러그인
        //
        // 코어는 패키지 내부를 스캔하지 않는다 — PluginHostInterface 를
        // 구현한 패키지에게 목록을 묻는다(NestedPlugin::discover).
        // 활성 목록에는 "{Pkg}/{Name}" 으로 저장되고, URL 은 부모 패키지에
        // 종속된다: /{패키지}/{플러그인}/... 형태. 플러그인 이름이 패키지
        // 이름으로 시작하면 그 부분은 URL 에서 접는다.
        //   Board/BoardReport → /board/report/..., /admin/board/report/...
        // (정적 경로가 변수 경로보다 우선이므로 /board/{board_id} 와 공존한다.)
        // ------------------------------------------------
        foreach (glob(MUBLO_PACKAGE_PATH . '/*', GLOB_ONLYDIR) ?: [] as $packageDir) {
            $package = basename($packageDir);

            foreach (NestedPlugin::discover($package) as $pluginName => $info) {
                $nestedName = $package . '/' . $pluginName;

                if ($enabledPlugins !== null && !$this->isExtensionEnabled($nestedName, $enabledPlugins)) {
                    continue;
                }

                if ($this->isSuperOnlyPluginOnSubSite($info['dir'], $context)) {
                    continue;
                }

                $routesFile = $info['dir'] . '/routes.php';
                if (!file_exists($routesFile)) {
                    continue;
                }

                $suffix = $pluginName;
                if (str_starts_with($suffix, $package) && strlen($suffix) > strlen($package)) {
                    $suffix = substr($suffix, strlen($package));
                }
                $prefix = $this->buildRoutePrefix($package) . '/' . $this->buildRoutePrefix($suffix);

                $this->loadExtensionRouteFile(
                    $r,
                    $registeredRoutes,
                    $routesFile,
                    $prefix,
                    'plugin',
                    $nestedName
                );
            }
        }
    }

    /**
     * super_only 플러그인이 하위 도메인에서 접근되는지 확인
     *
     * super_only 플러그인은 SUPER 도메인에서만 라우트 접근 허용
     */
    private function isSuperOnlyPluginOnSubSite(string $pluginPath, Context $context): bool
    {
        $manifestFile = $pluginPath . '/manifest.json';
        if (!file_exists($manifestFile)) {
            return false;
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (empty($manifest['super_only'])) {
            return false;
        }

        // SUPER 도메인이면 허용
        $domainId = $context->getDomainId();
        $group = $context->getDomainGroup();

        if ($domainId === null || $group === null || $group === '') {
            return false;
        }

        $rootId = (int) explode('/', $group)[0];
        return $rootId > 0 && $rootId !== $domainId;
    }

    /**
     * 활성화된 Plugin 이름 목록 조회
     *
     * @param Context $context
     * @return array|null 활성화된 플러그인 이름 배열, 컨테이너가 없으면 null
     */
    private function getEnabledPluginNames(Context $context): ?array
    {
        if ($this->container === null) {
            return null; // 컨테이너 없으면 전체 로드 (하위 호환성)
        }

        $domainId = $context->getDomainId();
        if (!$domainId) {
            return []; // 도메인 없으면 빈 배열 (아무것도 로드 안 함)
        }

        try {
            $extensionService = $this->container->get(ExtensionService::class);
            return $this->withoutFailedExtensions(
                $extensionService->getEnabledPlugins($domainId),
                'plugin'
            );
        } catch (\Throwable $e) {
            error_log('Failed to get enabled plugins: ' . $e->getMessage());
            return []; // 조회 실패 시 fail-closed (비활성 확장 라우트 노출 방지)
        }
    }

    /**
     * Package routes.php 로드
     *
     * 각 Package 디렉토리의 routes.php를 찾아 로드한다.
     * Plugin과 동일한 방식으로 PrefixedRouteCollector를 통해 접두사가 적용된다.
     *
     * ----------------------------------------------------
     * [도메인별 활성화 체크]
     * ----------------------------------------------------
     *
     * ExtensionService를 통해 도메인별 활성화된 Package만 로드한다.
     * 비활성화된 Package의 라우트는 등록되지 않는다.
     *
     * ----------------------------------------------------
     * [Package vs Plugin]
     * ----------------------------------------------------
     *
     * - Plugin: 단일 기능 확장 (포인트, 배너 등)
     * - Package: 복합 기능 확장 (쇼핑몰, 예약 시스템 등)
     *
     * 라우팅 방식은 동일하나, Package는 더 복잡한 MVC 구조를 가짐
     *
     * ----------------------------------------------------
     * [URL 접두사 자동 적용]
     * ----------------------------------------------------
     *
     * - Front: /{package_name}/...
     * - Admin: /admin/{package_name}/...
     *
     * 예) Shop 패키지:
     *     /goods        → /shop/goods
     *     /admin/order  → /admin/shop/order
     *
     * ----------------------------------------------------
     *
     * @param RouteCollector $r FastRoute RouteCollector
     * @param Context $context 애플리케이션 컨텍스트
     */
    private function loadPackageRoutes(RouteCollector $r, Context $context, array &$registeredRoutes): void
    {
        $packageDir = MUBLO_PACKAGE_PATH;

        // Packages 디렉토리가 없으면 스킵
        if (!is_dir($packageDir)) {
            return;
        }

        // ------------------------------------------------
        // 도메인별 활성화된 Package 목록 조회
        // ------------------------------------------------
        $enabledPackages = $this->getEnabledPackageNames($context);

        // 모든 Package 디렉토리 탐색
        $packages = glob($packageDir . '/*', GLOB_ONLYDIR);

        foreach ($packages as $packagePath) {
            $packageName = basename($packagePath);

            // ----------------------------------------
            // Package 활성화 체크
            //
            // 비활성화된 Package는 스킵
            // (enabledPackages가 null이면 전체 로드 - 컨테이너 없는 경우)
            // ----------------------------------------
            if ($enabledPackages !== null && !$this->isExtensionEnabled($packageName, $enabledPackages)) {
                continue;
            }

            $routesFile = $packagePath . '/routes.php';

            // routes.php가 있는 Package만 처리
            if (!file_exists($routesFile)) {
                continue;
            }

            // ----------------------------------------
            // Package 이름에서 URL 접두사 생성
            //
            // 디렉토리명: Shop
            // 접두사: shop (소문자)
            // ----------------------------------------
            $prefix = $this->buildRoutePrefix($packageName);

            // routes.php 로드 및 콜백 실행
            $this->loadExtensionRouteFile(
                $r,
                $registeredRoutes,
                $routesFile,
                $prefix,
                'package',
                $packageName
            );
        }
    }

    /**
     * routes.php 자체의 require 오류도 확장 경계 안에서 격리한다.
     *
     * @param array<int, array{method: string|string[], route: string, handler: mixed}> $registeredRoutes
     */
    private function loadExtensionRouteFile(
        RouteCollector $collector,
        array &$registeredRoutes,
        string $routesFile,
        string $prefix,
        string $type,
        string $name
    ): void {
        try {
            $callback = require $routesFile;
        } catch (\Throwable $e) {
            $this->recordExtensionRouteFailure($type, $name, $e);
            return;
        }

        if (!is_callable($callback)) {
            return;
        }

        $this->registerExtensionRoutes(
            $collector,
            $registeredRoutes,
            $callback,
            $prefix,
            $type,
            $name
        );
    }

    /**
     * 활성화 사전 검증용 routes.php 로더. 런타임처럼 건너뛰지 않고 실패 사유를 호출자에게 전달한다.
     *
     * @param array<int, array{method: string|string[], route: string, handler: mixed}> $registeredRoutes
     */
    private function registerConfiguredRouteFile(
        RouteCollector $collector,
        array &$registeredRoutes,
        string $routesFile,
        string $prefix,
        string $type,
        string $name
    ): void {
        if (!is_file($routesFile)) {
            return;
        }

        try {
            $callback = require $routesFile;
            if (is_callable($callback)) {
                $this->registerExtensionRoutesStrict(
                    $collector,
                    $registeredRoutes,
                    $callback,
                    $prefix,
                    $type
                );
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                "%s '%s'의 라우트를 활성화할 수 없습니다: %s",
                $type === 'package' ? '패키지' : '플러그인',
                $name,
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * 확장 routes.php를 임시 버퍼에서 실행하고 전체가 안전할 때만 실제 수집기에 반영한다.
     *
     * 콜백 오류나 FastRoute 충돌이 발생하면 버퍼 전체를 버리므로, 확장의 일부 라우트만
     * 남거나 충돌 확장 하나 때문에 도메인 전체 라우팅이 중단되지 않는다.
     *
     * @param array<int, array{method: string|string[], route: string, handler: mixed}> $registeredRoutes
     */
    private function registerExtensionRoutes(
        RouteCollector $collector,
        array &$registeredRoutes,
        callable $callback,
        string $prefix,
        string $type,
        string $name
    ): void {
        try {
            $this->registerExtensionRoutesStrict(
                $collector,
                $registeredRoutes,
                $callback,
                $prefix,
                $type
            );
        } catch (\Throwable $e) {
            $this->recordExtensionRouteFailure($type, $name, $e);
        }
    }

    /**
     * @param array<int, array{method: string|string[], route: string, handler: mixed}> $registeredRoutes
     */
    private function registerExtensionRoutesStrict(
        RouteCollector $collector,
        array &$registeredRoutes,
        callable $callback,
        string $prefix,
        string $type
    ): void {
        $buffer = new BufferedRouteCollector();
        $callback(new PrefixedRouteCollector($buffer, $prefix, $type));
        $candidateRoutes = $buffer->routes();

        $this->assertNoAdminAutoResolveShadow($candidateRoutes);
        $this->assertRoutesCanBeRegistered($registeredRoutes, $candidateRoutes);
        $this->commitRoutes($collector, $candidateRoutes, $registeredRoutes);
    }

    /**
     * Core와 앞서 등록한 확장 라우트를 동일한 FastRoute 구현으로 재생한 뒤 후보를 검증한다.
     * 실제 수집기는 검증 성공 후에만 수정되므로 등록은 확장 단위로 원자적이다.
     *
     * @param array<int, array{method: string|string[], route: string, handler: mixed}> $registeredRoutes
     * @param array<int, array{method: string|string[], route: string, handler: mixed}> $candidateRoutes
     */
    private function assertRoutesCanBeRegistered(array $registeredRoutes, array $candidateRoutes): void
    {
        $validator = new RouteCollector(new Std(), new GroupCountBased());

        foreach (array_merge($registeredRoutes, $candidateRoutes) as $route) {
            $validator->addRoute($route['method'], $route['route'], $route['handler']);
        }
    }

    /**
     * Admin autoResolve는 FastRoute 표에 없으므로 별도 예약 경계가 필요하다.
     * 확장이 해당 첫 세그먼트를 선점하면 Core 관리자 화면이 조용히 가려진다.
     *
     * @param array<int, array{method: string|string[], route: string, handler: mixed}> $candidateRoutes
     */
    private function assertNoAdminAutoResolveShadow(array $candidateRoutes): void
    {
        $reserved = [];
        foreach (array_keys(self::ADMIN_AUTO_RESOLVE_READ_ACTIONS) as $controller) {
            $name = preg_replace('/Controller$/', '', $controller) ?? $controller;
            $reserved[$this->buildRoutePrefix($name)] = true;
        }

        foreach ($candidateRoutes as $route) {
            $path = $route['route'];
            if (!preg_match('#^/admin/([^/]+)#', $path, $matches)) {
                continue;
            }

            $segment = $matches[1];
            if (str_contains($segment, '{') || isset($reserved[$segment])) {
                throw new BadRouteException(sprintf(
                    'Extension route "%s" conflicts with the reserved Core admin namespace "%s".',
                    $path,
                    $segment
                ));
            }
        }
    }

    /**
     * @param array<int, array{method: string|string[], route: string, handler: mixed}> $routes
     * @param array<int, array{method: string|string[], route: string, handler: mixed}> $registeredRoutes
     */
    private function commitRoutes(RouteCollector $collector, array $routes, array &$registeredRoutes): void
    {
        foreach ($routes as $route) {
            $collector->addRoute($route['method'], $route['route'], $route['handler']);
            $registeredRoutes[] = $route;
        }
    }

    private function recordExtensionRouteFailure(string $type, string $name, \Throwable $e): void
    {
        error_log(sprintf(
            '[EXTENSION] %s "%s" routes skipped: %s',
            $type,
            $name,
            $e->getMessage()
        ));

        if ($this->container !== null && $this->container->has(ExtensionLoadDiagnostics::class)) {
            $this->container->get(ExtensionLoadDiagnostics::class)->record($type, $name, 'routes', $e);
        }
    }

    /**
     * 활성화된 Package 이름 목록 조회
     *
     * @param Context $context
     * @return array|null 활성화된 패키지 이름 배열, 컨테이너가 없으면 null
     */
    private function getEnabledPackageNames(Context $context): ?array
    {
        if ($this->container === null) {
            return null; // 컨테이너 없으면 전체 로드 (하위 호환성)
        }

        $domainId = $context->getDomainId();
        if (!$domainId) {
            return []; // 도메인 없으면 빈 배열 (아무것도 로드 안 함)
        }

        try {
            $extensionService = $this->container->get(ExtensionService::class);
            return $this->withoutFailedExtensions(
                $extensionService->getEnabledPackages($domainId),
                'package'
            );
        } catch (\Throwable $e) {
            error_log('Failed to get enabled packages: ' . $e->getMessage());
            return []; // 조회 실패 시 fail-closed (비활성 확장 라우트 노출 방지)
        }
    }

    /**
     * 현재 요청에서 register/boot/dependency 실패한 확장의 Route를 노출하지 않는다.
     * 영속 활성 설정은 유지하므로 다음 요청에서 정상 복구되면 다시 로드된다.
     *
     * @param string[] $enabled
     * @return string[]
     */
    private function withoutFailedExtensions(array $enabled, string $type): array
    {
        if ($this->container === null || !$this->container->has(ExtensionLoadDiagnostics::class)) {
            return $enabled;
        }

        $failed = [];
        foreach ($this->container->get(ExtensionLoadDiagnostics::class)->all() as $failure) {
            if (($failure['type'] ?? null) === $type && isset($failure['name'])) {
                $failed[(string) $failure['name']] = true;
            }
        }

        return array_values(array_filter(
            $enabled,
            static fn(string $name): bool => !isset($failed[$name])
        ));
    }

    /**
     * 확장 이름이 활성화 목록에 포함되는지 확인
     *
     * 이름 비교 시 아래 표기를 모두 동등하게 처리한다.
     * - AutoForm
     * - auto-form
     * - auto_form
     * - autoform
     */
    private function isExtensionEnabled(string $name, array $enabledList): bool
    {
        $normalizedName = $this->normalizeExtensionName($name);
        foreach ($enabledList as $enabledName) {
            if (!is_string($enabledName)) {
                continue;
            }
            if ($this->normalizeExtensionName($enabledName) === $normalizedName) {
                return true;
            }
        }

        return false;
    }

    /**
     * URL 접두사 생성
     *
     * 예:
     * - AutoForm  -> auto-form
     * - auto_form -> auto-form
     * - auto-form -> auto-form
     */
    private function buildRoutePrefix(string $name): string
    {
        $prefix = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name);
        $prefix = str_replace('_', '-', (string) $prefix);
        $prefix = strtolower($prefix);
        $prefix = preg_replace('/-+/', '-', $prefix);
        return trim((string) $prefix, '-');
    }

    /**
     * 확장 이름 정규화 (매칭 비교용)
     */
    private function normalizeExtensionName(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';
    }

    /**
     * 자동 Controller / Method 매핑
     *
     * 명시적 라우트에서 매칭되지 않은 URL을
     * 규칙 기반으로 Controller/Method에 매핑한다.
     *
     * ----------------------------------------------------
     * [매핑 규칙]
     * ----------------------------------------------------
     *
     * URL 패턴                    → Controller@Method, params
     * /                           → IndexController@index
     * /main                       → MainController@index
     * /board/list                 → BoardController@list
     * /admin/member/edit          → (Admin) MemberController@edit
     * /admin/member/edit/123      → (Admin) MemberController@edit, ['123']
     * /admin/board/view/notice/5  → (Admin) BoardController@view, ['notice', '5']
     *
     * ----------------------------------------------------
     * [URL 파라미터]
     * ----------------------------------------------------
     *
     * 세 번째 세그먼트부터 params 배열로 Controller에 전달된다.
     * Controller 메서드 시그니처: function method(array $params, Context $context)
     *
     * 예: /admin/member-field/edit/42
     *     → MemberFieldController@edit
     *     → $params = ['42']
     *
     * ----------------------------------------------------
     * [Admin 영역 판단]
     * ----------------------------------------------------
     *
     * Context.isAdmin()이 true이면:
     * - 네임스페이스: Mublo\Controller\Admin\
     *
     * Context.isAdmin()이 false이면:
     * - 네임스페이스: Mublo\Controller\Front\
     *
     * ----------------------------------------------------
     * [미들웨어]
     * ----------------------------------------------------
     *
     * - Admin 영역: AdminMiddleware 자동 적용
     * - Front 영역: 미들웨어 없음 (필요시 명시적 라우트 정의)
     *
     * ----------------------------------------------------
     *
     * @param string $path URL 경로
     * @param Context $context 애플리케이션 컨텍스트
     * @param string $httpMethod HTTP 요청 메서드
     * @return array{
     *   controller: class-string,
     *   method: string,
     *   params: array,
     *   middleware: array
     * }
     */
    private function autoResolve(string $path, Context $context, string $httpMethod = 'GET'): array
    {
        // ------------------------------------------------
        // "/" 또는 빈 경로 → IndexController@index
        // ------------------------------------------------
        if ($path === '/' || $path === '') {
            return [
                'controller' => \Mublo\Controller\Front\IndexController::class,
                'method'     => 'index',
                'params'     => [],
                'middleware' => [],
            ];
        }

        // ------------------------------------------------
        // URL 세그먼트 분리
        //
        // /board/list → ['board', 'list']
        // /admin/member/edit → ['admin', 'member', 'edit']
        //
        // array_filter: 빈 문자열 제거 (양끝 슬래시)
        // array_values: 인덱스 재정렬
        // ------------------------------------------------
        $segments = array_values(
            array_filter(explode('/', $path))
        );

        // ------------------------------------------------
        // Admin 영역일 경우 'admin' 세그먼트 제거
        //
        // /admin/settings → ['admin', 'settings']
        // Admin 영역에서는 'admin'을 건너뛰고 처리
        // ['settings'] → SettingsController@index
        //
        // /admin/member/edit → ['admin', 'member', 'edit']
        // Admin 영역에서는 ['member', 'edit']로 처리
        // → MemberController@edit
        // ------------------------------------------------
        if ($context->isAdmin() && !empty($segments) && $segments[0] === 'admin') {
            array_shift($segments);
        }

        // ------------------------------------------------
        // 세그먼트가 비어있으면 (Admin 루트: /admin)
        // DashboardController@index로 처리
        // ------------------------------------------------
        if (empty($segments)) {
            $controllerName = 'DashboardController';
            $method = 'index';
            $params = [];
        } else {
            // ------------------------------------------------
            // Controller 이름 결정
            //
            // 첫 번째 세그먼트를 PascalCase로 변환
            // settings → SettingsController
            // my-page → MyPageController (kebab-case 지원)
            // ------------------------------------------------
            $controllerName = str_replace(' ', '', ucwords(str_replace('-', ' ', $segments[0]))) . 'Controller';

            // ------------------------------------------------
            // Method 이름 결정
            //
            // 두 번째 세그먼트가 있으면 사용
            // 없으면 'index'가 기본값
            // view-detail → viewDetail (camelCase 변환)
            // ------------------------------------------------
            $rawMethod = $segments[1] ?? 'index';
            $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $rawMethod))));

            // ------------------------------------------------
            // URL 파라미터 추출
            //
            // 세 번째 세그먼트부터 params 배열로 전달
            // /admin/member/edit/123     → ['123']
            // /admin/board/view/notice/5 → ['notice', '5']
            // ------------------------------------------------
            $params = array_slice($segments, 2);
        }

        // ------------------------------------------------
        // 네임스페이스 결정
        //
        // Context.isAdmin()에 따라 분기
        // - Admin: Mublo\Controller\Admin\
        // - Front: Mublo\Controller\Front\
        // ------------------------------------------------
        $namespace = $context->isAdmin()
            ? 'Mublo\\Controller\\Admin\\'
            : 'Mublo\\Controller\\Front\\';

        $controllerClass = $namespace . $controllerName;

        // ------------------------------------------------
        // 클래스/메서드 존재 검증
        //
        // 존재하지 않는 Controller나 public 메서드로의 접근을 차단
        // 내부 메서드가 의도치 않게 엔드포인트로 노출되는 것을 방지
        // ------------------------------------------------
        if (!class_exists($controllerClass)) {
            throw new HttpNotFoundException('Not Found');
        }

        if (!method_exists($controllerClass, $method)) {
            throw new HttpNotFoundException('Not Found');
        }

        // PHP 는 클래스·메서드 이름의 대소문자를 가리지 않는다. 그래서 /admin/MEMBER/delete
        // 도 MemberController::delete 로 해석된다. 반면 관리자 권한 검사는 요청 경로를
        // 메뉴 URL 과 대소문자까지 그대로 비교하므로 이런 경로는 어떤 메뉴에도 매칭되지
        // 않고, 빈 메뉴코드는 AdminPermissionService::isDenied() 가 무조건 허용한다.
        // 대소문자만 바꾸면 차단된 메뉴의 상태 변경 action 에 닿을 수 있었다.
        //
        // 명시 라우트(FastRoute)는 원래 대소문자를 구분한다. autoResolve 도 선언된
        // 이름과 정확히 같을 때만 통과시켜 두 경로의 판정을 일치시킨다.
        $reflClass = new \ReflectionClass($controllerClass);
        if ($reflClass->getName() !== $controllerClass) {
            throw new HttpNotFoundException('Not Found');
        }

        $reflMethod = new \ReflectionMethod($controllerClass, $method);
        if (!$reflMethod->isPublic() || $reflMethod->isStatic() || $reflMethod->getName() !== $method) {
            throw new HttpNotFoundException('Not Found');
        }

        // Admin 자동 라우팅은 조회용 GET/HEAD action만 명시 allowlist로 공개한다.
        // POST/PUT/PATCH/DELETE는 전역 CSRF 미들웨어를 통과하며 기존 관리자 API와
        // 호환된다. OPTIONS/TRACE 등 그 밖의 메서드는 Controller로 전달하지 않는다.
        if ($context->isAdmin()) {
            $httpMethod = strtoupper($httpMethod);

            $readActions = self::ADMIN_AUTO_RESOLVE_READ_ACTIONS[$controllerName] ?? [];
            if (in_array($httpMethod, ['GET', 'HEAD'], true)
                && !in_array($method, $readActions, true)) {
                throw new HttpNotFoundException('Not Found');
            }

            if (!in_array($httpMethod, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                throw new HttpNotFoundException('Not Found');
            }
        }

        // ------------------------------------------------
        // 미들웨어 결정
        //
        // Admin 영역은 자동으로 AdminMiddleware 적용
        // Front 영역은 미들웨어 없음
        // ------------------------------------------------
        $middleware = $context->isAdmin()
            ? [\Mublo\Core\Middleware\AdminMiddleware::class]
            : [];

        return [
            'controller' => $controllerClass,
            'method'     => $method,
            'params'     => $params,
            'middleware' => $middleware,
        ];
    }

    /**
     * 캐시 디렉토리 존재 확인 및 생성
     *
     * 캐시 파일을 저장할 디렉토리가 없으면 생성한다.
     * 재귀적으로 상위 디렉토리도 함께 생성된다.
     *
     * 경로: storage/cache/routes/
     */
    private function ensureCacheDirectoryExists(): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            // 0755: 소유자 rwx, 그룹 rx, 기타 rx
            // true: 재귀적 생성 (중간 디렉토리도 생성)
            mkdir(self::CACHE_DIR, 0755, true);
        }
    }

    /**
     * 캐시 파일이 TTL을 초과했으면 삭제
     *
     * 캐시 파일 생성 후 일정 시간(1시간)이 지나면 삭제하여
     * 다음 요청에서 라우트를 재구성하도록 한다.
     */
    private function invalidateStaleCacheFile(string $cacheFile): void
    {
        if (!file_exists($cacheFile)) {
            return;
        }

        if (time() - filemtime($cacheFile) > 3600) {
            unlink($cacheFile);
        }
    }

    /**
     * 같은 도메인의 '다른 시그니처' 캐시 파일 정리.
     *
     * 활성 확장/routes.php 가 바뀌면 새 시그니처의 캐시 파일이 생성되는데, 이전
     * 시그니처 파일은 더는 참조되지 않아 invalidateStaleCacheFile(현재 파일만 검사)로는
     * 절대 지워지지 않는다. 한 도메인의 활성 시그니처는 한 시점에 하나뿐이므로,
     * 유지할 파일을 제외한 형제는 낡은 것이며 즉시 제거한다(디스크·inode 누적 방지).
     */
    private function purgeSupersededCacheFiles(string $keepFile): void
    {
        if ($this->currentDomain === null) {
            return;
        }

        $safeDomain = str_replace([':', '/'], '_', $this->currentDomain);
        $keepBase = basename($keepFile);
        // 시그니처는 12자리 hex(buildRouteCacheSignature). 이 형태로 앵커링해야
        // glob 의 '*' 가 점을 포함하는 탓에 '정식 슈퍼스트링' 도메인(example.com 이
        // example.com.au 캐시를 지움)까지 삼키는 것을 막는다.
        $pattern = '/^' . preg_quote($safeDomain, '/') . '\.[0-9a-f]{12}\.cache\.php$/';
        foreach (glob(self::CACHE_DIR . '/' . $safeDomain . '.*.cache.php') ?: [] as $file) {
            $base = basename($file);
            if ($base !== $keepBase && preg_match($pattern, $base) === 1 && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * 현재 도메인의 라우트 캐시 클리어
     *
     * 현재 설정된 도메인의 캐시 파일을 삭제하여
     * 다음 요청 시 라우트가 재구성되도록 한다.
     *
     * ----------------------------------------------------
     * [사용 시점]
     * ----------------------------------------------------
     *
     * - 해당 도메인의 Plugin/Package 활성화 상태 변경 후
     * - routes.php 파일 수정 후
     *
     * ----------------------------------------------------
     *
     * @return bool 삭제 성공 여부
     */
    public function clearCache(): bool
    {
        if ($this->currentDomain === null) {
            return false;
        }

        $safeDomain = str_replace([':', '/'], '_', $this->currentDomain);
        $cacheFiles = glob(self::CACHE_DIR . '/' . $safeDomain . '.*.cache.php') ?: [];
        $legacyCacheFile = self::CACHE_DIR . '/' . $safeDomain . '.cache.php';
        if (is_file($legacyCacheFile)) {
            $cacheFiles[] = $legacyCacheFile;
        }

        foreach ($cacheFiles as $cacheFile) {
            if (is_file($cacheFile) && !unlink($cacheFile)) {
                return false;
            }
        }

        return true;  // 파일이 없으면 이미 클리어된 상태
    }

    /**
     * 특정 도메인의 라우트 캐시 클리어 (정적 메서드)
     *
     * 인스턴스 생성 없이 특정 도메인의 캐시를 클리어할 수 있는 편의 메서드
     *
     * @param string $domain 도메인명 (예: 'shop.example.com')
     * @return bool 삭제 성공 여부
     */
    public static function clearRouteCache(string $domain): bool
    {
        $safeDomain = str_replace([':', '/'], '_', $domain);
        $cacheFiles = glob(MUBLO_STORAGE_PATH . '/cache/routes/' . $safeDomain . '.*.cache.php') ?: [];
        $legacyCacheFile = MUBLO_STORAGE_PATH . '/cache/routes/' . $safeDomain . '.cache.php';
        if (is_file($legacyCacheFile)) {
            $cacheFiles[] = $legacyCacheFile;
        }

        foreach ($cacheFiles as $cacheFile) {
            if (is_file($cacheFile) && !unlink($cacheFile)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 모든 도메인의 라우트 캐시 클리어 (정적 메서드)
     *
     * routes 캐시 디렉토리의 모든 캐시 파일을 삭제한다.
     *
     * ----------------------------------------------------
     * [사용 시점]
     * ----------------------------------------------------
     *
     * - 전역 라우트 변경 후 (Core routes 수정)
     * - Plugin/Package 추가/삭제 후
     * - 배포 스크립트에서 자동 호출
     *
     * ----------------------------------------------------
     *
     * @return int 삭제된 파일 수
     */
    public static function clearAllRouteCache(): int
    {
        $cacheDir = MUBLO_STORAGE_PATH . '/cache/routes';
        $deletedCount = 0;

        if (!is_dir($cacheDir)) {
            return 0;
        }

        $cacheFiles = glob($cacheDir . '/*.cache.php');

        foreach ($cacheFiles as $file) {
            if (unlink($file)) {
                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    /**
     * 캐시 사용 여부 확인
     *
     * 현재 Router가 캐시를 사용하도록 설정되어 있는지 반환한다.
     * 디버깅/테스트 용도
     *
     * @return bool 캐시 사용 여부
     */
    public function isCacheEnabled(): bool
    {
        return $this->useCache;
    }

    /**
     * 현재 도메인의 캐시 파일 존재 여부 확인
     *
     * 라우트 캐시 파일이 실제로 존재하는지 확인한다.
     * 디버깅/모니터링 용도
     *
     * @return bool 캐시 파일 존재 여부
     */
    public function cacheFileExists(): bool
    {
        if ($this->currentDomain === null) {
            return false;
        }

        return file_exists($this->getCacheFilePath());
    }

    /**
     * 현재 도메인 반환
     *
     * dispatch() 호출 후 설정된 현재 도메인을 반환한다.
     *
     * @return string|null 현재 도메인
     */
    public function getCurrentDomain(): ?string
    {
        return $this->currentDomain;
    }

    /**
     * 캐시된 도메인 목록 반환
     *
     * 현재 캐시되어 있는 모든 도메인 목록을 반환한다.
     * 관리/모니터링 용도
     *
     * @return array 도메인 목록
     */
    public static function getCachedDomains(): array
    {
        $cacheDir = MUBLO_STORAGE_PATH . '/cache/routes';
        $domains = [];

        if (!is_dir($cacheDir)) {
            return [];
        }

        $cacheFiles = glob($cacheDir . '/*.cache.php');

        foreach ($cacheFiles as $file) {
            $filename = basename($file, '.cache.php');
            // 파일명에서 시그니처 제거 후 도메인 복원 (_ → :)
            $domainPart = preg_replace('/\.[a-f0-9]{12}$/', '', $filename) ?? $filename;
            $domain = str_replace('_', ':', $domainPart);
            $domains[] = $domain;
        }

        return array_values(array_unique($domains));
    }
}
