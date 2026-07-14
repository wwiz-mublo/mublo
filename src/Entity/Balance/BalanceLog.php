<?php
namespace Mublo\Entity\Balance;

use DateTimeImmutable;
use Mublo\Enum\Balance\BalanceSourceType;

/**
 * Class BalanceLog
 *
 * 포인트/잔액 변경 원장 엔티티
 *
 * 책임:
 * - balance_logs 테이블의 데이터를 객체로 표현
 * - 변경 유형 판단 메서드 제공 (지급/차감, 관리자/시스템 조정 등)
 *
 * 금지:
 * - DB 직접 접근
 * - 비즈니스 로직 (Service 담당)
 *
 * Note: 이 테이블은 INSERT ONLY - UPDATE/DELETE 금지 (감사 추적용 불변 원장)
 */
class BalanceLog
{
    // ========================================
    // 기본 필드
    // ========================================
    protected int $logId;
    protected int $domainId;
    protected int $memberId;

    // ========================================
    // 변경 정보
    // ========================================
    protected int $amount;
    protected int $balanceBefore;
    protected int $balanceAfter;

    // ========================================
    // 출처 정보
    // ========================================
    protected BalanceSourceType $sourceType;
    protected string $sourceName;

    // ========================================
    // 상세 정보
    // ========================================
    protected string $action;
    protected string $message;
    protected ?string $referenceType;
    protected ?string $referenceId;

    // ========================================
    // 메타데이터
    // ========================================
    protected ?string $ipAddress;
    protected ?int $adminId;
    protected ?string $memo;
    protected ?string $idempotencyKey;

    // ========================================
    // 타임스탬프
    // ========================================
    protected DateTimeImmutable $createdAt;

    /**
     * 배열에서 Entity 생성 (Factory Method)
     */
    public static function fromArray(array $data): self
    {
        $log = new self();

        // 기본 필드
        $log->logId = (int) ($data['log_id'] ?? 0);
        $log->domainId = (int) ($data['domain_id'] ?? 0);
        $log->memberId = (int) ($data['member_id'] ?? 0);

        // 변경 정보
        $log->amount = (int) ($data['amount'] ?? 0);
        $log->balanceBefore = (int) ($data['balance_before'] ?? 0);
        $log->balanceAfter = (int) ($data['balance_after'] ?? 0);

        // 출처 정보
        $log->sourceType = BalanceSourceType::tryFrom($data['source_type'] ?? 'core') ?? BalanceSourceType::CORE;
        $log->sourceName = $data['source_name'] ?? '';

        // 상세 정보
        $log->action = $data['action'] ?? '';
        $log->message = $data['message'] ?? '';
        $log->referenceType = $data['reference_type'] ?? null;
        $log->referenceId = $data['reference_id'] ?? null;

        // 메타데이터
        $log->ipAddress = $data['ip_address'] ?? null;
        $log->adminId = isset($data['admin_id']) ? (int) $data['admin_id'] : null;
        $log->memo = $data['memo'] ?? null;
        $log->idempotencyKey = $data['idempotency_key'] ?? null;

        // 타임스탬프
        $log->createdAt = isset($data['created_at'])
            ? new DateTimeImmutable($data['created_at'])
            : new DateTimeImmutable();

        return $log;
    }

    /**
     * Entity를 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'log_id' => $this->logId,
            'domain_id' => $this->domainId,
            'member_id' => $this->memberId,
            'amount' => $this->amount,
            'balance_before' => $this->balanceBefore,
            'balance_after' => $this->balanceAfter,
            'source_type' => $this->sourceType->value,
            'source_name' => $this->sourceName,
            'action' => $this->action,
            'message' => $this->message,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'ip_address' => $this->ipAddress,
            'admin_id' => $this->adminId,
            'memo' => $this->memo,
            'idempotency_key' => $this->idempotencyKey,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    // ========================================
    // Getters - 기본 필드
    // ========================================

    public function getLogId(): int
    {
        return $this->logId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    // ========================================
    // Getters - 변경 정보
    // ========================================

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getBalanceBefore(): int
    {
        return $this->balanceBefore;
    }

    public function getBalanceAfter(): int
    {
        return $this->balanceAfter;
    }

    // ========================================
    // Getters - 출처 정보
    // ========================================

    public function getSourceType(): BalanceSourceType
    {
        return $this->sourceType;
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    // ========================================
    // Getters - 상세 정보
    // ========================================

    public function getAction(): string
    {
        return $this->action;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getReferenceType(): ?string
    {
        return $this->referenceType;
    }

    public function getReferenceId(): ?string
    {
        return $this->referenceId;
    }

    // ========================================
    // Getters - 메타데이터
    // ========================================

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getAdminId(): ?int
    {
        return $this->adminId;
    }

    public function getMemo(): ?string
    {
        return $this->memo;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    // ========================================
    // Getters - 타임스탬프
    // ========================================

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    // ========================================
    // 헬퍼 메서드
    // ========================================

    /**
     * 지급인지 여부 (양수)
     */
    public function isAddition(): bool
    {
        return $this->amount > 0;
    }

    /**
     * 차감인지 여부 (음수)
     */
    public function isDeduction(): bool
    {
        return $this->amount < 0;
    }

    /**
     * 관리자 조정인지 여부
     */
    public function isAdminAdjustment(): bool
    {
        return $this->sourceType === BalanceSourceType::ADMIN;
    }

    /**
     * 시스템 조정인지 여부 (무결성 복구 등)
     */
    public function isSystemAdjustment(): bool
    {
        return $this->sourceType === BalanceSourceType::SYSTEM;
    }

    /**
     * 참조 정보가 있는지 여부
     */
    public function hasReference(): bool
    {
        return $this->referenceType !== null && $this->referenceId !== null;
    }
}
