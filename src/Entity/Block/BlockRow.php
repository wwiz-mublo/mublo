<?php
declare(strict_types=1);
namespace Mublo\Entity\Block;

use DateTimeImmutable;
use Mublo\Enum\Block\BlockPosition;

/**
 * Class BlockRow
 *
 * 블록 행(Row) 엔티티
 *
 * 책임:
 * - block_rows 테이블의 데이터를 객체로 표현
 * - 블록의 한 줄(행) 설정 관리
 *
 * 금지:
 * - DB 직접 접근
 * - 비즈니스 로직 (Service 담당)
 */
class BlockRow
{
    // ========================================
    // 기본 필드
    // ========================================
    protected int $rowId;
    protected int $domainId;

    // ========================================
    // 연결 (페이지 또는 위치)
    // ========================================
    protected ?int $pageId;
    protected ?BlockPosition $position;
    protected ?string $positionMenu;

    // ========================================
    // 기본 정보
    // ========================================
    protected ?string $sectionId;
    protected ?string $adminTitle;

    // ========================================
    // 레이아웃
    // ========================================
    protected int $widthType;
    protected int $columnCount;
    protected int $columnMargin;
    protected int $columnWidthUnit;

    // ========================================
    // 높이/여백
    // ========================================
    protected ?string $pcHeight;
    protected ?string $mobileHeight;
    protected ?string $pcMargin;
    protected ?string $mobileMargin;
    protected ?string $pcPadding;
    protected ?string $mobilePadding;

    // ========================================
    // 배경 설정 (JSON)
    // ========================================
    protected ?array $backgroundConfig;

    // ========================================
    // 관리
    // ========================================
    protected int $sortOrder;
    protected bool $isActive;
    protected int $revisionNo = 1;

    // ========================================
    // topbar "보지 않기" (관리자 지정 기간 동안 방문자가 숨김)
    // ========================================
    protected bool $dismissible = false;
    protected int $dismissHours = 24;

    protected DateTimeImmutable $createdAt;
    protected DateTimeImmutable $updatedAt;

    /**
     * DB 로우 데이터로부터 BlockRow 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $row = new self();

        // 기본 필드
        $row->rowId = (int) ($data['row_id'] ?? 0);
        $row->domainId = (int) ($data['domain_id'] ?? 0);

        // 연결
        $row->pageId = isset($data['page_id']) ? (int) $data['page_id'] : null;
        $row->position = isset($data['position']) ? BlockPosition::tryFrom($data['position']) : null;
        $row->positionMenu = $data['position_menu'] ?? null;

        // 기본 정보
        $row->sectionId = $data['section_id'] ?? null;
        $row->adminTitle = $data['admin_title'] ?? null;

        // 레이아웃
        $row->widthType = (int) ($data['width_type'] ?? 1);
        $row->columnCount = (int) ($data['column_count'] ?? 1);
        $row->columnMargin = (int) ($data['column_margin'] ?? 0);
        $row->columnWidthUnit = (int) ($data['column_width_unit'] ?? 1);

        // 높이/여백
        $row->pcHeight = $data['pc_height'] ?? null;
        $row->mobileHeight = $data['mobile_height'] ?? null;
        $row->pcMargin = $data['pc_margin'] ?? null;
        $row->mobileMargin = $data['mobile_margin'] ?? null;
        $row->pcPadding = $data['pc_padding'] ?? null;
        $row->mobilePadding = $data['mobile_padding'] ?? null;

        // JSON 필드: background_config
        $row->backgroundConfig = self::parseJsonArray($data['background_config'] ?? null);

        // 관리
        $row->sortOrder = (int) ($data['sort_order'] ?? 0);
        $row->isActive = (bool) ($data['is_active'] ?? true);
        $row->revisionNo = max(1, (int) ($data['revision_no'] ?? 1));

        // topbar 보지 않기
        $row->dismissible = (bool) ($data['dismissible'] ?? false);
        $row->dismissHours = (int) ($data['dismiss_hours'] ?? 24);

        // 날짜
        $row->createdAt = self::parseDateTime($data['created_at'] ?? null);
        $row->updatedAt = self::parseDateTime($data['updated_at'] ?? null);

        return $row;
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
            'row_id' => $this->rowId,
            'domain_id' => $this->domainId,
            'page_id' => $this->pageId,
            'position' => $this->position?->value,
            'position_menu' => $this->positionMenu,
            'section_id' => $this->sectionId,
            'admin_title' => $this->adminTitle,
            'width_type' => $this->widthType,
            'column_count' => $this->columnCount,
            'column_margin' => $this->columnMargin,
            'column_width_unit' => $this->columnWidthUnit,
            'pc_height' => $this->pcHeight,
            'mobile_height' => $this->mobileHeight,
            'pc_margin' => $this->pcMargin,
            'mobile_margin' => $this->mobileMargin,
            'pc_padding' => $this->pcPadding,
            'mobile_padding' => $this->mobilePadding,
            'background_config' => $this->backgroundConfig,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'revision_no' => $this->revisionNo,
            'dismissible' => $this->dismissible,
            'dismiss_hours' => $this->dismissHours,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    // ========================================
    // Getters - 기본 필드
    // ========================================

    public function getRowId(): int
    {
        return $this->rowId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getRevisionNo(): int
    {
        return $this->revisionNo;
    }

    // ========================================
    // Getters - 연결
    // ========================================

    public function getPageId(): ?int
    {
        return $this->pageId;
    }

    public function getPosition(): ?BlockPosition
    {
        return $this->position;
    }

    public function getPositionMenu(): ?string
    {
        return $this->positionMenu;
    }

    /**
     * 페이지용 행인지 확인
     */
    public function isPageRow(): bool
    {
        return $this->pageId !== null;
    }

    /**
     * 위치 기반 행인지 확인
     */
    public function isPositionRow(): bool
    {
        return $this->position !== null;
    }

    // ========================================
    // Getters - 기본 정보
    // ========================================

    public function getSectionId(): ?string
    {
        return $this->sectionId;
    }

    public function getAdminTitle(): ?string
    {
        return $this->adminTitle;
    }

    /**
     * 표시용 제목 (관리 제목 또는 기본값)
     */
    public function getDisplayTitle(): string
    {
        if (!empty($this->adminTitle)) {
            return $this->adminTitle;
        }

        if ($this->pageId) {
            return "페이지 행 #{$this->rowId}";
        }

        return $this->position ? "위치: {$this->position->value}" : "행 #{$this->rowId}";
    }

    // ========================================
    // Getters - 레이아웃
    // ========================================

    public function getWidthType(): int
    {
        return $this->widthType;
    }

    /**
     * 와이드(전체) 타입인지 확인
     */
    public function isWide(): bool
    {
        return $this->widthType === 0;
    }

    /**
     * 최대넓이 타입인지 확인
     */
    public function isContained(): bool
    {
        return $this->widthType === 1;
    }

    public function getColumnCount(): int
    {
        return $this->columnCount;
    }

    public function getColumnMargin(): int
    {
        return $this->columnMargin;
    }

    public function getColumnWidthUnit(): int
    {
        return $this->columnWidthUnit;
    }

    // ========================================
    // Getters - 높이/여백
    // ========================================

    public function getPcHeight(): ?string
    {
        return $this->pcHeight;
    }

    public function getMobileHeight(): ?string
    {
        return $this->mobileHeight;
    }

    public function getPcMargin(): ?string
    {
        return $this->pcMargin;
    }

    public function getMobileMargin(): ?string
    {
        return $this->mobileMargin;
    }

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
     * 배경색 반환
     */
    public function getBackgroundColor(): ?string
    {
        return $this->backgroundConfig['color'] ?? null;
    }

    /**
     * 배경 이미지 URL 반환
     */
    public function getBackgroundImage(): ?string
    {
        return $this->backgroundConfig['image'] ?? null;
    }

    /**
     * 배경 CSS 스타일 생성
     */
    public function getBackgroundStyle(): string
    {
        return self::buildBackgroundCss($this->backgroundConfig);
    }

    /**
     * background_config → CSS 문자열 (Row/Column 공용)
     */
    public static function buildBackgroundCss(?array $bg): string
    {
        if (!$bg) {
            return '';
        }

        $styles = [];

        // 그라데이션
        if (!empty($bg['gradient'])) {
            $styles[] = "background: {$bg['gradient']}";
        } elseif (!empty($bg['color'])) {
            $styles[] = "background-color: {$bg['color']}";
        }

        // 이미지 (그라데이션과 병행 가능)
        if (!empty($bg['image'])) {
            if (!empty($bg['gradient'])) {
                // 그라데이션 위에 이미지 오버레이
                $styles = ["background: {$bg['gradient']}, url('{$bg['image']}')"];
            } else {
                $styles[] = "background-image: url('{$bg['image']}')";
            }

            $styles[] = 'background-position: ' . ($bg['position'] ?? 'center center');
            $styles[] = 'background-size: ' . ($bg['size'] ?? 'cover');
            $styles[] = 'background-repeat: ' . ($bg['repeat'] ?? 'no-repeat');

            if (!empty($bg['attachment'])) {
                $styles[] = "background-attachment: {$bg['attachment']}";
            }
        }

        return implode('; ', $styles);
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

    /**
     * topbar "보지 않기" 버튼 사용 여부 (topbar 행에서만 의미)
     */
    public function isDismissible(): bool
    {
        return $this->dismissible;
    }

    /**
     * 보지 않기 유지 시간(hours)
     */
    public function getDismissHours(): int
    {
        return $this->dismissHours;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
