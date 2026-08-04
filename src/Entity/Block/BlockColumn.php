<?php
declare(strict_types=1);
namespace Mublo\Entity\Block;

use DateTimeImmutable;
use Mublo\Contract\Block\BlockColumnView;
use Mublo\Enum\Block\BlockContentType;
use Mublo\Enum\Block\BlockContentKind;

/**
 * Class BlockColumn
 *
 * 블록 칸(Column) 엔티티
 *
 * 책임:
 * - block_columns 테이블의 데이터를 객체로 표현
 * - 블록의 개별 칸 설정 및 콘텐츠 관리
 *
 * 금지:
 * - DB 직접 접근
 * - 비즈니스 로직 (Service 담당)
 *
 * 렌더러에게는 이 엔티티가 아니라 BlockColumnView 로 좁혀서 건넨다.
 * 저장·정렬·스택 구성 표면은 코어 내부이며 확장 계약이 아니다.
 */
class BlockColumn implements BlockColumnView
{
    // ========================================
    // 기본 필드
    // ========================================
    protected int $columnId;
    protected int $rowId;
    protected int $domainId;

    // ========================================
    // 칸 정보
    // ========================================
    protected int $columnIndex;
    protected ?string $width;

    // ========================================
    // 여백
    // ========================================
    protected ?string $pcPadding;
    protected ?string $mobilePadding;

    // ========================================
    // 배경 설정 (JSON)
    // ========================================
    protected ?array $backgroundConfig;

    // ========================================
    // 테두리 설정 (JSON)
    // ========================================
    protected ?array $borderConfig;

    // ========================================
    // 제목/문구 설정 (JSON)
    // ========================================
    protected ?array $titleConfig;

    // ========================================
    // 콘텐츠
    // ========================================
    protected ?BlockContentType $contentType;
    protected ?string $contentTypeRaw;
    protected BlockContentKind $contentKind;
    protected ?string $contentSkin;
    protected ?array $contentConfig;
    protected ?array $contentItems;

    // ========================================
    // 콘텐츠 스택 (content_mode=stack 일 때만 의미)
    // ========================================
    protected string $contentMode = 'single';
    protected int $pcContentGap = 0;
    protected int $mobileContentGap = 0;
    /** @var BlockColumnContent[] 하위 콘텐츠 (preload 시 주입, 기본 빈 배열) */
    protected array $contents = [];
    /** 스택 콘텐츠 view 의 렌더 키 ("{column_id}-c-{content_id}") — 원본 칸은 null */
    protected ?string $renderKeyOverride = null;

    // ========================================
    // 관리
    // ========================================
    protected int $sortOrder;
    protected bool $isActive;
    protected DateTimeImmutable $createdAt;
    protected DateTimeImmutable $updatedAt;

    /**
     * DB 로우 데이터로부터 BlockColumn 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $column = new self();

        // 기본 필드
        $column->columnId = (int) ($data['column_id'] ?? 0);
        $column->rowId = (int) ($data['row_id'] ?? 0);
        $column->domainId = (int) ($data['domain_id'] ?? 0);

        // 칸 정보
        $column->columnIndex = (int) ($data['column_index'] ?? 0);
        $column->width = $data['width'] ?? null;

        // 여백
        $column->pcPadding = $data['pc_padding'] ?? null;
        $column->mobilePadding = $data['mobile_padding'] ?? null;

        // JSON 필드들
        $column->backgroundConfig = self::parseJsonArray($data['background_config'] ?? null);
        $column->borderConfig = self::parseJsonArray($data['border_config'] ?? null);
        $column->titleConfig = self::parseJsonArray($data['title_config'] ?? null);

        // 콘텐츠
        $column->contentTypeRaw = $data['content_type'] ?? null;
        $column->contentType = isset($data['content_type']) ? BlockContentType::tryFrom($data['content_type']) : null;
        $column->contentKind = BlockContentKind::tryFrom($data['content_kind'] ?? 'CORE') ?? BlockContentKind::CORE;
        $column->contentSkin = $data['content_skin'] ?? null;
        $column->contentConfig = self::parseJsonArray($data['content_config'] ?? null);
        $column->contentItems = self::parseJsonArray($data['content_items'] ?? null);

        // 콘텐츠 스택 — 알 수 없는 값은 읽기 시 single 로 안전 처리 (계획 4.1)
        $mode = (string) ($data['content_mode'] ?? 'single');
        $column->contentMode = $mode === 'stack' ? 'stack' : 'single';
        $column->pcContentGap = max(0, (int) ($data['pc_content_gap'] ?? 0));
        $column->mobileContentGap = max(0, (int) ($data['mobile_content_gap'] ?? 0));

        // 관리
        $column->sortOrder = (int) ($data['sort_order'] ?? 0);
        $column->isActive = (bool) ($data['is_active'] ?? true);

        // 날짜
        $column->createdAt = self::parseDateTime($data['created_at'] ?? null);
        $column->updatedAt = self::parseDateTime($data['updated_at'] ?? null);

        return $column;
    }

    /**
     * JSON 문자열/배열을 배열로 파싱
     */
    private static function parseJsonArray($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * 날짜 문자열을 DateTimeImmutable로 변환
     */
    private static function parseDateTime(?string $datetime): DateTimeImmutable
    {
        if (empty($datetime)) {
            return new DateTimeImmutable();
        }

        try {
            return new DateTimeImmutable($datetime);
        } catch (\Exception $e) {
            return new DateTimeImmutable();
        }
    }

    /**
     * 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'column_id' => $this->columnId,
            'row_id' => $this->rowId,
            'domain_id' => $this->domainId,
            'column_index' => $this->columnIndex,
            'width' => $this->width,
            'pc_padding' => $this->pcPadding,
            'mobile_padding' => $this->mobilePadding,
            'background_config' => $this->backgroundConfig,
            'border_config' => $this->borderConfig,
            'title_config' => $this->titleConfig,
            'content_type' => $this->contentTypeRaw,
            'content_kind' => $this->contentKind->value,
            'content_skin' => $this->contentSkin,
            'content_config' => $this->contentConfig,
            'content_items' => $this->contentItems,
            'content_mode' => $this->contentMode,
            'pc_content_gap' => $this->pcContentGap,
            'mobile_content_gap' => $this->mobileContentGap,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    // ========================================
    // Getters - 기본 필드
    // ========================================

    public function getColumnId(): int
    {
        return $this->columnId;
    }

    public function getRowId(): int
    {
        return $this->rowId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    // ========================================
    // Getters - 칸 정보
    // ========================================

    public function getColumnIndex(): int
    {
        return $this->columnIndex;
    }

    public function getWidth(): ?string
    {
        return $this->width;
    }

    // ========================================
    // Getters - 여백
    // ========================================

    public function getPcPadding(): ?string
    {
        return $this->pcPadding;
    }

    public function getMobilePadding(): ?string
    {
        return $this->mobilePadding;
    }

    // ========================================
    // Getters - 배경 설정
    // ========================================

    public function getBackgroundConfig(): ?array
    {
        return $this->backgroundConfig;
    }

    /**
     * 배경 CSS 스타일 생성
     */
    public function getBackgroundStyle(): string
    {
        return BlockRow::buildBackgroundCss($this->backgroundConfig);
    }

    // ========================================
    // Getters - 테두리 설정
    // ========================================

    public function getBorderConfig(): ?array
    {
        return $this->borderConfig;
    }

    /**
     * 테두리 CSS 스타일 생성
     */
    public function getBorderStyle(): string
    {
        if (!$this->borderConfig) {
            return '';
        }

        $styles = [];

        $width = $this->borderConfig['width'] ?? null;
        $color = $this->borderConfig['color'] ?? null;
        $borderStyle = $this->borderConfig['style'] ?? 'solid';

        if ($width && $color) {
            $styles[] = "border: {$width} {$borderStyle} {$color}";
        }

        if (!empty($this->borderConfig['radius'])) {
            $styles[] = "border-radius: {$this->borderConfig['radius']}";
        }

        return implode('; ', $styles);
    }

    // ========================================
    // Getters - 제목 설정
    // ========================================

    public function getTitleConfig(): ?array
    {
        return $this->titleConfig;
    }

    /**
     * title_config 의 값을 문자열로 꺼낸다.
     *
     * 이 JSON 은 관리자 폼이 만든 것이라 타입이 보장되지 않는다. 글꼴 크기는
     * 숫자 입력이라 26 처럼 정수로 저장돼 있고, 문자열로 저장된 설치본도 있다.
     * declare(strict_types=1) 아래에서는 ?string 게터가 정수를 그대로 돌려줄 수
     * 없어 TypeError 가 나고, 렌더러가 예외를 받아 그 칸이 통째로 비어 버린다.
     *
     * 게터마다 캐스팅하지 않고 여기 한 곳에서 정규화한다 — 새 키가 늘어도
     * 같은 실수를 반복하지 않는다.
     */
    private function titleString(string $key): ?string
    {
        $value = $this->titleConfig[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        // 배열·객체가 들어오면 문자열로 만들 수 없다 — 값이 없는 것으로 본다.
        if (!is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * 제목 표시 여부
     */
    public function showTitle(): bool
    {
        return ($this->titleConfig['show'] ?? false) === true;
    }

    /**
     * 제목 텍스트
     */
    public function getTitleText(): ?string
    {
        return $this->titleString('text');
    }

    /**
     * 제목 색상
     */
    public function getTitleColor(): ?string
    {
        return $this->titleString('color');
    }

    /**
     * 제목 PC 폰트 크기
     */
    public function getTitlePcSize(): ?string
    {
        return $this->titleString('size_pc');
    }

    /**
     * 제목 Mobile 폰트 크기
     */
    public function getTitleMobileSize(): ?string
    {
        return $this->titleString('size_mo');
    }

    /**
     * 제목 위치 (left, center, right)
     */
    public function getTitlePosition(): string
    {
        return $this->titleString('position') ?? 'left';
    }

    /**
     * 제목 PC 이미지
     */
    public function getTitlePcImage(): ?string
    {
        return $this->titleString('pc_image');
    }

    /**
     * 제목 Mobile 이미지
     */
    public function getTitleMobileImage(): ?string
    {
        return $this->titleString('mo_image');
    }

    /**
     * 더보기 링크 사용 여부
     */
    public function hasMoreLink(): bool
    {
        return ($this->titleConfig['more_link'] ?? false) === true;
    }

    /**
     * 더보기 URL
     */
    public function getMoreUrl(): ?string
    {
        return $this->titleString('more_url');
    }

    /**
     * 더보기 문구 (미설정 시 스킨의 기본 문구 사용)
     */
    public function getMoreText(): ?string
    {
        return $this->titleString('more_text');
    }

    /**
     * 문구 텍스트
     */
    public function getCopytext(): ?string
    {
        return $this->titleString('copytext');
    }

    /**
     * 문구 색상
     */
    public function getCopytextColor(): ?string
    {
        return $this->titleString('copytext_color');
    }

    /**
     * 문구 위치 (빈 값이면 제목 정렬 상속)
     */
    public function getCopytextPosition(): ?string
    {
        return $this->titleString('copytext_position');
    }

    /**
     * 문구 PC 폰트 크기
     */
    public function getCopytextPcSize(): ?string
    {
        return $this->titleString('copytext_size_pc');
    }

    /**
     * 문구 Mobile 폰트 크기
     */
    public function getCopytextMobileSize(): ?string
    {
        return $this->titleString('copytext_size_mo');
    }

    /**
     * 제목 영역에 필요한 값 묶음 (BlockColumnView).
     *
     * 위 개별 게터들의 기본값·정규화를 한 번 거친 결과다. 렌더러는 이 배열을
     * 스킨에 넘기고, 코어의 공용 파셜(views/Block/_shared/title.php)이 그린다.
     * 투영을 데이터 소유자인 여기에 두어, 렌더러 계약이 제목 게터 16개를
     * 떠안지 않게 한다.
     *
     * @return array<string, mixed>
     */
    public function toTitleView(): array
    {
        return [
            'show' => $this->showTitle(),
            'text' => $this->getTitleText(),
            'position' => $this->getTitlePosition(),
            'color' => $this->getTitleColor(),
            'size_pc' => $this->getTitlePcSize(),
            'size_mo' => $this->getTitleMobileSize(),
            'pc_image' => $this->getTitlePcImage(),
            'mo_image' => $this->getTitleMobileImage(),
            'more_url' => $this->hasMoreLink() ? $this->getMoreUrl() : null,
            'more_text' => $this->getMoreText(),
            'copytext' => $this->getCopytext(),
            'copytext_color' => $this->getCopytextColor(),
            'copytext_position' => $this->getCopytextPosition(),
            'copytext_size_pc' => $this->getCopytextPcSize(),
            'copytext_size_mo' => $this->getCopytextMobileSize(),
        ];
    }

    // ========================================
    // Getters - 콘텐츠
    // ========================================

    public function getContentType(): ?BlockContentType
    {
        return $this->contentType;
    }

    /**
     * 콘텐츠 타입 문자열 반환 (Core Enum + Plugin/Package 타입 모두 포함)
     */
    public function getContentTypeString(): ?string
    {
        return $this->contentTypeRaw;
    }

    public function getContentKind(): BlockContentKind
    {
        return $this->contentKind;
    }

    /**
     * PC 출력 갯수
     */
    public function getPcCount(): int
    {
        return (int) ($this->contentConfig['pc_count'] ?? 4);
    }

    /**
     * MO 출력 갯수
     */
    public function getMoCount(): int
    {
        return (int) ($this->contentConfig['mo_count'] ?? 4);
    }

    public function getContentSkin(): ?string
    {
        return $this->contentSkin;
    }

    /**
     * PC 출력 스타일 (list, slide, none)
     */
    public function getPcStyle(): string
    {
        // ?? 없이 읽으면 키 없는 config(빈 {} 로 저장된 칸)에서 undefined key 경고가
        // 나고, 경고를 예외로 승격하는 환경에서는 스킨 렌더링이 통째로 터진다.
        return $this->normalizeLayoutStyle($this->contentConfig['pc_style'] ?? null);
    }

    /**
     * MO 출력 스타일 (list, slide, none)
     */
    public function getMoStyle(): string
    {
        return $this->normalizeLayoutStyle($this->contentConfig['mo_style'] ?? null);
    }

    /**
     * PC 1줄 출력 갯수
     */
    public function getPcCols(): string
    {
        return $this->normalizeLayoutColumns($this->contentConfig['pc_cols'] ?? null, '4');
    }

    /**
     * MO 1줄 출력 갯수
     */
    public function getMoCols(): string
    {
        return $this->normalizeLayoutColumns($this->contentConfig['mo_cols'] ?? null, '2');
    }

    /**
     * AOS 효과
     */
    public function getAos(): ?string
    {
        return $this->contentConfig['aos'] ?? null;
    }

    /**
     * AOS 애니메이션 시간 (ms)
     */
    public function getAosDuration(): int
    {
        return max(100, min(3000, (int) ($this->contentConfig['aos_duration'] ?? 600)));
    }

    /**
     * PC 자동재생 딜레이 (ms). 0 = 비활성
     */
    public function getPcAutoplay(): int
    {
        return $this->normalizeAutoplay($this->contentConfig['pc_autoplay'] ?? null);
    }

    /**
     * MO 자동재생 딜레이 (ms). 0 = 비활성
     */
    public function getMoAutoplay(): int
    {
        return $this->normalizeAutoplay($this->contentConfig['mo_autoplay'] ?? null);
    }

    /**
     * PC 무한 반복 여부
     */
    public function getPcLoop(): bool
    {
        return $this->normalizeBoolean($this->contentConfig['pc_loop'] ?? null);
    }

    /**
     * MO 무한 반복 여부
     */
    public function getMoLoop(): bool
    {
        return $this->normalizeBoolean($this->contentConfig['mo_loop'] ?? null);
    }

    /**
     * PC 슬라이드 이미지 크롭(cover) 모드 여부
     * true이면 가장 작은 이미지 높이에 맞춰 나머지를 object-fit: cover로 크롭
     */
    public function getPcSlideCover(): bool
    {
        return $this->normalizeBoolean($this->contentConfig['pc_slide_cover'] ?? null);
    }

    /**
     * MO 슬라이드 이미지 크롭(cover) 모드 여부
     */
    public function getMoSlideCover(): bool
    {
        return $this->normalizeBoolean($this->contentConfig['mo_slide_cover'] ?? null);
    }

    private function normalizeLayoutStyle(mixed $value): string
    {
        return in_array($value, ['list', 'slide', 'none'], true) ? $value : 'list';
    }

    private function normalizeLayoutColumns(mixed $value, string $default): string
    {
        if ($value === 'auto') {
            return 'auto';
        }

        $columns = filter_var($value, FILTER_VALIDATE_INT);
        return $columns !== false && $columns >= 1 && $columns <= 12
            ? (string) $columns
            : $default;
    }

    private function normalizeAutoplay(mixed $value): int
    {
        $delay = filter_var($value, FILTER_VALIDATE_INT);
        if ($delay === false || $delay <= 0) {
            return 0;
        }

        // 관리자 UI의 입력 범위와 같은 상한을 런타임에서도 보장한다.
        return min($delay, 30000);
    }

    private function normalizeBoolean(mixed $value): bool
    {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $normalized ?? false;
    }

    /**
     * MubloItemLayout용 data-* 속성 문자열 생성
     *
     * 스킨에서 <div class="mublo-item-layout" <?= $column->getLayoutDataAttributes() ?>> 로 사용
     */
    public function getLayoutDataAttributes(): string
    {
        $attrs = [
            'data-pc-style="' . htmlspecialchars($this->getPcStyle()) . '"',
            'data-mo-style="' . htmlspecialchars($this->getMoStyle()) . '"',
            'data-pc-cols="' . htmlspecialchars($this->getPcCols()) . '"',
            'data-mo-cols="' . htmlspecialchars($this->getMoCols()) . '"',
        ];

        if ($this->getPcAutoplay() > 0) {
            $attrs[] = 'data-pc-autoplay="' . $this->getPcAutoplay() . '"';
        }
        if ($this->getMoAutoplay() > 0) {
            $attrs[] = 'data-mo-autoplay="' . $this->getMoAutoplay() . '"';
        }
        if ($this->getPcLoop()) {
            $attrs[] = 'data-pc-loop="true"';
        }
        if ($this->getMoLoop()) {
            $attrs[] = 'data-mo-loop="true"';
        }

        if ($this->getPcSlideCover()) {
            $attrs[] = 'data-pc-slide-cover="true"';
        }
        if ($this->getMoSlideCover()) {
            $attrs[] = 'data-mo-slide-cover="true"';
        }

        return implode(' ', $attrs);
    }

    public function getContentConfig(): ?array
    {
        return $this->contentConfig;
    }

    public function getContentItems(): ?array
    {
        return $this->contentItems;
    }

    /**
     * 콘텐츠 설정 여부 (타입이 지정되어 있는지)
     *
     * Core Enum 타입 + Plugin/Package 타입 모두 감지
     */
    public function hasContent(): bool
    {
        return !empty($this->contentTypeRaw);
    }

    // ========================================
    // 콘텐츠 스택 (content_mode=stack)
    // ========================================

    /** 'single' | 'stack' */
    public function getContentMode(): string
    {
        return $this->contentMode;
    }

    public function isStack(): bool
    {
        return $this->contentMode === 'stack';
    }

    public function getPcContentGap(): int
    {
        return $this->pcContentGap;
    }

    public function getMobileContentGap(): int
    {
        return $this->mobileContentGap;
    }

    /**
     * 하위 콘텐츠 주입 — Repository 배치 preload 후 호출 (계획 7.4).
     * sort_order, content_id 순으로 정렬된 배열이어야 한다.
     *
     * @param BlockColumnContent[] $contents
     */
    public function setContents(array $contents): void
    {
        $this->contents = array_values($contents);
    }

    /** @return BlockColumnContent[] */
    public function getContents(): array
    {
        return $this->contents;
    }

    /**
     * 렌더 식별자 (계획 7.2).
     *
     * - 단일 모드: "12" (column_id 문자열 그대로)
     * - 스택 콘텐츠 view: "12-c-31"
     *
     * 접두사는 각 렌더러가 소유한다: 'bc-' . getRenderKey(),
     * 'block_faq_' . getRenderKey() — 렌더 키에 접두사를 넣으면 기존
     * single DOM ID(block_faq_12 등)가 바뀌어 snapshot 불변과 충돌한다.
     */
    public function getRenderKey(): string
    {
        return $this->renderKeyOverride ?? (string) $this->columnId;
    }

    /**
     * 렌더 전용 view — 원본 칸의 레이아웃 + 스택 콘텐츠의 콘텐츠 필드 병합 (계획 7.1).
     *
     * 기존 RendererInterface::render(BlockColumn) 계약을 유지하기 위한
     * 합성이다. view 의 렌더 키는 "{column_id}-c-{content_id}" 가 되어 같은
     * 타입을 한 스택에 반복 배치해도 DOM ID 가 고유하다.
     */
    public function withContentView(BlockColumnContent $content): static
    {
        $view = clone $this;

        $view->titleConfig = $content->getTitleConfig();
        $view->contentTypeRaw = $content->getContentTypeString();
        $view->contentType = $content->getContentTypeString() !== null
            ? BlockContentType::tryFrom($content->getContentTypeString())
            : null;
        $view->contentKind = $content->getContentKind();
        $view->contentSkin = $content->getContentSkin();
        $view->contentConfig = $content->getContentConfig();
        $view->contentItems = $content->getContentItems();

        // view 는 단일 렌더 계약으로 소비된다 — 중첩 스택 없음
        $view->contentMode = 'single';
        $view->contents = [];
        $view->renderKeyOverride = $this->columnId . '-c-' . $content->getContentId();

        return $view;
    }

    // ========================================
    // Getters - 관리
    // ========================================

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // ========================================
    // CSS 스타일 생성 (통합)
    // ========================================

}
