<?php
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
     *   - skipValidation: bool - true면 인터페이스 검증 스킵 (Core 전용, 기본: false)
     *   - maxItems: int - 콘텐츠 아이템 최대 선택 수 (hasItems 인 타입만 의미 있음)
     *       미지정 → 출력갯수(pc/mo count)를 최대치로 사용. 아이템이 곧 출력 단위인
     *                진열형 타입(이미지 등)에 맞다.
     *       1 이상 → 출력갯수와 무관하게 고정. 아이템이 "데이터를 어디서 가져올지"를
     *                지정하는 소스형 타입(게시판 등)은 출력갯수와 축이 다르므로 여기에 해당.
     *       0      → 무제한.
     *
     * @throws InvalidArgumentException 인터페이스 미구현 또는 중복 등록 시
     */
    public static function registerContentType(
        string $type,
        string $kind,
        string $title,
        string $rendererClass,
        ?string $configFormClass = null,
        array $options = []
    ): void {
        $allowOverwrite = $options['allowOverwrite'] ?? false;
        $skipValidation = $options['skipValidation'] ?? false;

        // 1. 중복 등록 검사
        if (isset(self::$contentTypes[$type]) && !$allowOverwrite) {
            $existingKind = self::$contentTypes[$type]['kind'];
            throw new InvalidArgumentException(
                "콘텐츠 타입 '{$type}'은(는) 이미 {$existingKind}에서 등록되었습니다. " .
                "덮어쓰려면 options['allowOverwrite'] = true를 사용하세요."
            );
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

            $options[] = $option;
        }

        return $options;
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

        self::initializeCoreTypes();
        self::$initialized = true;
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
            configFormClass: null
        );

        // 이미지
        self::registerContentType(
            type: BlockContentType::IMAGE->value,
            kind: BlockContentKind::CORE->value,
            title: '이미지',
            rendererClass: 'Mublo\\Core\\Block\\Renderer\\ImageRenderer',
            configFormClass: 'Mublo\\Core\\Block\\Form\\ImageConfigForm',
            options: ['hasStyle' => true]
        );

        // 동영상
        self::registerContentType(
            type: BlockContentType::MOVIE->value,
            kind: BlockContentKind::CORE->value,
            title: '동영상',
            rendererClass: 'Mublo\\Core\\Block\\Renderer\\MovieRenderer',
            configFormClass: 'Mublo\\Core\\Block\\Form\\MovieConfigForm'
        );

        // 로그인 위젯
        self::registerContentType(
            type: BlockContentType::OUTLOGIN->value,
            kind: BlockContentKind::CORE->value,
            title: '로그인 위젯',
            rendererClass: 'Mublo\\Core\\Block\\Renderer\\OutloginRenderer',
            configFormClass: null,
            options: ['noCache' => true]
        );

        // 메뉴
        self::registerContentType(
            type: BlockContentType::MENU->value,
            kind: BlockContentKind::CORE->value,
            title: '메뉴',
            rendererClass: 'Mublo\\Core\\Block\\Renderer\\MenuRenderer',
            configFormClass: 'Mublo\\Core\\Block\\Form\\MenuConfigForm',
            options: ['hasItems' => true]
        );

        // PHP 파일 포함 (개발자용)
        self::registerContentType(
            type: BlockContentType::INCLUDE->value,
            kind: BlockContentKind::CORE->value,
            title: 'PHP 파일 포함',
            rendererClass: 'Mublo\\Core\\Block\\Renderer\\IncludeRenderer',
            configFormClass: 'Mublo\\Core\\Block\\Form\\IncludeConfigForm',
            options: ['noCache' => true]
        );
    }
}
