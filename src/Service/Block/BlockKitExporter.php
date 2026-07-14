<?php
namespace Mublo\Service\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockPage;
use Mublo\Entity\Block\BlockRow;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;

/**
 * BlockKitExporter
 *
 * 기존 블록 행/칸 구성을 portable JSON kit 구조로 변환한다.
 */
class BlockKitExporter
{
    public const KIT_FORMAT = 'mublo-starter-kit';
    public const KIT_FORMAT_VERSION = '1.0';

    /** 스택 칸을 포함한 킷의 형식 버전 (계획 9.1 — 조건부 사용) */
    public const KIT_FORMAT_VERSION_STACK = '1.1';

    /** 메인화면 킷이 함께 운반할 수 있는 domain_config.site_config 키. */
    public const MAIN_SITE_CONFIG_KEYS = [
        'layout_type', 'use_main_layout',
        'layout_left_width', 'layout_right_width',
        'sidebar_left_mobile', 'sidebar_right_mobile',
        'layout_max_width', 'content_max_width',
    ];

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

    /** 스택 콘텐츠에서 portable kit 에 싣지 않는 필드 (ID·소속·시각 — 순서는 배열이 진실) */
    private const CONTENT_OMIT_FIELDS = [
        'content_id',
        'column_id',
        'domain_id',
        'sort_order',
        'created_at',
        'updated_at',
    ];

    /** 스택 칸의 이중 표현 scalar 가 미러하는 콘텐츠 필드 (계획 9.1) */
    private const CONTENT_MIRROR_FIELDS = [
        'title_config', 'content_type', 'content_kind', 'content_skin', 'content_config', 'content_items',
    ];

    private const PAGE_OMIT_FIELDS = [
        'page_id',
        'domain_id',
        'created_at',
        'updated_at',
    ];

    public function __construct(
        private BlockRowRepository $rowRepository,
        private BlockColumnRepository $columnRepository,
        private BlockContentSanitizer $contentSanitizer,
        private MainScreenComposition $mainScreenComposition,
        private ?\Mublo\Repository\Block\BlockColumnContentRepository $contentRepository = null
    ) {
    }

    /**
     * position 블록 킷 내보내기
     *
     * menuCode는 target에 함께 기록한다. 그러지 않으면 replace 모드가
     * 삭제 범위를 target과 일치시킬 수 없다(설계 6.2).
     */
    public function exportPosition(
        int $domainId,
        string $position,
        ?string $menuCode = null,
        array $meta = [],
        array $options = []
    ): array {
        $rows = array_values(array_filter(
            $this->rowRepository->findAllByPosition($domainId, $position),
            fn (BlockRow $row) => ($row->getPositionMenu() ?: null) === ($menuCode ?: null)
        ));

        $target = [
            'kind' => 'position',
            'position' => $position,
        ];

        if ($menuCode) {
            $target['menu_code'] = $menuCode;
        }

        return $this->exportRows(
            rows: $rows,
            target: $target,
            meta: $meta,
            options: $options
        );
    }

    /**
     * 메인화면 복합 블록 킷 내보내기.
     *
     * 메인화면은 단일 position이 아니라 site_config에 따라 여러 전역 슬롯을 조립한다.
     * 각 행의 position을 보존하고, 같은 화면을 재현하는 데 필요한 레이아웃 설정도 함께 담는다.
     */
    public function exportMainScreen(
        int $domainId,
        array $siteConfig,
        array $meta = [],
        array $options = []
    ): array {
        $slots = $this->mainScreenComposition->slots($siteConfig);
        $rows = [];

        foreach ($slots as $slot) {
            foreach ($this->rowRepository->findAllByPosition($domainId, $slot) as $row) {
                if (!$row instanceof BlockRow || ($row->getPositionMenu() ?: null) !== null) {
                    continue;
                }

                $rows[] = $row;
            }
        }

        $kit = $this->exportRows(
            rows: $rows,
            target: [
                'kind' => 'screen',
                'screen' => 'main',
                'slots' => $slots,
            ],
            meta: $meta,
            options: $options
        );

        $kit['site_settings'] = [
            'site_config' => array_intersect_key($siteConfig, array_flip(self::MAIN_SITE_CONFIG_KEYS)),
        ];

        return $kit;
    }

    /**
     * 페이지 블록 킷 내보내기
     *
     * 레이아웃이 page 객체 안에 자기완결적으로 담기므로 site_settings가 필요 없다(설계 5.6).
     */
    public function exportPage(BlockPage $page, array $meta = [], array $options = []): array
    {
        $kit = $this->exportRows(
            rows: $this->rowRepository->findAllByPage($page->getPageId()),
            target: [
                'kind' => 'page',
                'page_code' => $page->getPageCode(),
            ],
            meta: $meta,
            options: $options
        );

        $kit['page'] = $this->stripFields($page->toArray(), self::PAGE_OMIT_FIELDS);

        return $kit;
    }

    /**
     * @param BlockRow[] $rows
     */
    public function exportRows(array $rows, array $target, array $meta = [], array $options = []): array
    {
        $exportMode = $options['export_mode'] ?? 'distribution';
        $kitRows = [];
        $requiredTypes = [];
        $containsScript = false;
        $hasStackColumn = false;

        // 저작자가 체크한 칸의 column_id 집합. 배열 순서에 의존하지 않는 안정적인 키다.
        $includeItemsForColumns = array_map('intval', (array) ($options['include_content_items_by_column'] ?? []));

        foreach ($rows as $row) {
            if (!$row instanceof BlockRow) {
                continue;
            }

            $rowData = $this->stripFields($row->toArray(), self::ROW_OMIT_FIELDS);
            if ($exportMode !== 'clone') {
                $rowData = $this->stripDomainImages($rowData);
            }
            $rowData['columns'] = [];

            foreach ($this->columnRepository->findAllByRowForDomain(
                $row->getRowId(),
                $row->getDomainId()
            ) as $column) {
                if (!$column instanceof BlockColumn) {
                    continue;
                }

                $columnData = $this->stripFields($column->toArray(), self::COLUMN_OMIT_FIELDS);
                if ($exportMode !== 'clone') {
                    $columnData = $this->stripDomainImages($columnData);
                }
                $contentType = $columnData['content_type'] ?? null;

                if (is_string($contentType) && $contentType !== '') {
                    $requiredTypes[$contentType] = array_filter([
                        'type' => $contentType,
                        'kind' => $columnData['content_kind'] ?? 'CORE',
                        // 가져오는 쪽에는 확장이 없어 provider 를 알 수 없다.
                        // 내보내는 쪽에만 정보가 있으므로 여기서 적어 보낸다(설계 4.3).
                        'provider' => $this->resolveProvider($contentType),
                    ], fn($v) => $v !== null);
                }

                if ($this->contentSanitizer->columnContainsScript($columnData)) {
                    $containsScript = true;
                }

                // 저작자가 체크하지 않은 칸은 비운다. clone 모드는 전부 유지한다(설계 7.2).
                // banner 는 어느 모드에서든 비운다 — 담아 봐야 깨진 이미지만 뜬다(7.4).
                $contentItemsAllowed = $exportMode === 'clone'
                    || in_array($column->getColumnId(), $includeItemsForColumns, true);
                if ($contentType === 'banner' || !$contentItemsAllowed) {
                    $hadItems = !empty($columnData['content_items']);
                    $columnData['content_items'] = null;

                    // 원래 참조가 있었는데 비운 칸만 "설정이 필요한 칸"이다(설계 8.3).
                    // 코어는 어떤 타입이 참조를 쓰는지 알 수 없으므로(options['hasItems'] 는
                    // product_auto 처럼 참조를 저장하지 않는 타입도 true 다) 여기서 표시해 둔다.
                    if ($hadItems) {
                        $columnData['kit_needs_items'] = true;
                    }
                }

                // 스택 칸 — contents 를 같은 export 변환 파이프라인으로 처리한 뒤,
                // 변환 완료본의 첫 활성 콘텐츠에서 이중 표현 scalar 를 생성한다
                // (계획 9.1 처리 순서 — DB 미러 복사 금지)
                if ($column->isStack()) {
                    $hasStackColumn = true;
                    $columnData = $this->exportStackColumn(
                        $column,
                        $columnData,
                        $exportMode,
                        $contentItemsAllowed,
                        $requiredTypes,
                        $containsScript
                    );
                }

                $rowData['columns'][] = $columnData;
            }

            $kitRows[] = $rowData;
        }

        return [
            'format' => self::KIT_FORMAT,
            // 조건부 버전 (계획 9.1): 스택 칸이 하나라도 있으면 1.1, single 전용
            // 킷은 계속 1.0 — 구버전 Mublo 가 일반 킷을 계속 읽을 수 있다
            'format_version' => $hasStackColumn ? self::KIT_FORMAT_VERSION_STACK : self::KIT_FORMAT_VERSION,
            'export_mode' => $exportMode,
            'name' => $meta['name'] ?? 'Untitled block kit',
            'description' => $meta['description'] ?? '',
            'version' => $meta['version'] ?? '1.0.0',
            'author' => $meta['author'] ?? '',
            'author_url' => $meta['author_url'] ?? '',
            'screenshot' => $meta['screenshot'] ?? null,
            'target' => $target,
            'requires' => [
                // 저작자가 지정한다. 현재 코어 버전을 자동으로 박으면 블록 킷마다 불필요한
                // 하한선이 생겨, 멀쩡히 붙을 블록 킷이 경고를 달고 다닌다.
                'core' => $this->normalizeCoreConstraint($meta['requires_core'] ?? null),
                'block_types' => array_values($requiredTypes),
            ],
            'contains_script' => $containsScript,
            'rows' => $kitRows,
        ] + ($exportMode === 'clone' ? ['source_install' => $options['source_install'] ?? null] : []);
    }

    /**
     * 도메인에 종속된 이미지 경로를 걷어낸다(설계 4.4).
     *
     * 블록 킷은 구조를 나르고, 이미지는 운영자의 콘텐츠다. `/storage/2026/01/hero.jpg` 는
     * 만든 사이트에만 존재하는 파일이므로, 다른 설치에 그대로 실어 보내면 반드시
     * 깨진 이미지가 뜬다. 배경색·그라데이션·크기·정렬처럼 **값**인 것은 구조이므로 남긴다.
     *
     * 경계 규칙: 파일을 가리키면 콘텐츠, 값이면 구조.
     *
     * clone 모드는 같은 설치로 되돌리는 백업이므로 호출하지 않는다(설계 7.2).
     *
     * @param array<string, mixed> $data row 또는 column 배열
     * @return array<string, mixed>
     */
    /**
     * 스택 칸 export (계획 9.1).
     *
     * 각 콘텐츠에 칸과 동일한 변환 파이프라인(ID 제거·도메인 이미지 제거·
     * banner/미체크 items 비움·requires 합집합·스크립트 감지)을 적용하고,
     * **변환이 끝난** 첫 활성 콘텐츠에서 이중 표현 scalar 를 생성한다 —
     * 신규 importer 가 읽는 contents[] 와 구버전이 읽는 scalar 가 항상
     * 같은 변환 결과가 된다. 전 자식 비활성이면 scalar 를 비워 구버전에서
     * 빈 칸으로 강등된다.
     *
     * @param array<string, mixed> $requiredTypes (참조 전달)
     */
    private function exportStackColumn(
        BlockColumn $column,
        array $columnData,
        string $exportMode,
        bool $contentItemsAllowed,
        array &$requiredTypes,
        bool &$containsScript
    ): array {
        $contents = [];
        try {
            $contents = $this->contentRepository?->findByColumnForDomain(
                $column->getColumnId(),
                $column->getDomainId(),
                true
            ) ?? [];
        } catch (\Throwable) {
            // 콘텐츠 테이블 미설치 — scalar(레거시 미러)만으로 내보낸다
        }

        $contentsOut = [];
        foreach ($contents as $content) {
            $contentData = $this->stripFields($content->toArray(), self::CONTENT_OMIT_FIELDS);
            if ($exportMode !== 'clone') {
                $contentData = $this->stripDomainImages($contentData);
            }

            $contentType = $contentData['content_type'] ?? null;
            if (is_string($contentType) && $contentType !== '') {
                // requires.block_types 는 하위 콘텐츠 타입의 합집합 (계획 9.1) —
                // stack 자체는 타입이 아니므로 기록하지 않는다
                $requiredTypes[$contentType] = array_filter([
                    'type' => $contentType,
                    'kind' => $contentData['content_kind'] ?? 'CORE',
                    'provider' => $this->resolveProvider($contentType),
                ], fn ($v) => $v !== null);
            }

            if ($this->contentSanitizer->columnContainsScript($contentData)) {
                $containsScript = true;
            }

            if ($contentType === 'banner' || !$contentItemsAllowed) {
                $hadItems = !empty($contentData['content_items']);
                $contentData['content_items'] = null;
                if ($hadItems) {
                    $contentData['kit_needs_items'] = true;
                }
            }

            $contentsOut[] = $contentData;
        }

        $columnData['contents'] = $contentsOut;

        // 이중 표현 scalar — 변환 완료본의 첫 활성 콘텐츠 (없으면 비움)
        $firstActive = null;
        foreach ($contentsOut as $contentData) {
            if ((int) ($contentData['is_active'] ?? 1) === 1) {
                $firstActive = $contentData;
                break;
            }
        }
        foreach (self::CONTENT_MIRROR_FIELDS as $field) {
            $columnData[$field] = $firstActive[$field] ?? null;
        }
        if ($firstActive === null) {
            $columnData['content_kind'] = 'CORE';
        }

        return $columnData;
    }

    private function stripDomainImages(array $data): array
    {
        // background_config: image 만 파일이다. color·gradient·size·position·repeat 는 값이다.
        $background = $data['background_config'] ?? null;
        if (is_array($background) && !empty($background['image'])) {
            unset($background['image']);
            $data['background_config'] = $background;
            $data['kit_needs_bg_image'] = true;
        }

        // title_config: pc_image·mo_image 만 파일이다. text·color·size_* 는 값이다.
        $title = $data['title_config'] ?? null;
        if (is_array($title)) {
            $hadImage = false;

            foreach (['pc_image', 'mo_image'] as $field) {
                if (!empty($title[$field])) {
                    unset($title[$field]);
                    $hadImage = true;
                }
            }

            if ($hadImage) {
                $data['title_config'] = $title;
                $data['kit_needs_title_image'] = true;
            }
        }

        return $data;
    }

    /**
     * 저작자가 적은 코어 제약을 정리한다. 빈 값은 "요구 없음"(null)이다.
     */
    private function normalizeCoreConstraint(mixed $constraint): ?string
    {
        if (!is_string($constraint)) {
            return null;
        }

        $constraint = trim($constraint);

        return $constraint === '' ? null : $constraint;
    }

    private function stripFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            unset($data[$field]);
        }

        return $data;
    }

    /**
     * 이 블록 타입을 제공한 확장의 이름을 구한다.
     *
     * 가져오는 쪽에는 확장이 설치되어 있지 않아 레지스트리가 비어 있다. 내보내는 쪽에만
     * 정보가 있으므로 여기서 뽑아 블록 킷에 적어 보낸다. 그래야 가져오기가
     * "Shop 패키지를 설치하세요"라고 말할 수 있다.
     *
     * BlockRegistry 는 확장 이름을 저장하지 않으므로 renderer FQCN 의 네임스페이스 관례를
     * 파싱한다. 이 파싱은 여기에만 존재하며, 안정 API 인 BlockRegistry 를 건드리지 않는다.
     *
     *   Mublo\Packages\Shop\Block\ProductRenderer  → Shop
     *   Mublo\Plugin\Banner\Block\BannerRenderer   → Banner
     *   Mublo\Core\Block\Renderer\HtmlRenderer     → null (코어)
     *
     * 관례를 따르지 않는 확장은 null 이 되고, 저작자가 내보내기 폼에서 직접 적으면 된다.
     */
    private function resolveProvider(string $contentType): ?string
    {
        $registered = BlockRegistry::getContentType($contentType);
        $renderer = $registered['renderer'] ?? null;

        if (!is_string($renderer) || $renderer === '') {
            return null;
        }

        if (preg_match('/^Mublo\\\\(?:Packages|Plugin)\\\\([A-Za-z0-9_]+)\\\\/', $renderer, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

}
