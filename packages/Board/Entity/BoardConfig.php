<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Entity;

use DateTimeImmutable;

/**
 * Class BoardConfig
 *
 * 게시판 설정 엔티티
 *
 * 책임:
 * - board_configs 테이블의 데이터를 객체로 표현
 * - 게시판별 권한 및 기능 설정 관리
 *
 * 금지:
 * - DB 직접 접근
 * - 비즈니스 로직 (Service 담당)
 */
class BoardConfig
{
    // ========================================
    // 기본 필드
    // ========================================
    protected int $boardId;
    protected int $domainId;
    protected int $groupId;
    protected string $boardSlug;
    protected string $boardName;
    protected ?string $boardDescription;
    protected ?array $boardAdminIds;

    // ========================================
    // 권한 레벨
    // ========================================
    protected int $listLevel;
    protected int $readLevel;
    protected int $writeLevel;
    protected int $commentLevel;
    protected int $downloadLevel;

    // ========================================
    // 레벨별 1일 작성 제한 (JSON: {"level_value": limit})
    // ========================================
    protected ?array $dailyWriteLimit;
    protected ?array $dailyCommentLimit;

    // ========================================
    // UI 설정
    // ========================================
    protected string $boardSkin;
    protected string $boardEditor;

    // ========================================
    // 목록 설정
    // ========================================
    protected int $noticeCount;
    protected int $perPage;

    // ========================================
    // 기능 사용 여부
    // ========================================
    protected bool $useSecret;
    protected bool $isSecretBoard;
    protected bool $useCategory;
    protected bool $useComment;
    protected bool $useReaction;
    protected bool $useLink;
    protected bool $useFile;
    protected bool $allowGuest;

    // ========================================
    // 반응 설정
    // ========================================
    protected ?array $reactionConfig;

    // ========================================
    // 파일 설정
    // ========================================
    protected int $fileCountLimit;
    protected int $fileSizeLimit;
    protected string $fileExtensionAllowed;

    // ========================================
    // 테이블 분리 설정
    // ========================================
    protected bool $useSeparateTable;
    protected ?string $tableName;

    // ========================================
    // 관리
    // ========================================
    protected int $sortOrder;
    protected bool $isActive;
    protected bool $isGlobal;
    protected DateTimeImmutable $createdAt;
    protected DateTimeImmutable $updatedAt;

    /**
     * DB 로우 데이터로부터 BoardConfig 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $config = new self();

        // 기본 필드
        $config->boardId = (int) ($data['board_id'] ?? 0);
        $config->domainId = (int) ($data['domain_id'] ?? 0);
        $config->groupId = (int) ($data['group_id'] ?? 0);
        $config->boardSlug = $data['board_slug'] ?? '';
        $config->boardName = $data['board_name'] ?? '';
        $config->boardDescription = $data['board_description'] ?? null;

        // JSON 필드: board_admin_ids
        $config->boardAdminIds = self::parseJsonArray($data['board_admin_ids'] ?? null);

        // 권한 레벨
        $config->listLevel = (int) ($data['list_level'] ?? 0);
        $config->readLevel = (int) ($data['read_level'] ?? 0);
        $config->writeLevel = (int) ($data['write_level'] ?? 1);
        $config->commentLevel = (int) ($data['comment_level'] ?? 1);
        $config->downloadLevel = (int) ($data['download_level'] ?? 0);

        // 레벨별 1일 작성 제한 (JSON)
        $config->dailyWriteLimit = self::parseJsonArray($data['daily_write_limit'] ?? null);
        $config->dailyCommentLimit = self::parseJsonArray($data['daily_comment_limit'] ?? null);

        // UI 설정
        $config->boardSkin = $data['board_skin'] ?? 'basic';
        $config->boardEditor = $data['board_editor'] ?? 'mublo-editor';

        // 목록 설정
        $config->noticeCount = (int) ($data['notice_count'] ?? 5);
        $config->perPage = (int) ($data['per_page'] ?? 0);

        // 기능 사용 여부
        $config->useSecret = (bool) ($data['use_secret'] ?? false);
        $config->isSecretBoard = (bool) ($data['is_secret_board'] ?? false);
        $config->useCategory = (bool) ($data['use_category'] ?? false);
        $config->useComment = (bool) ($data['use_comment'] ?? true);
        $config->useReaction = (bool) ($data['use_reaction'] ?? true);
        $config->useLink = (bool) ($data['use_link'] ?? false);
        $config->useFile = (bool) ($data['use_file'] ?? true);
        $config->allowGuest = (bool) ($data['allow_guest'] ?? false);

        // JSON 필드: reaction_config
        $config->reactionConfig = self::parseJsonArray($data['reaction_config'] ?? null);

        // 파일 설정
        $config->fileCountLimit = (int) ($data['file_count_limit'] ?? 2);
        $config->fileSizeLimit = (int) ($data['file_size_limit'] ?? 2097152);
        $config->fileExtensionAllowed = $data['file_extension_allowed'] ?? 'jpg,jpeg,png,gif,pdf,zip';

        // 테이블 분리 설정
        $config->useSeparateTable = (bool) ($data['use_separate_table'] ?? false);
        $config->tableName = $data['table_name'] ?? null;

        // 관리
        $config->sortOrder = (int) ($data['sort_order'] ?? 0);
        $config->isActive = (bool) ($data['is_active'] ?? true);
        $config->isGlobal = (bool) ($data['is_global'] ?? false);

        // 날짜
        $config->createdAt = self::parseDateTime($data['created_at'] ?? null);
        $config->updatedAt = self::parseDateTime($data['updated_at'] ?? null);

        return $config;
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
            'board_id' => $this->boardId,
            'domain_id' => $this->domainId,
            'group_id' => $this->groupId,
            'board_slug' => $this->boardSlug,
            'board_name' => $this->boardName,
            'board_description' => $this->boardDescription,
            'board_admin_ids' => $this->boardAdminIds,
            'list_level' => $this->listLevel,
            'read_level' => $this->readLevel,
            'write_level' => $this->writeLevel,
            'comment_level' => $this->commentLevel,
            'download_level' => $this->downloadLevel,
            'daily_write_limit' => $this->dailyWriteLimit,
            'daily_comment_limit' => $this->dailyCommentLimit,
            'board_skin' => $this->boardSkin,
            'board_editor' => $this->boardEditor,
            'notice_count' => $this->noticeCount,
            'per_page' => $this->perPage,
            'use_secret' => $this->useSecret,
            'is_secret_board' => $this->isSecretBoard,
            'use_category' => $this->useCategory,
            'use_comment' => $this->useComment,
            'use_reaction' => $this->useReaction,
            'use_link' => $this->useLink,
            'use_file' => $this->useFile,
            'allow_guest' => $this->allowGuest,
            'reaction_config' => $this->reactionConfig,
            'file_count_limit' => $this->fileCountLimit,
            'file_size_limit' => $this->fileSizeLimit,
            'file_extension_allowed' => $this->fileExtensionAllowed,
            'use_separate_table' => $this->useSeparateTable,
            'table_name' => $this->tableName,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'is_global' => $this->isGlobal,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    // ========================================
    // Getters - 기본 필드
    // ========================================

    public function getBoardId(): int
    {
        return $this->boardId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function getBoardSlug(): string
    {
        return $this->boardSlug;
    }

    public function getBoardName(): string
    {
        return $this->boardName;
    }

    public function getBoardDescription(): ?string
    {
        return $this->boardDescription;
    }

    public function getBoardAdminIds(): ?array
    {
        return $this->boardAdminIds;
    }

    // ========================================
    // Getters - 권한 레벨
    // ========================================

    public function getListLevel(): int
    {
        return $this->listLevel;
    }

    public function getReadLevel(): int
    {
        return $this->readLevel;
    }

    public function getWriteLevel(): int
    {
        return $this->writeLevel;
    }

    public function getCommentLevel(): int
    {
        return $this->commentLevel;
    }

    public function getDownloadLevel(): int
    {
        return $this->downloadLevel;
    }

    // ========================================
    // Getters - 레벨별 1일 작성 제한
    // ========================================

    public function getDailyWriteLimit(): ?array
    {
        return $this->dailyWriteLimit;
    }

    public function getDailyCommentLimit(): ?array
    {
        return $this->dailyCommentLimit;
    }

    /**
     * 특정 레벨의 1일 글쓰기 제한 조회
     * @return int|null null = 무제한
     */
    public function getDailyWriteLimitForLevel(int $levelValue): ?int
    {
        if ($this->dailyWriteLimit === null) {
            return null;
        }
        $key = (string) $levelValue;
        return isset($this->dailyWriteLimit[$key]) ? (int) $this->dailyWriteLimit[$key] : null;
    }

    /**
     * 특정 레벨의 1일 댓글 제한 조회
     * @return int|null null = 무제한
     */
    public function getDailyCommentLimitForLevel(int $levelValue): ?int
    {
        if ($this->dailyCommentLimit === null) {
            return null;
        }
        $key = (string) $levelValue;
        return isset($this->dailyCommentLimit[$key]) ? (int) $this->dailyCommentLimit[$key] : null;
    }

    // ========================================
    // Getters - UI 설정
    // ========================================

    public function getBoardSkin(): string
    {
        return $this->boardSkin;
    }

    public function getBoardEditor(): string
    {
        return $this->boardEditor;
    }

    // ========================================
    // Getters - 목록 설정
    // ========================================

    public function getNoticeCount(): int
    {
        return $this->noticeCount;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    // ========================================
    // Getters - 기능 사용 여부
    // ========================================

    public function useSecret(): bool
    {
        return $this->useSecret;
    }

    public function isUseSecret(): bool
    {
        return $this->useSecret;
    }

    public function isSecretBoard(): bool
    {
        return $this->isSecretBoard;
    }

    public function useCategory(): bool
    {
        return $this->useCategory;
    }

    public function isUseCategory(): bool
    {
        return $this->useCategory;
    }

    public function useComment(): bool
    {
        return $this->useComment;
    }

    public function isUseComment(): bool
    {
        return $this->useComment;
    }

    public function useReaction(): bool
    {
        return $this->useReaction;
    }

    public function isUseReaction(): bool
    {
        return $this->useReaction;
    }

    public function useLink(): bool
    {
        return $this->useLink;
    }

    public function isUseLink(): bool
    {
        return $this->useLink;
    }

    public function useFile(): bool
    {
        return $this->useFile;
    }

    public function isUseFile(): bool
    {
        return $this->useFile;
    }

    public function isAllowGuest(): bool
    {
        return $this->allowGuest;
    }

    // ========================================
    // Getters - 반응 설정
    // ========================================

    public function getReactionConfig(): ?array
    {
        return $this->reactionConfig;
    }

    /**
     * 활성화된 반응 타입 목록
     */
    public function getEnabledReactions(): array
    {
        if (!$this->useReaction || empty($this->reactionConfig)) {
            return [];
        }

        $enabled = [];
        foreach ($this->reactionConfig as $type => $config) {
            if (empty($config['enabled'])) {
                continue;
            }
            // 이모지/라벨이 비어있는 손상 데이터는 건너뜀 (렌더 깨짐 방지)
            if (trim((string) ($config['icon'] ?? '')) === '' || trim((string) ($config['label'] ?? '')) === '') {
                continue;
            }
            $enabled[$type] = $config;
        }

        return $enabled;
    }

    // ========================================
    // Getters - 파일 설정
    // ========================================

    public function getFileCountLimit(): int
    {
        return $this->fileCountLimit;
    }

    public function getFileSizeLimit(): int
    {
        return $this->fileSizeLimit;
    }

    /**
     * 파일 크기 제한 (MB 단위)
     */
    public function getFileSizeLimitMB(): float
    {
        return round($this->fileSizeLimit / 1048576, 2);
    }

    public function getFileExtensionAllowed(): string
    {
        return $this->fileExtensionAllowed;
    }

    /**
     * 허용 확장자 배열
     */
    public function getAllowedExtensions(): array
    {
        return array_map('trim', explode(',', $this->fileExtensionAllowed));
    }

    // ========================================
    // Getters - 테이블 분리 설정
    // ========================================

    public function useSeparateTable(): bool
    {
        return $this->useSeparateTable;
    }

    public function getTableName(): ?string
    {
        return $this->tableName;
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

    public function isGlobal(): bool
    {
        return $this->isGlobal;
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
    // 권한 판단 메서드
    // ========================================

    /**
     * 게시판 관리자인지 확인
     */
    public function isAdmin(int $memberId): bool
    {
        if ($this->boardAdminIds === null) {
            return false;
        }

        return in_array($memberId, $this->boardAdminIds, true);
    }

    /**
     * 목록 보기 권한 확인
     */
    public function canList(int $memberLevel): bool
    {
        return $memberLevel >= $this->listLevel;
    }

    /**
     * 글 읽기 권한 확인
     */
    public function canRead(int $memberLevel): bool
    {
        return $memberLevel >= $this->readLevel;
    }

    /**
     * 글쓰기 권한 확인
     */
    public function canWrite(int $memberLevel): bool
    {
        return $memberLevel >= $this->writeLevel;
    }

    /**
     * 댓글 쓰기 권한 확인
     */
    public function canComment(int $memberLevel): bool
    {
        return $memberLevel >= $this->commentLevel;
    }

    /**
     * 다운로드 권한 확인
     */
    public function canDownload(int $memberLevel): bool
    {
        return $memberLevel >= $this->downloadLevel;
    }
}
