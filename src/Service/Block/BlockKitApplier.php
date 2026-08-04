<?php
declare(strict_types=1);
namespace Mublo\Service\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Event\Block\BlockPageMenuSyncEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockPageRepository;
use Mublo\Repository\Block\BlockRowRevisionRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Core\App\Application;
use Mublo\Entity\Block\BlockRow;
use Mublo\Service\Domain\DomainSettingsService;
use Mublo\Service\Extension\ExtensionCompatibility;
use Mublo\Service\System\InstallIdProvider;

/**
 * BlockKitApplier
 *
 * JSON kit의 검증(dry-run)과 실제 DB 반영을 담당한다.
 *
 * BlockSeeder 는 건드리지 않는다 — 설치 시점에는 DI 컨테이너가 없어
 * 생성자를 바꾸면 설치가 깨진다(설계 11.2 ③).
 */
class BlockKitApplier
{
    private BlockColumnPayloadNormalizer $columnNormalizer;

    public const MAX_KIT_BYTES = 2097152; // 2 MiB

    public const MODE_APPEND = 'append';
    public const MODE_REPLACE = 'replace';

    private const ROW_OMIT_FIELDS = [
        'row_id',
        'domain_id',
        'page_id',
        'created_at',
        'updated_at',
    ];

    private const COLUMN_OMIT_FIELDS = [
        'column_id',
        'row_id',
        'domain_id',
        'created_at',
        'updated_at',
    ];

    /**
     * DB 에 쓸 수 있는 행 필드.
     *
     * 블록 킷은 제3자 파일이므로 화이트리스트로 받는다. 모르는 키가 INSERT 로 흘러가면
     * 쿼리가 깨지거나 의도치 않은 컬럼이 채워진다.
     */
    private const ROW_ALLOWED_FIELDS = [
        'position', 'position_menu', 'section_id', 'admin_title',
        'width_type', 'column_count', 'column_margin', 'column_width_unit',
        'pc_height', 'mobile_height', 'pc_margin', 'mobile_margin',
        'pc_padding', 'mobile_padding', 'background_config',
        'sort_order', 'is_active',
    ];

    /**
     * DB 에 쓸 수 있는 칸 필드.
     *
     * column_index / sort_order 는 replaceByRow() 가 배열 순서로 채우므로 블록 킷이 지정하지 않는다.
     */
    private const COLUMN_ALLOWED_FIELDS = [
        'width', 'pc_padding', 'mobile_padding',
        'background_config', 'border_config', 'title_config',
        'content_type', 'content_kind', 'content_skin', 'content_config', 'content_items',
        'is_active',
    ];

    /** 1.1 스택 칸의 하위 콘텐츠에서 DB 에 쓸 수 있는 필드 (계획 9.1) */
    private const CONTENT_ALLOWED_FIELDS = [
        'title_config', 'content_type', 'content_kind', 'content_skin',
        'content_config', 'content_items', 'is_active',
    ];

    /**
     * DB 에 쓸 수 있는 페이지 필드.
     *
     * 페이지 블록 킷은 레이아웃을 자기 안에 담으므로 site_config 를 건드리지 않는다(설계 5.6).
     */
    private const PAGE_ALLOWED_FIELDS = [
        'page_code', 'page_title', 'page_description',
        'seo_title', 'seo_description', 'seo_keywords',
        'layout_type', 'use_fullpage', 'custom_width',
        'sidebar_left_width', 'sidebar_left_mobile',
        'sidebar_right_width', 'sidebar_right_mobile',
        'use_header', 'use_footer', 'allow_level', 'page_config',
        'is_active',
    ];

    /**
     * 블록 킷이 건드릴 수 있는 site_config 키.
     *
     * seo_config.custom_head_script 처럼 임의 스크립트를 삽입할 수 있는 버킷이 있으므로,
     * 블록 킷이 쓸 수 있는 키를 레이아웃으로 명시적으로 제한한다(설계 9.3).
     */
    private const SITE_CONFIG_ALLOWED_KEYS = BlockKitExporter::MAIN_SITE_CONFIG_KEYS;

    private const MAIN_SCREEN = 'main';
    private const MAIN_SCREEN_POSITIONS = [
        'topbar', 'subhead', 'left', 'contenthead', 'index', 'contentfoot', 'right', 'subfoot',
    ];

    /** @var array<string, true> */
    private array $obsoleteImagesAfterApply = [];

    public function __construct(
        private BlockContentSanitizer $contentSanitizer,
        private BlockRowRepository $rowRepository,
        private BlockColumnRepository $columnRepository,
        private BlockPageRepository $pageRepository,
        private BlockRenderService $renderService,
        private DomainSettingsService $domainSettingsService,
        private InstallIdProvider $installIdProvider,
        private ExtensionCompatibility $compatibility,
        BlockColumnPayloadNormalizer $columnNormalizer,
        private MainScreenComposition $mainScreenComposition,
        private ?EventDispatcher $eventDispatcher = null,
        private ?BlockRowRevisionRepository $revisionRepository = null,
        private ?BlockImageProcessor $imageProcessor = null,
        private ?BlockColumnContentService $columnWriter = null
    ) {
        $this->columnNormalizer = $columnNormalizer;
    }

    public function dryRunFromJson(string $json): array
    {
        $bytes = strlen($json);
        if ($bytes > self::MAX_KIT_BYTES) {
            return [
                'ok' => false,
                'errors' => ['블록 킷 파일은 2 MiB를 초과할 수 없습니다.'],
                'warnings' => [],
                'summary' => ['bytes' => $bytes],
            ];
        }

        $kit = json_decode($json, true);
        if (!is_array($kit)) {
            return [
                'ok' => false,
                'errors' => ['유효한 JSON 블록 킷이 아닙니다.'],
                'warnings' => [],
                'summary' => ['bytes' => $bytes],
            ];
        }

        return $this->dryRun($kit, ['bytes' => $bytes]);
    }

    public function dryRun(array $kit, array $context = []): array
    {
        $errors = [];
        $warnings = [];
        $missingTypes = [];
        $containsScriptActual = false;
        $normalizedRows = [];
        $needsSetup = [];
        $inlineImageColumns = [];
        $bgImageRows = [];

        // 블록 킷이 적어 온 provider 는 **판단에 쓰지 않는다.** 적용 여부는 BlockRegistry 가 정한다.
        // 사용자에게 무엇을 설치해야 하는지 알려주는 문구용이다(설계 4.3).
        $providers = $this->resolveProviders($kit);

        // 이미지 경로는 그것을 만든 설치에서만 유효하다. 같은 설치로 되돌리는 clone 백업만
        // 그대로 살린다. 그 외에는 전부 걷어낸다 — 배포 블록 킷이든 남의 clone 블록 킷이든(설계 4.4/7.2).
        $keepDomainImages = ($kit['export_mode'] ?? null) === 'clone'
            && $this->installIdProvider->matches($kit['source_install'] ?? null);

        // 형식 버전 검증 (계획 9.1.1) — 알 수 없는 상위 버전을 조용히 왜곡
        // 설치하지 않고 명시적으로 거부한다. 버전이 없거나 1.0/1.1 이면 처리.
        $formatVersion = (string) ($kit['format_version'] ?? BlockKitExporter::KIT_FORMAT_VERSION);
        $supportedVersions = [BlockKitExporter::KIT_FORMAT_VERSION, BlockKitExporter::KIT_FORMAT_VERSION_STACK];
        if (!in_array($formatVersion, $supportedVersions, true)) {
            $errors[] = "지원하지 않는 블록 킷 형식 버전입니다: {$formatVersion} — 더 높은 버전의 Mublo 에서 만든 킷일 수 있습니다. Mublo 를 업데이트한 뒤 다시 시도해 주세요.";
        }

        if (($kit['format'] ?? null) !== BlockKitExporter::KIT_FORMAT) {
            $errors[] = '지원하지 않는 블록 킷 format입니다.';
        }

        $rows = $kit['rows'] ?? null;
        if (empty($rows) || !is_array($rows)) {
            $errors[] = '블록 킷에 rows 배열이 없습니다.';
            $rows = [];
        }

        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                $errors[] = "rows[{$rowIndex}]가 객체가 아닙니다.";
                continue;
            }

            $normalizedRow = $this->stripFields($row, self::ROW_OMIT_FIELDS);
            if (!$keepDomainImages) {
                $normalizedRow = $this->stripDomainImages($normalizedRow);
            }

            // needs_setup 은 칸 단위라 행 배경은 실을 곳이 없다. 그냥 흘려보내면
            // 운영자는 배경이 왜 비었는지 알 수 없으므로 경고로 남긴다.
            if (!empty($normalizedRow['kit_needs_bg_image'])) {
                $bgImageRows[] = ((int) $rowIndex + 1) . '행';
            }

            $normalizedRow['columns'] = [];
            $candidateColumns = [];

            $columns = $row['columns'] ?? [];
            if (!is_array($columns)) {
                $errors[] = "rows[{$rowIndex}].columns가 배열이 아닙니다.";
                $columns = [];
            }

            foreach ($columns as $columnIndex => $column) {
                if (!is_array($column)) {
                    $errors[] = "rows[{$rowIndex}].columns[{$columnIndex}]가 객체가 아닙니다.";
                    continue;
                }

                // 블록 킷이 이미지를 실어 왔다면(손으로 고쳤거나 옛 블록 킷이거나) 여기서 다시 걷어낸다.
                // 내보내기를 믿지 않는다 — 남의 설치를 가리키는 경로는 반드시 깨진다(설계 4.4).
                if (!$keepDomainImages) {
                    $column = $this->stripDomainImages($column);
                }

                // 본문 HTML 안에 박아 넣은 업로드 경로는 구조가 아니라 콘텐츠다. 걷어낼 수는
                // 없지만(HTML 을 다시 쓰는 셈이다) 깨진 이미지가 뜰 것임은 알려 줄 수 있다.
                if (!$keepDomainImages && $this->hasInlineUploadPath($column)) {
                    $inlineImageColumns[] = ((int) $rowIndex + 1) . '행 ' . ((int) $columnIndex + 1) . '칸';
                }

                if ($this->contentSanitizer->columnContainsScript($column)) {
                    $containsScriptActual = true;
                }

                // stack의 scalar는 호환 미러이므로 실제 원본인 contents만 검사한다.
                // single 칸은 칸 자체가 콘텐츠 노드다.
                $isStack = ($column['content_mode'] ?? null) === 'stack';
                $contentIndex = 0;
                foreach (BlockContentTree::contentNodes($column) as $content) {
                    $contentType = $content['content_type'] ?? null;
                    if (is_string($contentType)
                        && $contentType !== ''
                        && !BlockRegistry::hasContentType($contentType)
                    ) {
                        $missingTypes[$contentType] = $contentType;
                    }

                    // "설정이 필요한 콘텐츠" 체크리스트(설계 8.3). kit_hint 는 DB 에 저장하지 않고
                    // 적용 결과 화면에서만 쓴다.
                    $setupReason = $this->resolveSetupReason($content);
                    if ($setupReason !== null) {
                        // 인덱스는 블록 킷이 통제하는 값이다. 관리자 화면이 그대로 출력하므로
                        // 반드시 정수로 강제한다.
                        $setup = [
                            'row_index' => (int) $rowIndex,
                            'column_index' => (int) ($column['column_index'] ?? $columnIndex),
                            'content_type' => is_string($contentType) ? $contentType : null,
                            'reason' => $setupReason,
                            'provider' => $setupReason === 'extension_missing' && is_string($contentType)
                                ? ($providers[$contentType]['label'] ?? null)
                                : null,
                            'kit_hint' => isset($content['kit_hint']) && is_string($content['kit_hint'])
                                ? $content['kit_hint']
                                : null,
                        ];
                        if ($isStack) {
                            $setup['content_index'] = $contentIndex;
                        }
                        $needsSetup[] = $setup;
                    }
                    $contentIndex++;
                }

                $normalizedColumn = $this->stripFields($column, self::COLUMN_OMIT_FIELDS);
                $candidateColumns[] = $normalizedColumn;
            }

            $normalization = $this->columnNormalizer->normalizeMany(
                $candidateColumns,
                BlockColumnWriteContext::kitImport((int) ($context['domain_id'] ?? 0))
            );

            foreach ($normalization->getErrors() as $error) {
                $errors[] = "rows[{$rowIndex}].columns[{$error['column_index']}]: {$error['message']}";
            }
            foreach ($normalization->getWarnings() as $warning) {
                // 미설치 타입은 아래 missingTypes 경로에서 provider 안내를 포함한
                // 더 친절한 경고로 한 번만 전달한다.
                if ($warning['code'] === 'unresolved_extension') {
                    continue;
                }
                $warnings[] = "rows[{$rowIndex}].columns[{$warning['column_index']}]: {$warning['message']}";
            }
            $normalizedRow['columns'] = $normalization->getNormalizedColumns();

            $normalizedRows[] = $normalizedRow;
        }

        // 블록 킷의 position 이 현재 레이아웃에서 렌더되는지 확인한다(설계 5.5).
        // LayoutType::getAvailablePositions() 는 지금껏 호출처가 없던 데드코드다.
        if ($positionWarning = $this->checkPositionRenderable($kit)) {
            $warnings[] = $positionWarning;
        }

        // 페이지 블록 킷은 page 객체가 자기완결적이어야 한다. NOT NULL 컬럼이 빠지면
        // INSERT 가 DB 에서 터지므로, 여기서 사유가 분명한 오류로 바꾼다.
        foreach ($this->validatePageTarget($kit) as $error) {
            $errors[] = $error;
        }

        foreach ($this->validateMainScreenTarget($kit, $normalizedRows) as $error) {
            $errors[] = $error;
        }

        if (($kit['target']['kind'] ?? null) === 'screen') {
            $warnings[] = '메인화면 킷은 subhead·contenthead·사이드바 등 전역 슬롯을 포함할 수 있어 다른 화면의 공용 영역에도 영향을 줍니다.';
        }

        if (($kit['contains_script'] ?? false) !== true && $containsScriptActual) {
            $errors[] = 'contains_script=false로 표시된 블록 킷에 실제 JS 콘텐츠가 포함되어 있습니다.';
        }

        // clone 블록 킷은 숫자 PK 를 그대로 담는다. 다른 설치에 적용하면 엉뚱한 데이터를 가리킨다(설계 7.2).
        if (($kit['export_mode'] ?? null) === 'clone'
            && !$this->installIdProvider->matches($kit['source_install'] ?? null)) {
            $warnings[] = '다른 설치에서 만든 복제/백업 블록 킷입니다. 배너·상품 등의 참조가 엉뚱한 데이터를 가리킬 수 있습니다.';
        }

        $coreWarning = $this->checkCoreVersion($kit);
        if ($coreWarning !== null) {
            $warnings[] = $coreWarning;
        }

        if ($bgImageRows !== []) {
            $warnings[] = '행 배경 이미지는 블록 킷에 담기지 않습니다('
                . implode(', ', $bgImageRows) . '). 직접 지정해 주세요.';
        }

        if ($inlineImageColumns !== []) {
            $warnings[] = 'HTML 안에 업로드 이미지 경로가 들어 있어 깨져 보입니다('
                . implode(', ', array_slice($inlineImageColumns, 0, 5))
                . (count($inlineImageColumns) > 5 ? ' 외 ' . (count($inlineImageColumns) - 5) . '개' : '')
                . '). 이미지를 다시 넣어 주세요.';
        }

        foreach ($missingTypes as $type) {
            $provider = $providers[$type] ?? null;

            $warnings[] = $provider === null
                ? "설치되지 않은 블록 타입입니다: {$type}"
                : "'{$type}' 블록을 표시하려면 {$provider['label']}를 설치해야 합니다.";
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => [
                'bytes' => $context['bytes'] ?? null,
                'row_count' => count($normalizedRows),
                'column_count' => $this->countColumns($normalizedRows),
                'missing_block_types' => array_values($missingTypes),
                'contains_script' => $containsScriptActual,
                'needs_setup' => $needsSetup,
                'target_kind' => $kit['target']['kind'] ?? null,
                'target_screen' => $kit['target']['screen'] ?? null,
                'site_settings_required' => ($kit['target']['kind'] ?? null) === 'screen',
            ],
            'normalized_rows' => $normalizedRows,
        ];
    }

    /**
     * 이 칸이 "설정이 필요한 칸"인지 판단하고 사유를 반환한다(설계 8.3).
     *
     * @param array<string, mixed> $column
     * @return string|null 설정이 필요 없으면 null
     */
    /**
     * 블록 킷이 요구하는 코어 버전을 확인한다(설계 6.3).
     *
     * **차단하지 않고 경고만 한다.** 적용 가능 여부를 실질적으로 결정하는 것은 어차피
     * block_types 검사다. 코어 버전은 "이 블록 킷은 더 새 코어를 염두에 두고 만들어졌다" 는
     * 힌트일 뿐이고, 대개는 그래도 잘 붙는다.
     *
     * 제약 문법은 확장 활성화 게이트와 같은 파서를 쓴다. 해석할 수 없는 제약은 만족으로
     * 보므로(ExtensionCompatibility::satisfies), 이상한 문자열이 블록 킷을 막지 못한다.
     *
     * @param array<string, mixed> $kit
     */
    private function checkCoreVersion(array $kit): ?string
    {
        $constraint = $kit['requires']['core'] ?? null;
        if (!is_string($constraint) || trim($constraint) === '') {
            return null;
        }

        if ($this->compatibility->satisfies(Application::VERSION, $constraint)) {
            return null;
        }

        // 제약 문자열은 블록 킷이 실어 온 남의 값이다. 관리자 화면이 이스케이프하지만
        // 길이를 잘라 경고창이 통째로 밀리는 것을 막는다.
        $shown = mb_strimwidth(trim($constraint), 0, 40, '…');

        return "이 블록 킷은 코어 {$shown} 를 요구하지만 현재 코어는 " . Application::VERSION
            . ' 입니다. 일부 블록이 의도대로 보이지 않을 수 있습니다.';
    }

    /**
     * 블록 킷의 requires.block_types 에서 "무엇을 설치해야 하는가"를 뽑는다.
     *
     * 이 값은 **판단에 쓰지 않는다.** 적용 가능 여부는 BlockRegistry::hasContentType() 이
     * 결정한다. 블록 킷이 provider 를 틀리게 적거나 거짓말해도 안전한 이유다(설계 4.3).
     *
     * 다만 문자열이 관리자 화면에 찍히므로 형식을 검증한다. 확장 이름은 PHP 네임스페이스
     * 조각이므로 영숫자와 언더스코어뿐이다.
     *
     * @param array<string, mixed> $kit
     * @return array<string, array{name: string, label: string}> content_type => provider
     */
    private function resolveProviders(array $kit): array
    {
        $blockTypes = $kit['requires']['block_types'] ?? null;
        if (!is_array($blockTypes)) {
            return [];
        }

        $providers = [];

        foreach ($blockTypes as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $type = $entry['type'] ?? null;
            $name = $entry['provider'] ?? null;

            if (!is_string($type) || !is_string($name)) {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9_]{1,40}$/', $name) !== 1) {
                continue; // 블록 킷이 실어 온 값이다. 형식이 어긋나면 쓰지 않는다.
            }

            // kind 를 모르면 "무엇을" 설치하라고 말할 수 없다. 이름만 던지느니 물러난다.
            // 덤으로 라벨이 언제나 '패키지'/'플러그인' 으로 끝나 조사가 '를' 로 고정된다.
            $label = match ($entry['kind'] ?? null) {
                'PACKAGE' => "{$name} 패키지",
                'PLUGIN' => "{$name} 플러그인",
                default => null,
            };

            if ($label === null) {
                continue;
            }

            $providers[$type] = ['name' => $name, 'label' => $label];
        }

        return $providers;
    }

    /**
     * 페이지 블록 킷의 page 객체를 검증한다.
     *
     * block_pages.page_title 은 NOT NULL 이고 기본값이 없다. 검증하지 않으면
     * 손으로 다듬은 블록 킷이 INSERT 단계에서 터지고, 사용자는 어느 필드가 문제인지 알 수 없다.
     *
     * @param array<string, mixed> $kit
     * @return string[]
     */
    private function validatePageTarget(array $kit): array
    {
        if (($kit['target']['kind'] ?? null) !== 'page') {
            return [];
        }

        $errors = [];

        if (trim((string) ($kit['target']['page_code'] ?? '')) === '') {
            $errors[] = '페이지 블록 킷의 target.page_code가 비어 있습니다.';
        }

        $page = $kit['page'] ?? null;
        if (!is_array($page)) {
            $errors[] = '페이지 블록 킷에 page 객체가 없습니다.';
            return $errors;
        }

        if (trim((string) ($page['page_title'] ?? '')) === '') {
            $errors[] = '페이지 블록 킷의 page.page_title이 비어 있습니다.';
        }

        return $errors;
    }

    /**
     * 메인화면 킷의 복합 슬롯과 site_config를 함께 검증한다.
     *
     * screen 종류는 현재 main만 지원한다. 행이 target.slots 밖으로 빠져나가거나 메뉴 전용
     * 행을 가장하면 공용 영역을 예측하지 못하게 바꾸므로 적용 전에 차단한다.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return string[]
     */
    private function validateMainScreenTarget(array $kit, array $rows): array
    {
        if (($kit['target']['kind'] ?? null) !== 'screen') {
            return [];
        }

        $errors = [];
        $target = is_array($kit['target'] ?? null) ? $kit['target'] : [];

        if (($target['screen'] ?? null) !== self::MAIN_SCREEN) {
            $errors[] = 'screen 블록 킷은 현재 target.screen=main만 지원합니다.';
        }

        $slots = $target['slots'] ?? null;
        if (!is_array($slots) || $slots === []) {
            $errors[] = '메인화면 블록 킷에 target.slots 배열이 없습니다.';
            $slots = [];
        } else {
            $slots = array_values($slots);
            $validSlots = true;
            foreach ($slots as $slot) {
                if (!is_string($slot) || !in_array($slot, self::MAIN_SCREEN_POSITIONS, true)) {
                    $errors[] = '메인화면 블록 킷에 지원하지 않는 슬롯이 있습니다.';
                    $validSlots = false;
                    break;
                }
            }
            if ($validSlots && count($slots) !== count(array_unique($slots))) {
                $errors[] = '메인화면 블록 킷의 target.slots에 중복 위치가 있습니다.';
            }
        }

        $siteConfig = $kit['site_settings']['site_config'] ?? null;
        if (!is_array($siteConfig)
            || !array_key_exists('layout_type', $siteConfig)
            || !array_key_exists('use_main_layout', $siteConfig)) {
            $errors[] = '메인화면 블록 킷에는 layout_type과 use_main_layout 사이트 설정이 필요합니다.';
        } elseif ($slots !== $this->mainScreenComposition->slots($siteConfig)) {
            $errors[] = '메인화면 블록 킷의 슬롯 구성이 site_settings 레이아웃과 일치하지 않습니다.';
        }

        foreach ($rows as $index => $row) {
            $position = $row['position'] ?? null;
            if (!is_string($position) || !in_array($position, $slots, true)) {
                $errors[] = 'rows[' . $index . '].position이 메인화면 target.slots에 속하지 않습니다.';
            }
            if (($row['position_menu'] ?? null) !== null && ($row['position_menu'] ?? '') !== '') {
                $errors[] = 'rows[' . $index . ']은 메뉴 전용 행이라 메인화면 킷에 포함할 수 없습니다.';
            }
        }

        return $errors;
    }

    private function resolveSetupReason(array $column): ?string
    {
        $contentType = $column['content_type'] ?? null;

        if (is_string($contentType) && $contentType !== '') {
            if (!BlockRegistry::hasContentType($contentType)) {
                return 'extension_missing';
            }

            // options['hasItems'] 는 판단 근거가 될 수 없다 — product_auto 는 hasItems=true 지만
            // content_items 를 저장하지 않아 언제나 오탐이 된다. 대신 내보내기가 "참조가 있었는데
            // 비웠다"고 표시해 둔 칸만 신뢰한다(BlockKitExporter, 설계 7.3).
            if (!empty($column['kit_needs_items']) && empty($column['content_items'])) {
                return 'items_empty';
            }
        }

        // 배경/타이틀 이미지는 콘텐츠라 블록 킷에 실리지 않는다. 원래 있던 칸만 표시한다(설계 4.4).
        // content_type 이 비어 있어도 배경만 있는 장식 칸일 수 있으므로 위 분기 밖에 둔다.
        if (!empty($column['kit_needs_bg_image']) || !empty($column['kit_needs_title_image'])) {
            return 'image_missing';
        }

        return null;
    }

    /**
     * 칸의 HTML 안에 이 설치의 업로드 경로가 박혀 있는지 본다(설계 4.4).
     *
     * `/storage/...` 는 만든 사이트에만 존재하는 파일이다. 문자열 어디에 있든 — src, srcset,
     * style="background:url(...)", data 속성 — 가져온 사이트에서는 404 다.
     *
     * @param array<string, mixed> $column
     */
    private function hasInlineUploadPath(array $column): bool
    {
        foreach (BlockContentTree::contentNodes($column) as $content) {
            if ($this->valueContainsString($content['content_config'] ?? null, '/storage/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 도메인에 종속된 이미지 경로를 걷어낸다(설계 4.4).
     *
     * 내보내기가 이미 걷어냈지만 블록 킷은 제3자 파일이다. 손으로 고쳤거나, 이 규칙이 생기기 전에
     * 만들어진 블록 킷이면 경로가 그대로 실려 있다. 그대로 저장하면 남의 설치를 가리키는 경로가
     * DB 에 들어가 깨진 이미지가 뜬다. 여기서 다시 걷어내고, 걷어냈다는 사실을 표시해 둔다.
     *
     * 표시(kit_needs_*)는 필드 화이트리스트에 없으므로 DB 로 새어 나가지 않는다.
     *
     * @param array<string, mixed> $data row 또는 column 배열
     * @return array<string, mixed>
     */
    private function stripDomainImages(array $data): array
    {
        return BlockContentTree::mapAll(
            $data,
            static function (array $node): array {
                $background = $node['background_config'] ?? null;
                if (is_array($background) && !empty($background['image'])) {
                    unset($background['image']);
                    $node['background_config'] = $background;
                    $node['kit_needs_bg_image'] = true;
                }

                $title = $node['title_config'] ?? null;
                if (is_array($title)) {
                    foreach (['pc_image', 'mo_image'] as $field) {
                        if (!empty($title[$field])) {
                            unset($title[$field]);
                            $node['title_config'] = $title;
                            $node['kit_needs_title_image'] = true;
                        }
                    }
                }

                return $node;
            }
        );
    }

    private function valueContainsString(mixed $value, string $needle): bool
    {
        if (is_string($value)) {
            return str_contains($value, $needle);
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $nested) {
            if ($this->valueContainsString($nested, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 블록 킷의 position 이 현재 도메인 레이아웃에서 렌더되는지 확인한다(설계 5.5).
     *
     * 사이드바 블록 킷은 use_main_layout + layout_type 이 맞지 않으면 DB 에는 들어가지만
     * renderPosition() 이 호출조차 되지 않아 화면에 아무것도 나타나지 않는다.
     *
     * @param array<string, mixed> $kit
     */
    private function checkPositionRenderable(array $kit): ?string
    {
        if (($kit['target']['kind'] ?? null) !== 'position') {
            return null;
        }

        $position = $kit['target']['position'] ?? null;
        if (!is_string($position) || !in_array($position, ['left', 'right'], true)) {
            return null;
        }

        // 블록 킷이 레이아웃을 함께 요구한다면 적용 후 렌더된다 — 경고하지 않는다.
        $requested = $kit['site_settings']['site_config'] ?? [];
        if (is_array($requested) && !empty($requested['layout_type'])) {
            return null;
        }

        return "이 블록 킷의 '{$position}' 블록은 현재 레이아웃에서 렌더되지 않을 수 있습니다. "
            . '사이트 설정에서 사이드바 레이아웃과 메인화면 레이아웃 사용을 켜야 합니다.';
    }

    /**
     * 블록 킷을 현재 도메인에 적용한다.
     *
     * 도메인 격리 — 블록 킷 JSON 안의 domain_id 는 무시하고 인자로 받은 도메인에만 쓴다(설계 9.5).
     * 블록 3개 테이블을 하나의 트랜잭션으로 묶고, 커밋 후 캐시를 무효화한다(6.4).
     *
     * @param int $domainId 적용 대상 도메인
     * @param array<string, mixed> $kit 블록 킷 배열
     * @param string $mode append | replace
     * @param bool $applySiteSettings 블록 킷의 레이아웃 요구를 site_config 에 병합할지(설계 5.4 ⑥ — 별도 체크박스)
     * @param null|callable(array<string, mixed>): void $beforeCommit 블록·설정과 같은 DB 트랜잭션에 묶을 후속 기록
     * @return array{ok: bool, errors: string[], warnings: string[], summary: array<string, mixed>}
     */
    public function apply(
        int $domainId,
        array $kit,
        string $mode = self::MODE_APPEND,
        bool $applySiteSettings = false,
        bool $allowScripts = true,
        ?callable $beforeCommit = null
    ): array {
        $validation = $this->dryRun($kit, ['domain_id' => $domainId]);
        if (!$validation['ok']) {
            return $validation;
        }

        if (!$allowScripts && !empty($validation['summary']['contains_script'])) {
            $validation['ok'] = false;
            $validation['errors'][] = '직접 실행 JS가 포함된 블록 킷은 최고관리자만 적용할 수 있습니다.';
            return $validation;
        }

        if (!in_array($mode, [self::MODE_APPEND, self::MODE_REPLACE], true)) {
            $validation['ok'] = false;
            $validation['errors'][] = "지원하지 않는 적용 모드입니다: {$mode}";
            return $validation;
        }

        $target = $kit['target'] ?? [];
        $kind = $target['kind'] ?? null;

        if (!in_array($kind, ['position', 'page', 'screen'], true)) {
            $validation['ok'] = false;
            $validation['errors'][] = '블록 킷 target.kind는 position, page 또는 screen이어야 합니다.';
            return $validation;
        }

        // 페이지 블록 킷은 레이아웃을 page 객체 안에 담으므로 site_config 를 건드리지 않는다(설계 5.6)
        if ($kind === 'page') {
            $applySiteSettings = false;
        } elseif ($kind === 'screen') {
            // 메인화면은 슬롯 구성과 레이아웃 설정이 한 단위다. 설정을 빼면 같은 화면을 재현할 수 없다.
            $applySiteSettings = true;
        }

        // 되돌리기용 스냅샷 — 설정을 건드리기 전에 찍는다(설계 5.4 ⑤)
        $siteConfigSnapshot = $applySiteSettings ? $this->domainSettingsService->getSiteConfig($domainId) : null;

        $db = $this->rowRepository->getDb();
        $this->obsoleteImagesAfterApply = [];
        $db->beginTransaction();

        $pageMenuSync = null;

        try {
            $scope = match ($kind) {
                'page' => $this->applyPageKit($domainId, $kit, $validation['normalized_rows'], $mode),
                'screen' => $this->applyMainScreenKit($domainId, $kit, $validation['normalized_rows'], $mode),
                default => $this->applyPositionKit($domainId, $kit, $validation['normalized_rows'], $mode),
            };
            $pageMenuSync = $scope['_page_menu_sync'] ?? null;
            unset($scope['_page_menu_sync']);

            // 블록만 적용되고 레이아웃 설정이 실패하면 사이드바 블록 킷이 보이지 않는
            // 어중간한 상태가 되므로 같은 트랜잭션에 묶는다(설계 6.4).
            $siteConfigChanges = [];
            if ($applySiteSettings) {
                $siteConfigChanges = $this->mergeSiteConfig($domainId, $kit, $siteConfigSnapshot ?? []);
            }

            $summary = $validation['summary'] + $scope + [
                'mode' => $mode,
                'site_config_changes' => $siteConfigChanges,
                'site_config_snapshot' => $siteConfigSnapshot,
            ];

            // 적용 이력처럼 성공 사실과 분리되면 안 되는 기록은 커밋 직전에 실행한다.
            // 콜백 실패도 같은 catch로 들어가 블록·설정 변경까지 모두 롤백된다.
            if ($beforeCommit !== null) {
                $beforeCommit($summary);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();

            return [
                'ok' => false,
                'errors' => ['블록 킷 적용에 실패했습니다: ' . $e->getMessage()],
                'warnings' => $validation['warnings'],
                'summary' => $validation['summary'],
            ];
        }

        if (is_array($pageMenuSync)) {
            try {
                $this->eventDispatcher?->dispatch(new BlockPageMenuSyncEvent(
                    $domainId,
                    (int) $pageMenuSync['page_id'],
                    (string) $pageMenuSync['page_code'],
                    (string) $pageMenuSync['page_title']
                ));
            } catch (\Throwable $e) {
                // 블록 DB는 이미 커밋됐다. 후속 메뉴 동기화 실패를 적용 실패로 오인시키지 않는다.
                error_log('[BlockKitApplier] Page menu sync failed: ' . $e->getMessage());
                $validation['warnings'][] = '블록은 적용됐지만 페이지 메뉴 동기화에 실패했습니다.';
            }
        }

        // BlockSeeder 는 PDO 를 직접 써서 캐시를 무효화하지 않는다. 운영 중 적용에서는
        // 그것이 "적용 후 화면이 안 바뀌는" 버그로 나타나므로 명시적으로 무효화한다(9.5).
        // 블록 캐시와 도메인 캐시는 별개다 — DomainResolver 캐시는 saveSettings() 안에서 지워진다.
        try {
            $this->renderService->invalidateDomainCache($domainId);
        } catch (\Throwable $e) {
            error_log('[BlockKitApplier] Cache invalidation failed: ' . $e->getMessage());
            $validation['warnings'][] = '블록은 적용됐지만 캐시 즉시 갱신에 실패했습니다.';
        }
        try {
            $this->imageProcessor?->cleanupUnreferenced(array_keys($this->obsoleteImagesAfterApply));
        } catch (\Throwable $e) {
            // 이미 커밋된 블록을 적용 실패로 오해시키지 않고 고아 파일만 남겨 보존적으로 실패한다.
            error_log('[BlockKitApplier] Image cleanup failed: ' . $e->getMessage());
            $validation['warnings'][] = '블록은 적용됐지만 이전 이미지 정리를 완료하지 못했습니다.';
        }

        return [
            'ok' => true,
            'errors' => [],
            'warnings' => $validation['warnings'],
            'summary' => $summary,
        ];
    }

    /**
     * position 블록 킷 적용
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function applyPositionKit(int $domainId, array $kit, array $rows, string $mode): array
    {
        $target = $kit['target'];
        $position = (string) ($target['position'] ?? '');
        if ($position === '') {
            throw new \RuntimeException('블록 킷 target에 position이 없습니다.');
        }

        // 블록 킷이 담은 menuCode 스코프. 이것이 replace 삭제 범위와 정확히 일치해야 한다(6.2).
        $menuCode = isset($target['menu_code']) && $target['menu_code'] !== ''
            ? (string) $target['menu_code']
            : null;

        $deletedRows = 0;
        if ($mode === self::MODE_REPLACE) {
            $this->snapshotRowsForReplace(
                $this->rowRepository->findByPositionScope($domainId, $position, $menuCode)
            );
            // 대상 스코프만 지운다. block_columns 는 ON DELETE CASCADE 로 함께 지워진다.
            $deletedRows = $this->rowRepository->deleteByPosition($domainId, $position, $menuCode);
        }

        $sortOrder = $this->rowRepository->getNextSortOrderByPosition($domainId, $position);
        $created = $this->insertRows($domainId, $rows, $sortOrder, [
            'page_id' => null,
            'position' => $position,
            'position_menu' => $menuCode,
        ]);

        return [
            'target_kind' => 'position',
            'position' => $position,
            'menu_code' => $menuCode,
            'deleted_rows' => $deletedRows,
        ] + $created;
    }

    /**
     * 메인화면 복합 킷을 슬롯별 전역 스코프에 적용한다.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function applyMainScreenKit(int $domainId, array $kit, array $rows, string $mode): array
    {
        $target = $kit['target'];
        if (($target['screen'] ?? null) !== self::MAIN_SCREEN || !is_array($target['slots'] ?? null)) {
            throw new \RuntimeException('유효한 메인화면 target이 없습니다.');
        }

        $slots = array_values($target['slots']);
        $rowsByPosition = array_fill_keys($slots, []);
        foreach ($rows as $row) {
            $position = (string) ($row['position'] ?? '');
            if (!array_key_exists($position, $rowsByPosition)) {
                throw new \RuntimeException("메인화면 슬롯에 속하지 않는 행입니다: {$position}");
            }
            $rowsByPosition[$position][] = $row;
        }

        $deletedRows = 0;
        $createdRows = 0;
        $createdColumns = 0;

        foreach ($slots as $slot) {
            if ($mode === self::MODE_REPLACE) {
                $this->snapshotRowsForReplace(
                    $this->rowRepository->findByPositionScope($domainId, $slot, null)
                );
                $deletedRows += $this->rowRepository->deleteByPosition($domainId, $slot, null);
            }

            $created = $this->insertRows(
                $domainId,
                $rowsByPosition[$slot],
                $this->rowRepository->getNextSortOrderByPosition($domainId, $slot),
                [
                    'page_id' => null,
                    'position' => $slot,
                    'position_menu' => null,
                ]
            );
            $createdRows += $created['created_rows'];
            $createdColumns += $created['created_columns'];
        }

        return [
            'target_kind' => 'screen',
            'screen' => self::MAIN_SCREEN,
            'slots' => $slots,
            'deleted_rows' => $deletedRows,
            'created_rows' => $createdRows,
            'created_columns' => $createdColumns,
        ];
    }

    /**
     * 페이지 블록 킷 적용
     *
     * 같은 page_code 의 페이지가 있으면 재사용하고, 없으면 블록 킷의 page 객체로 만든다.
     * 페이지 레이아웃은 자기완결적이라 다른 화면에 부작용이 없다(설계 5.6).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function applyPageKit(int $domainId, array $kit, array $rows, string $mode): array
    {
        $pageCode = (string) ($kit['target']['page_code'] ?? '');
        if ($pageCode === '') {
            throw new \RuntimeException('블록 킷 target에 page_code가 없습니다.');
        }

        $pageData = $kit['page'] ?? [];
        if (!is_array($pageData)) {
            throw new \RuntimeException('블록 킷에 page 객체가 없습니다.');
        }

        // uk_domain_code 는 is_deleted 를 포함하지 않는 UNIQUE 키다. 소프트 삭제된 페이지가
        // 같은 코드를 점유하고 있으면 create() 가 중복키로 터진다 — 그 행을 되살려 쓴다.
        $existing = $this->pageRepository->findByCodeIncludingDeleted($domainId, $pageCode);
        $createdPage = false;
        $revivedPage = $existing?->isDeleted() ?? false;
        $deletedRows = 0;
        $pageTitle = (string) ($pageData['page_title'] ?? '');

        if ($existing) {
            $pageId = $existing->getPageId();

            if ($mode === self::MODE_REPLACE) {
                $this->snapshotRowsForReplace($this->rowRepository->findAllByPage($pageId));
                $deletedRows = $this->rowRepository->deleteByPage($pageId);

                // 교체는 "이 페이지를 블록 킷으로 바꾼다"는 뜻이다. 행만 갈고 레이아웃을 그대로 두면
                // 내보내기→적용 왕복에서 layout_type·SEO 등이 조용히 유실된다.
                $update = $this->castBoolsToInt($this->pickFields($pageData, self::PAGE_ALLOWED_FIELDS));
                unset($update['page_code']);
                $update['is_deleted'] = 0;
                $this->pageRepository->update($pageId, $this->encodeJsonFields($update, ['page_config']));
            } elseif ($existing->isDeleted()) {
                // 이어붙이기라도 삭제된 페이지에 행만 넣으면 화면에 나타나지 않는다.
                $this->pageRepository->update($pageId, ['is_deleted' => 0]);
                $pageTitle = $existing->getPageTitle();
            }
        } else {
            $insert = $this->castBoolsToInt($this->pickFields($pageData, self::PAGE_ALLOWED_FIELDS));
            $insert['domain_id'] = $domainId;
            $insert['page_code'] = $pageCode;
            $insert = $this->encodeJsonFields($insert, ['page_config']);

            $pageId = $this->pageRepository->create($insert);
            if (!$pageId) {
                throw new \RuntimeException('페이지 생성에 실패했습니다.');
            }
            $createdPage = true;
        }

        $sortOrder = $this->rowRepository->getNextSortOrderByPage($pageId);
        $created = $this->insertRows($domainId, $rows, $sortOrder, [
            'page_id' => $pageId,
            'position' => null,
            'position_menu' => null,
        ]);

        return [
            'target_kind' => 'page',
            'page_code' => $pageCode,
            'page_id' => $pageId,
            'created_page' => $createdPage,
            'deleted_rows' => $deletedRows,
            // 기존 페이지의 제목도 replace 적용에서 바뀔 수 있으므로 항상 동기화한다.
            '_page_menu_sync' => [
                'page_id' => $pageId,
                'page_code' => $pageCode,
                'page_title' => $pageTitle,
            ],
        ] + $created;
    }

    /**
     * 정규화된 행/칸을 삽입한다.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $overrides 블록 킷 값을 이기는 소속 정보(도메인 격리)
     * @return array{created_rows: int, created_columns: int}
     */
    private function insertRows(int $domainId, array $rows, int $sortOrder, array $overrides): array
    {
        $createdRows = 0;
        $createdColumns = 0;

        foreach ($rows as $row) {
            $rowData = $this->castBoolsToInt($this->pickFields($row, self::ROW_ALLOWED_FIELDS));
            $rowData = array_replace($rowData, $overrides);
            $rowData['domain_id'] = $domainId;
            $rowData['sort_order'] = $sortOrder++;
            $rowData = $this->encodeJsonFields($rowData, ['background_config']);

            $rowId = $this->rowRepository->create($rowData);
            if (!$rowId) {
                throw new \RuntimeException('행 생성에 실패했습니다.');
            }
            $createdRows++;

            $columns = [];
            foreach ($row['columns'] ?? [] as $column) {
                $picked = $this->castBoolsToInt($this->pickFields($column, self::COLUMN_ALLOWED_FIELDS));

                // 1.1 스택 칸 — contents 를 함께 나른다 (콘텐츠 필드만 통과)
                if (($column['content_mode'] ?? null) === 'stack'
                    && isset($column['contents']) && is_array($column['contents'])
                ) {
                    $picked['content_mode'] = 'stack';
                    foreach (['pc_content_gap', 'mobile_content_gap'] as $gapField) {
                        if (isset($column[$gapField])) {
                            $picked[$gapField] = (int) $column[$gapField];
                        }
                    }
                    $picked['contents'] = array_map(
                        fn (array $content) => $this->castBoolsToInt(
                            $this->pickFields($content, self::CONTENT_ALLOWED_FIELDS)
                        ),
                        array_values(array_filter($column['contents'], 'is_array'))
                    );
                }

                $columns[] = $picked;
            }

            if ($columns !== []) {
                if ($this->columnWriter !== null) {
                    // 스택 인지 저장 — 새 행이므로 전량 INSERT (계획 6.2.0 경로)
                    $this->columnWriter->saveColumns($rowId, $domainId, $columns);
                } else {
                    // columnWriter 미주입 폴백 — contents 는 INSERT 컬럼이 아니므로 제거
                    foreach ($columns as $i => $column) {
                        unset($column['contents'], $column['content_mode'], $column['pc_content_gap'], $column['mobile_content_gap']);
                        $columns[$i] = $column;
                    }
                    $this->columnRepository->replaceByRow($rowId, $domainId, $columns);
                }
                $createdColumns += count($columns);
            }
        }

        return ['created_rows' => $createdRows, 'created_columns' => $createdColumns];
    }

    /**
     * 블록 킷이 요구하는 레이아웃 키를 현재 site_config 에 병합한다.
     *
     * 통째로 덮어쓰지 않는다 — sanitizeSiteConfig() 는 전달받은 배열을 기본값으로 재구성하므로,
     * 레이아웃 키만 넘기면 site_title·editor 등이 함께 날아간다(설계 5.4 ①②).
     *
     * 저장은 반드시 DomainSettingsService 를 거친다. 화이트리스트 검증과
     * DomainResolver 캐시 무효화가 여기 붙어 있다(5.4 ⑦).
     *
     * @param array<string, mixed> $kit
     * @param array<string, mixed> $currentConfig
     * @return array<string, array{from: mixed, to: mixed}> 실제로 바뀐 키
     */
    private function mergeSiteConfig(int $domainId, array $kit, array $currentConfig): array
    {
        $requested = $kit['site_settings']['site_config'] ?? null;
        if (!is_array($requested) || $requested === []) {
            return [];
        }

        // 블록 킷이 쓸 수 있는 키를 레이아웃으로 제한한다(설계 9.3)
        $requested = array_intersect_key($requested, array_flip(self::SITE_CONFIG_ALLOWED_KEYS));
        if ($requested === []) {
            return [];
        }

        $merged = array_replace($currentConfig, $requested);

        $result = $this->domainSettingsService->saveSettings($domainId, ['site' => $merged]);
        if ($result->isFailure()) {
            throw new \RuntimeException('사이트 설정 저장에 실패했습니다: ' . $result->getMessage());
        }

        $changes = [];
        foreach ($requested as $key => $value) {
            $before = $currentConfig[$key] ?? null;
            if ($before != $value) {
                $changes[$key] = ['from' => $before, 'to' => $value];
            }
        }

        return $changes;
    }

    private function stripFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            unset($data[$field]);
        }

        return $data;
    }

    /**
     * 화이트리스트에 있는 키만 남긴다.
     *
     * @param array<string, mixed> $data
     * @param string[] $allowed
     * @return array<string, mixed>
     */
    private function pickFields(array $data, array $allowed): array
    {
        return array_intersect_key($data, array_flip($allowed));
    }

    /**
     * 최상위 bool 값을 int 로 강제한다.
     *
     * 블록 킷 JSON 은 use_header·is_active 등을 true/false 로 담는다(내보내기가 그렇게 쓴다).
     * PDO 는 false 를 빈 문자열로 바인딩하므로 strict 모드의 INT 컬럼에서 INSERT 가 터진다.
     * 리포지토리는 폼 입력(이미 0/1)만 받아 왔으므로 블록 킷 경로인 여기서 맞춘다.
     *
     * 중첩 배열은 건드리지 않는다 — background_config 등은 JSON 으로 인코딩되므로
     * bool 이 그대로 남는 것이 맞다.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function castBoolsToInt(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $data[$key] = (int) $value;
            }
        }

        return $data;
    }

    /**
     * 배열로 들어온 JSON 컬럼을 인코딩한다.
     *
     * @param array<string, mixed> $data
     * @param string[] $fields
     * @return array<string, mixed>
     */
    private function encodeJsonFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
            }
        }

        return $data;
    }

    /** @param BlockRow[] $rows */
    private function snapshotRowsForReplace(array $rows): void
    {
        foreach ($rows as $row) {
            $columns = $this->columnRepository->findAllByRowForDomain(
                $row->getRowId(),
                $row->getDomainId()
            );
            $portableColumns = $this->columnWriter !== null
                ? $this->columnWriter->columnsToPortableArrays($columns)
                : array_map(static fn ($column) => $column->toArray(), $columns);

            if ($this->revisionRepository !== null) {
                $this->revisionRepository->create(
                    $row->getDomainId(),
                    $row->getRowId(),
                    $row->getRevisionNo(),
                    [
                        'row' => $row->toArray(),
                        'columns' => $portableColumns,
                    ],
                    'kit_replace'
                );
                $this->revisionRepository->prune($row->getDomainId(), $row->getRowId());
            }

            if ($this->imageProcessor !== null) {
                $values = [$row->getBackgroundConfig()];
                foreach ($portableColumns as $column) {
                    foreach (BlockContentTree::allNodes($column) as $node) {
                        $values[] = $node['background_config'] ?? null;
                        $values[] = $node['title_config'] ?? null;
                        $values[] = $node['content_config'] ?? null;
                        $values[] = $node['content_items'] ?? null;
                    }
                }
                foreach ($this->imageProcessor->collectManagedImages(...$values) as $url) {
                    $this->obsoleteImagesAfterApply[$url] = true;
                }
            }
        }
    }

    private function countColumns(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            if (isset($row['columns']) && is_array($row['columns'])) {
                $count += count($row['columns']);
            }
        }

        return $count;
    }
}
