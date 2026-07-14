<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * OrderFieldRepository
 *
 * 주문 추가 필드 정의 + 값 CRUD
 */
class OrderFieldRepository extends BaseRepository
{
    protected string $table = 'shop_order_fields';
    protected string $primaryKey = 'field_id';

    private string $valuesTable = 'shop_order_field_values';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    // ── 필드 정의 ──

    /**
     * 도메인별 전체 필드 (sort_order ASC)
     */
    public function findByDomain(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('field_id', 'ASC')
            ->get();
    }

    /**
     * 도메인별 활성 필드만 (Front 체크아웃용, 관리자 전용 제외)
     */
    public function findActiveByDomain(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->where('is_admin_only', '=', 0)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('field_id', 'ASC')
            ->get();
    }

    /**
     * 도메인별 활성 필드 전체 (관리자 전용 포함)
     */
    public function findAllActiveByDomain(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('field_id', 'ASC')
            ->get();
    }

    /**
     * 필드 단건 조회
     */
    public function findField(int $fieldId): ?array
    {
        $row = $this->db->table($this->table)
            ->where('field_id', '=', $fieldId)
            ->first();

        return $row ?: null;
    }

    /**
     * 도메인 소유권을 포함한 필드 단건 조회.
     *
     * 프론트 입력 경계에서는 활성·비관리자 필드만 허용한다.
     */
    public function findFieldInDomain(int $domainId, int $fieldId, bool $frontOnly = false): ?array
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('field_id', '=', $fieldId);

        if ($frontOnly) {
            $query
                ->where('is_active', '=', 1)
                ->where('is_admin_only', '=', 0);
        }

        $row = $query->first();

        return $row ?: null;
    }

    /**
     * 도메인 소유권을 WHERE 절에서 최종 강제하는 필드 수정.
     */
    public function updateFieldInDomain(int $domainId, int $fieldId, array $data): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('field_id', '=', $fieldId)
            ->update($data);
    }

    /**
     * 도메인 소유권을 WHERE 절에서 최종 강제하는 필드 삭제.
     */
    public function deleteFieldInDomain(int $domainId, int $fieldId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('field_id', '=', $fieldId)
            ->delete();
    }

    /**
     * 도메인+필드명 중복 체크
     */
    public function existsByDomainAndName(int $domainId, string $name, ?int $excludeId = null): bool
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('field_name', '=', $name);

        if ($excludeId !== null) {
            $query->where('field_id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * 도메인별 최대 sort_order
     */
    public function getMaxSortOrder(int $domainId): int
    {
        $row = $this->db->table($this->table)
            ->select('MAX(sort_order) as max_sort')
            ->where('domain_id', '=', $domainId)
            ->first();

        return (int) ($row['max_sort'] ?? 0);
    }

    /**
     * sort_order 개별 업데이트
     */
    public function updateSortOrder(int $fieldId, int $domainId, int $order): int
    {
        return $this->db->table($this->table)
            ->where('field_id', '=', $fieldId)
            ->where('domain_id', '=', $domainId)
            ->update(['sort_order' => $order]);
    }

    // ── 필드 값 ──

    /**
     * 필드 값 저장 (INSERT or UPDATE)
     */
    public function saveValue(string $orderNo, int $fieldId, string $value): int
    {
        $existing = $this->db->table($this->valuesTable)
            ->where('order_no', '=', $orderNo)
            ->where('field_id', '=', $fieldId)
            ->first();

        if ($existing) {
            return $this->db->table($this->valuesTable)
                ->where('value_id', '=', $existing['value_id'])
                ->update(['field_value' => $value]);
        }

        return $this->db->table($this->valuesTable)->insert([
            'order_no' => $orderNo,
            'field_id' => $fieldId,
            'field_value' => $value,
        ]);
    }

    /**
     * 주문의 필드 값 목록 (필드 정의 JOIN)
     */
    public function getValues(string $orderNo): array
    {
        return $this->db->select(
            "SELECT v.*, f.field_name, f.field_label, f.field_type, f.field_options, f.field_config, f.is_encrypted
             FROM {$this->valuesTable} v
             INNER JOIN {$this->table} f ON f.field_id = v.field_id
             WHERE v.order_no = ?
             ORDER BY f.sort_order ASC, f.field_id ASC",
            [$orderNo]
        );
    }

    /**
     * 주문의 필드 값 전체 삭제
     */
    public function deleteValues(string $orderNo): bool
    {
        return $this->db->table($this->valuesTable)
            ->where('order_no', '=', $orderNo)
            ->delete() >= 0;
    }

    /**
     * 특정 필드의 값 단건 조회
     */
    public function getFieldValue(string $orderNo, int $fieldId): ?array
    {
        $row = $this->db->table($this->valuesTable)
            ->where('order_no', '=', $orderNo)
            ->where('field_id', '=', $fieldId)
            ->first();

        return $row ?: null;
    }

    /**
     * 특정 필드 값 삭제
     */
    public function deleteFieldValue(string $orderNo, int $fieldId): bool
    {
        return $this->db->table($this->valuesTable)
            ->where('order_no', '=', $orderNo)
            ->where('field_id', '=', $fieldId)
            ->delete() >= 0;
    }

    protected function toEntity(array $row): object
    {
        return (object) $row;
    }
}
