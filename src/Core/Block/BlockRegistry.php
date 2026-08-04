<?php
declare(strict_types=1);
namespace Mublo\Core\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Enum\Block\BlockContentKind;
use Mublo\Enum\Block\BlockContentType;
use InvalidArgumentException;
use Mublo\Core\Extension\ExtensionRegistrationScope;

/**
 * BlockRegistry
 *
 * 블록 콘텐츠 타입 레지스트리
 *
 * Plugin과 Package가 자신만의 콘텐츠 타입을 등록할 수 있게 하는 핵심 클래스
 *
 * 계약(Contract):
 * - rendererClass는 반드시 RendererInterface를 구현해야 함
 * - 중복 등록 시 기본적으로 예외 발생 (allowOverwrite 옵션으로 덮어쓰기 가능)
 *
 * 사용 예:
 * ```php
 * // Plugin에서 콘텐츠 타입 등록
 * BlockRegistry::registerContentType(
 *     type: 'gallery',
 *     kind: BlockContentKind::PLUGIN->value,
 *     title: '갤러리',
 *     rendererClass: GalleryRenderer::class,
 *     configFormClass: GalleryConfigForm::class
 * );
 *
 * // 기존 타입 덮어쓰기 (주의해서 사용)
 * BlockRegistry::registerContentType(
 *     type: BlockContentType::BOARD->value,
 *     kind: BlockContentKind::PLUGIN->value,
 *     title: '커스텀 게시판',
 *     rendererClass: CustomBoardRenderer::class,
 *     options: ['allowOverwrite' => true]
 * );
 *
 * // 관리자에서 등록된 타입 목록 가져오기
 * $types = BlockRegistry::getContentTypes();
 * ```
 */
class BlockRegistry
{
    /** @var list<string> */
    public const CAPABILITY_KEYS = ['skin', 'items', 'count', 'style', 'aos', 'customConfig'];

    /**
     * 등록된 콘텐츠 타입
     *
     * @var array<string, array{
     *     type: string,
     *     kind: string,
     *     title: string,
     *     renderer: string,
     *     configForm: string|null,
     *     options: array
     * }>
     */
    private static array $contentTypes = [];

    /** @var array<string, string> */
    private static array $contentTypeOwners = [];

    /**
     * 선점된 타입 코드를 다시 요구해 등록이 생략된 기록
     *
     * 코어는 충돌을 해결하지 않는다. 늦게 온 쪽의 해당 타입 하나만 빼고,
     * 누가 누구에게 밀렸는지만 남긴다.
     *
     * @var list<array{
     *     type: string,
     *     claimant: string|null,
     *     claimantKind: string,
     *     claimantTitle: string,
     *     holder: string|null,
     *     holderKind: string,
     *     holderTitle: string
     * }>
     */
    private static array $typeConflicts = [];

    /**
     * 초기화 여부
     */
    private static bool $initialized = false;

    /**
     * 콘텐츠 타입 등록
     *
     * Plugin/Package의 Provider.boot()에서 호출
     *
     * @param string $type 콘텐츠 타입 코드 (예: 'gallery', 'product')
     * @param string $kind 콘텐츠 종류 (CORE, PLUGIN, PACKAGE)
     * @param string $title 표시 제목 (예: '갤러리', '상품')
     * @param string $rendererClass Renderer 클래스명 (RendererInterface 구현 필수)
     * @param string|null $configFormClass 관리자 설정 폼 클래스명 (ConfigFormInterface 구현)
     * @param array $options 추가 옵션
     *   - allowOverwrite: bool - true면 기존 타입 덮어쓰기 허용 (기본: false)
     *       false인데 타입 코드가 이미 선점돼 있으면 예외 없이 이 등록만 생략한다.
     *       확장의 나머지 등록(다른 타입·메뉴·라우트)은 그대로 살아남는다.
     *   - skipValidation: bool - true면 인터페이스 검증 스킵 (Core 전용, 기본: false)
     *   - capabilities: array - 관리자 공통 UI 기능(skin/items/count/style/aos/customConfig)
     *       신규 타입은 capabilities()로 여섯 기능을 모두 명시한다. hasItems/hasStyle은
     *       기존 확장 호환용이며 capability가 선언되면 UI 판단에 사용하지 않는다.
     *   - editor: array{adapter: string, mode: inline|modal} - 전용 편집 UI 라우팅 메타
     *   - maxItems: int - 콘텐츠 아이템 최대 선택 수 (items capability 타입만 의미 있음)
     *       미지정 → 출력갯수(pc/mo count)를 최대치로 사용. 아이템이 곧 출력 단위인
     *                진열형 타입(이미지 등)에 맞다.
     *       1 이상 → 출력갯수와 무관하게 고정. 아이템이 "데이터를 어디서 가져올지"를
     *                지정하는 소스형 타입(게시판 등)은 출력갯수와 축이 다르므로 여기에 해당.
     *       0      → 무제한.
     *   - icon: string - 관리자 편집기 타입 목록에 쓸 아이콘 클래스 (예: 'bi-images')
     *       미지정 → 관리자 UI 가 기본 아이콘을 쓴다. 코어는 타입별 아이콘을
     *                추측하지 않는다 — 타입을 소유한 확장이 선언한다.
     *   - kitPortable: bool - 블록 킷에 콘텐츠 아이템을 담을 수 있는지 (기본: true)
     *       false → 킷 내보내기에서 아이템을 비운다. 아이템이 그 도메인에서만
     *               의미 있는 참조(업로드 이미지 등)라 옮겨 봐야 깨지는 타입에 쓴다.
     *
     * @throws InvalidArgumentException 인터페이스 미구현 또는 옵션 형식 오류 시
     */
    public static function registerContentType(
        string $type,
        string $kind,
        string $title,
        string $rendererClass,
        ?string $configFormClass = null,
        array $options = []
    ): void {
        // 코어 타입을 먼저 자리잡게 한다. 등록이 지연 초기화를 타지 않으면 확장이
        // 코어 코드를 선점할 수 있고, 그러면 나중 첫 읽기에서 코어 초기화가 터진다.
        self::ensureInitialized();

        $allowOverwrite = $options['allowOverwrite'] ?? false;
        $skipValidation = $options['skipValidation'] ?? false;

        $options['capabilities'] = self::normalizeCapabilities($options);
        if (isset($options['editor'])) {
            self::validateEditor($options['editor']);
        }

        // 1. 선점 검사. 머블로는 확장 제작자에게 타입 코드 네이밍을 강제하지 않으므로
        // 충돌은 언제든 일어날 수 있다. 코어는 충돌을 해결하지 않고, 늦게 온 등록
        // 하나만 생략한 뒤 기록만 남긴다(선착순). 확장 전체를 중단시키지 않는다.
        if (isset(self::$contentTypes[$type]) && !$allowOverwrite) {
            self::recordTypeConflict($type, $kind, $title);
            return;
        }

        // 2. RendererInterface 구현 검사. 존재하지 않는 클래스도 등록 단계에서 실패시켜
        // 오타가 운영 화면의 빈 블록으로 늦게 드러나지 않게 한다.
        if (!$skipValidation) {
            if (!class_exists($rendererClass)) {
                throw new InvalidArgumentException(
                    "Renderer 클래스 '{$rendererClass}'를 찾을 수 없습니다."
                );
            }

            if (!is_subclass_of($rendererClass, RendererInterface::class)) {
                throw new InvalidArgumentException(
                    "Renderer 클래스 '{$rendererClass}'는 RendererInterface를 구현해야 합니다."
                );
            }
        }

        // 3. 등록
        $previous = self::$contentTypes[$type] ?? null;
        $previousOwner = self::$contentTypeOwners[$type] ?? null;
        $owner = ExtensionRegistrationScope::currentOwner();
        $registered = [
            'type' => $type,
            'kind' => $kind,
            'title' => $title,
            'renderer' => $rendererClass,
            'configForm' => $configFormClass,
            'options' => $options,
        ];
        self::$contentTypes[$type] = $registered;

        if ($owner !== null) {
            self::$contentTypeOwners[$type] = $owner;
            ExtensionRegistrationScope::recordCommit(function () use ($type, $owner): void {
                if ((self::$contentTypeOwners[$type] ?? null) === $owner) {
                    unset(self::$contentTypeOwners[$type]);
                }
            });
            ExtensionRegistrationScope::recordUndo(function () use (
                $type,
                $owner,
                $previous,
                $previousOwner
            ): void {
                if ((self::$contentTypeOwners[$type] ?? null) !== $owner) {
                    return;
                }

                if ($previous === null) {
                    unset(self::$contentTypes[$type], self::$contentTypeOwners[$type]);
                    return;
                }

                self::$contentTypes[$type] = $previous;
                if ($previousOwner === null || !ExtensionRegistrationScope::has($previousOwner)) {
                    unset(self::$contentTypeOwners[$type]);
                } else {
                    self::$contentTypeOwners[$type] = $previousOwner;
                }
            });
        } else {
            unset(self::$contentTypeOwners[$type]);
        }
    }

    /**
     * 선점된 코드라 등록이 생략됐음을 기록한다.
     *
     * 화면에는 승자 하나만 보이므로, 진 쪽이 왜 사라졌는지는 여기서만 알 수 있다.
     */
    private static function recordTypeConflict(string $type, string $kind, string $title): void
    {
        $holderInfo = self::$contentTypes[$type];
        $claimant = ExtensionRegistrationScope::currentOwner();
        $holder = self::$contentTypeOwners[$type] ?? null;

        self::$typeConflicts[] = [
            'type' => $type,
            'claimant' => $claimant,
            'claimantKind' => $kind,
            'claimantTitle' => $title,
            'holder' => $holder,
            'holderKind' => $holderInfo['kind'],
            'holderTitle' => $holderInfo['title'],
        ];

        error_log(sprintf(
            '[BLOCK] content type "%s" skipped for %s: already registered by %s',
            $type,
            $claimant ?? $kind,
            $holder ?? $holderInfo['kind']
        ));
    }

    /**
     * 등록이 생략된 타입 코드 충돌 목록
     *
     * @return list<array{
     *     type: string,
     *     claimant: string|null,
     *     claimantKind: string,
     *     claimantTitle: string,
     *     holder: string|null,
     *     holderKind: string,
     *     holderTitle: string
     * }>
     */
    public static function getTypeConflicts(): array
    {
        return self::$typeConflicts;
    }

    /**
     * 콘텐츠 타입 정보 조회
     *
     * @param string $type 콘텐츠 타입 코드
     * @return array|null 타입 정보 또는 null
     */
    public static function getContentType(string $type): ?array
    {
        self::ensureInitialized();

        return self::$contentTypes[$type] ?? null;
    }

    /**
     * 모든 콘텐츠 타입 목록 조회
     *
     * @param string|null $kind 종류 필터 (null이면 전체)
     * @return array
     */
    public static function getContentTypes(?string $kind = null): array
    {
        self::ensureInitialized();

        if ($kind === null) {
            return self::$contentTypes;
        }

        return array_filter(
            self::$contentTypes,
            fn($item) => $item['kind'] === $kind
        );
    }

    /**
     * 종류별 콘텐츠 타입 그룹화
     *
     * 관리자 폼에서 select 옵션 그룹으로 사용
     *
     * @return array
     */
    public static function getContentTypesGroupedByKind(): array
    {
        self::ensureInitialized();

        $grouped = [
            BlockContentKind::CORE->value => [],
            BlockContentKind::PLUGIN->value => [],
            BlockContentKind::PACKAGE->value => [],
        ];

        foreach (self::$contentTypes as $type => $info) {
            $grouped[$info['kind']][$type] = $info;
        }

        return $grouped;
    }

    /**
     * 콘텐츠 타입 선택 옵션 생성
     *
     * @return array [['value' => type, 'label' => title, 'kind' => kind], ...]
     */
    public static function getContentTypeOptions(): array
    {
        self::ensureInitialized();

        $options = [];

        foreach (self::$contentTypes as $type => $info) {
            $opts = $info['options'] ?? [];
            $option = [
                'value' => $type,
                'label' => $info['title'],
                'kind' => $info['kind'],
                'capabilities' => $opts['capabilities'] ?? self::normalizeCapabilities($opts),
            ];

            if (!empty($opts['hasItems'])) {
                $option['hasItems'] = true;
            }
            if (!empty($opts['hasStyle'])) {
                $option['hasStyle'] = true;
            }
            // 미지정이면 관리자 UI가 출력갯수 연동(기존 동작)을 그대로 쓴다.
            if (isset($opts['maxItems'])) {
                $option['maxItems'] = (int) $opts['maxItems'];
            }
            if (!empty($opts['adminScript'])) {
                $option['adminScript'] = $opts['adminScript'];
                $option['adminScriptInit'] = $opts['adminScriptInit'] ?? '';
            }
            if (isset($opts['editor'])) {
                $option['editor'] = $opts['editor'];
            }
            if (!empty($opts['icon'])) {
                $option['icon'] = $opts['icon'];
            }

            $options[] = $option;
        }

        return $options;
    }

    /**
     * 콘텐츠 타입의 관리자 UI 기능을 명시적으로 선언한다.
     * 모든 인자를 이름으로 전달하면 등록부만 읽어도 타입의 UI 계약을 알 수 있다.
     */
    public static function capabilities(
        bool $skin,
        bool $items,
        bool $count,
        bool $style,
        bool $aos,
        bool $customConfig,
    ): array {
        return compact('skin', 'items', 'count', 'style', 'aos', 'customConfig');
    }

    /**
     * 코어와 확장이 동일하게 사용하는 전용 콘텐츠 편집기 메타를 만든다.
     */
    public static function editor(string $adapter, string $mode = 'inline'): array
    {
        $editor = compact('adapter', 'mode');
        self::validateEditor($editor);

        return $editor;
    }

    private static function validateEditor(mixed $editor): void
    {
        if (!is_array($editor)) {
            throw new InvalidArgumentException("options['editor']는 배열이어야 합니다.");
        }

        $adapter = $editor['adapter'] ?? null;
        if (!is_string($adapter) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $adapter) !== 1) {
            throw new InvalidArgumentException("options['editor'].adapter 형식이 올바르지 않습니다.");
        }

        $mode = $editor['mode'] ?? null;
        if (!in_array($mode, ['inline', 'modal'], true)) {
            throw new InvalidArgumentException("options['editor'].mode는 inline 또는 modal이어야 합니다.");
        }

        $unknown = array_diff(array_keys($editor), ['adapter', 'mode']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('알 수 없는 editor 메타입니다: ' . implode(', ', $unknown));
        }
    }

    /**
     * 관리자 공통 UI가 사용할 콘텐츠 타입 capability를 정규화한다.
     *
     * 명시적 capabilities가 우선한다. 기존 확장은 호환을 위해 구 옵션에서
     * 추론하되, 신규/내장 타입은 각 기능을 명시적으로 선언해야 한다.
     *
     * @return array{skin: bool, items: bool, count: bool, style: bool, aos: bool, customConfig: bool}
     */
    private static function normalizeCapabilities(array $options): array
    {
        $legacy = [
            'skin' => !empty($options['skinBasePath']),
            'items' => !empty($options['hasItems']),
            // 기존 행 설정 모달은 hasItems || hasStyle일 때 출력갯수를 노출했다.
            'count' => !empty($options['hasItems']) || !empty($options['hasStyle']),
            'style' => !empty($options['hasStyle']),
            // 확장 타입은 일부 코어 타입 예외를 제외하고 AOS가 항상 노출됐다.
            'aos' => true,
            'customConfig' => !empty($options['adminScript']),
        ];

        if (!array_key_exists('capabilities', $options)) {
            return $legacy;
        }

        if (!is_array($options['capabilities'])) {
            throw new InvalidArgumentException("options['capabilities']는 배열이어야 합니다.");
        }

        $capabilities = $options['capabilities'];
        $unknown = array_diff(array_keys($capabilities), self::CAPABILITY_KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                '알 수 없는 블록 capability입니다: ' . implode(', ', $unknown)
            );
        }

        foreach ($capabilities as $key => $enabled) {
            if (!is_bool($enabled)) {
                throw new InvalidArgumentException("블록 capability '{$key}'는 bool이어야 합니다.");
            }
        }

        return array_replace($legacy, $capabilities);
    }

    /**
     * 콘텐츠 타입 존재 여부 확인
     *
     * @param string $type 콘텐츠 타입
     * @return bool
     */
    public static function hasContentType(string $type): bool
    {
        self::ensureInitialized();

        return isset(self::$contentTypes[$type]);
    }

    /**
     * Renderer 클래스 조회
     *
     * @param string $type 콘텐츠 타입
     * @return string|null
     */
    public static function getRendererClass(string $type): ?string
    {
        self::ensureInitialized();

        return self::$contentTypes[$type]['renderer'] ?? null;
    }

    /**
     * Renderer 인스턴스 생성
     *
     * 클래스 검증 + 인스턴스화를 한번에 수행
     *
     * @param string $type 콘텐츠 타입
     * @return RendererInterface|null
     * @throws InvalidArgumentException Renderer가 인터페이스를 구현하지 않는 경우
     */
    public static function createRenderer(string $type): ?RendererInterface
    {
        $rendererClass = self::getRendererClass($type);

        if ($rendererClass === null) {
            return null;
        }

        if (!class_exists($rendererClass)) {
            throw new InvalidArgumentException(
                "Renderer 클래스 '{$rendererClass}'를 찾을 수 없습니다."
            );
        }

        $renderer = new $rendererClass();

        if (!$renderer instanceof RendererInterface) {
            throw new InvalidArgumentException(
                "Renderer 클래스 '{$rendererClass}'는 RendererInterface를 구현해야 합니다."
            );
        }

        return $renderer;
    }

    /**
     * 스킨 기본 경로 조회
     *
     * Plugin/Package가 등록한 커스텀 스킨 경로 반환
     * 미등록 시 null (BlockSkinService가 기본 경로 사용)
     *
     * @param string $type 콘텐츠 타입
     * @return string|null 절대 경로 또는 null
     */
    public static function getSkinBasePath(string $type): ?string
    {
        self::ensureInitialized();

        return self::$contentTypes[$type]['options']['skinBasePath'] ?? null;
    }

    /**
     * 캐시 비활성화 여부
     *
     * 사용자 상태에 의존하는 콘텐츠 (로그인 위젯 등)는 캐시하면 안 됨
     *
     * @param string $type 콘텐츠 타입
     * @return bool
     */
    public static function isNoCache(string $type): bool
    {
        self::ensureInitialized();

        return !empty(self::$contentTypes[$type]['options']['noCache']);
    }

    /**
     * 블록 킷에 콘텐츠 아이템을 담을 수 있는지 여부
     *
     * 블록 킷은 다른 도메인·설치로 옮겨지므로, 아이템이 그 도메인에서만
     * 의미 있는 참조(업로드된 배너 이미지 등)라면 담아 봐야 깨진 참조만 남는다.
     * 그런 타입은 등록 시 options['kitPortable'] = false 로 선언한다.
     *
     * 등록된 타입의 기본값은 true 다 — 코어는 어떤 타입이 이식 가능한지 모르며,
     * 판단은 타입을 소유한 확장이 한다.
     *
     * 미등록 타입은 false 다. 확장이 비활성이면 그 타입의 칸은 DB 에 남아 있어도
     * 물어볼 상대가 없다. 킷은 다른 도메인·설치로 옮겨지므로, 알 수 없으면 담지
     * 않는 쪽이 안전하다 — 비워도 kit_needs_items 로 표시되어 가져오기 쪽에서
     * 다시 지정할 수 있다.
     *
     * @param string $type 콘텐츠 타입
     * @return bool
     */
    public static function isKitPortable(string $type): bool
    {
        self::ensureInitialized();

        $registered = self::$contentTypes[$type] ?? null;
        if ($registered === null) {
            return false;
        }

        $options = $registered['options'] ?? [];

        return !array_key_exists('kitPortable', $options) || (bool) $options['kitPortable'];
    }

    /**
     * 아이템 선택 필요 여부
     *
     * @param string $type 콘텐츠 타입
     * @return bool
     */
    public static function hasItems(string $type): bool
    {
        self::ensureInitialized();

        return !empty(self::$contentTypes[$type]['options']['hasItems']);
    }

    /**
     * ItemsProvider 클래스 조회
     *
     * DualListbox 모드에서 아이템 목록을 제공하는 PHP 클래스
     *
     * @param string $type 콘텐츠 타입
     * @return string|null BlockItemsProviderInterface 구현 클래스 FQCN
     */
    public static function getItemsProviderClass(string $type): ?string
    {
        self::ensureInitialized();

        return self::$contentTypes[$type]['options']['itemsProvider'] ?? null;
    }

    /**
     * 관리자 커스텀 스크립트 정보 조회
     *
     * Custom UI 모드에서 Plugin이 등록한 JS 스크립트 정보
     *
     * @param string $type 콘텐츠 타입
     * @return array|null ['script' => URL, 'init' => 전역 객체명] 또는 null
     */
    public static function getAdminScript(string $type): ?array
    {
        self::ensureInitialized();

        $opts = self::$contentTypes[$type]['options'] ?? [];

        if (empty($opts['adminScript'])) {
            return null;
        }

        return [
            'script' => $opts['adminScript'],
            'init' => $opts['adminScriptInit'] ?? '',
        ];
    }

    /**
     * ConfigForm 클래스 조회
     *
     * 현재 관리자 블록 설정 UI는 JS(adminScript) 기반으로 운영되며,
     * 이 메서드를 통한 PHP 서버사이드 폼 렌더링은 사용되지 않음.
     * 향후 PHP 폼 렌더링이 필요할 때를 위한 확장 포인트로 유지.
     *
     * @param string $type 콘텐츠 타입
     * @return string|null
     */
    public static function getConfigFormClass(string $type): ?string
    {
        self::ensureInitialized();

        return self::$contentTypes[$type]['configForm'] ?? null;
    }

    /**
     * 콘텐츠 타입 제거
     *
     * 주로 테스트용
     *
     * @param string $type 콘텐츠 타입
     */
    public static function unregisterContentType(string $type): void
    {
        unset(self::$contentTypes[$type], self::$contentTypeOwners[$type]);
    }

    /**
     * 레지스트리 초기화 (테스트용)
     */
    public static function reset(): void
    {
        self::$contentTypes = [];
        self::$contentTypeOwners = [];
        self::$typeConflicts = [];
        self::$initialized = false;
    }

    /**
     * Core 콘텐츠 타입 초기화
     */
    private static function ensureInitialized(): void
    {
        if (self::$initialized) {
            return;
        }

        // initializeCoreTypes()도 registerContentType()을 타고 되돌아온다.
        // 플래그를 먼저 세워 재진입을 끊는다. 초기화가 중간에 실패하더라도
        // 다음 호출이 처음부터 다시 돌며 부분 등록분과 충돌하는 일이 없다.
        self::$initialized = true;
        self::initializeCoreTypes();
    }

    /**
     * Core 콘텐츠 타입 등록
     */
    private static function initializeCoreTypes(): void
    {
        // HTML 직접입력
        self::registerContentType(
            type: BlockContentType::HTML->value,
            kind: BlockContentKind::CORE->value,
            title: 'HTML 직접입력',
            rendererClass: 'Mublo\\Core\\Block\\Renderer\\HtmlRenderer',
            configFormClass: null,
            options: ['capabilities' => [
                'skin' => false, 'items' => false, 'count' => false,
                'style' => false, 'aos' => true, 'customConfig' => false,
            ], 'editor' => self::editor('html', 'modal'), 'icon' => 'bi-code-slash']
        );

        // 이미지
        self::registerContentType(
            type: BlockContentType::IMAGE->value,
            kind: BlockContentKind::CORE->value,
            title: '이미지',
            rendererClass: 'Mublo\\Core\\Block\\Renderer\\ImageRenderer',
            configFormClass: 'Mublo\\Core\\Block\\Form\\ImageConfigForm',
            options: [
                'hasStyle' => true,
                'capabilities' => [
                    'skin' => false, 'items' => false, 'count' => false,
                    'style' => true, 'aos' => true, 'customConfig' => false,
                ],
                'editor' => self::editor('image', 'modal'),
                'icon' => 'bi-image',
            ]
        );

        // 동영상
        self::registerContentType(
            type: BlockContentType::MOVIE->value,
            kind: BlockContentKind::CORE->value,
            title: '동영상',
            rendererClass: 'Mublo\\Core\\Block\\Renderer\\MovieRenderer',
            configFormClass: 'Mublo\\Core\\Block\\Form\\MovieConfigForm',
            options: ['capabilities' => [
                'skin' => false, 'items' => false, 'count' => false,
                'style' => false, 'aos' => true, 'customConfig' => false,
            ], 'editor' => self::editor('movie', 'modal'), 'icon' => 'bi-play-btn']
        );

        // 로그인 위젯
        self::registerContentType(
            type: BlockContentType::OUTLOGIN->value,
            kind: BlockContentKind::CORE->value,
            title: '로그인 위젯',
            rendererClass: 'Mublo\\Core\\Block\\Renderer\\OutloginRenderer',
            configFormClass: null,
            options: [
                'noCache' => true,
                'capabilities' => [
                    'skin' => true, 'items' => false, 'count' => false,
                    'style' => false, 'aos' => true, 'customConfig' => false,
                ],
                'editor' => self::editor('outlogin'),
                'icon' => 'bi-person-check',
            ]
        );

        // 메뉴
        self::registerContentType(
            type: BlockContentType::MENU->value,
            kind: BlockContentKind::CORE->value,
            title: '메뉴',
            rendererClass: 'Mublo\\Core\\Block\\Renderer\\MenuRenderer',
            configFormClass: 'Mublo\\Core\\Block\\Form\\MenuConfigForm',
            // 전체 트리도 현재 URL에 따라 active 표시가 달라지고,
            // scope=current 는 출력할 하위 트리 자체가 요청마다 달라진다.
            options: [
                'hasItems' => true,
                'noCache' => true,
                'capabilities' => [
                    'skin' => true, 'items' => true, 'count' => false,
                    'style' => false, 'aos' => true, 'customConfig' => false,
                ],
                'editor' => self::editor('menu'),
                'icon' => 'bi-list-ul',
            ]
        );

        // PHP 파일 포함 (개발자용)
        self::registerContentType(
            type: BlockContentType::INCLUDE->value,
            kind: BlockContentKind::CORE->value,
            title: 'PHP 파일 포함',
            rendererClass: 'Mublo\\Core\\Block\\Renderer\\IncludeRenderer',
            configFormClass: 'Mublo\\Core\\Block\\Form\\IncludeConfigForm',
            options: [
                'noCache' => true,
                'capabilities' => [
                    'skin' => false, 'items' => false, 'count' => false,
                    'style' => false, 'aos' => false, 'customConfig' => false,
                ],
                'editor' => self::editor('include'),
                'icon' => 'bi-filetype-php',
            ]
        );
    }
}
