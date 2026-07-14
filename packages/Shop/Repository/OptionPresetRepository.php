<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Packages\Shop\Entity\OptionPreset;
use Mublo\Repository\BaseRepository;

/**
 * OptionPreset Repository
 *
 * 옵션 프리셋 데이터베이스 접근 담당
 *
 * 책임:
 * - shop_option_presets 테이블 CRUD
 * - shop_option_preset_options / shop_option_preset_values 관리
 * - OptionPreset Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class OptionPresetRepository extends BaseRepository
{
    protected string $table = 'shop_option_presets';
    protected string $entityClass = OptionPreset::class;
    protected string $primaryKey = 'preset_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 도메인별 프리셋 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return OptionPreset[]
     */
    public function getList(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('created_at', 'DESC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 프리셋 옵션 + 값 조회
     *
     * 프리셋에 속한 옵션 목록과 각 옵션의 값을 JOIN하여 반환
     *
     * @param int $presetId 프리셋 ID
     * @return array 옵션 배열 (각 옵션에 values 키 포함)
     */
    public function getPresetOptions(int $presetId): array
    {
        $optionTable = $this->getDb()->prefixTable('shop_option_preset_options');
        $valueTable = $this->getDb()->prefixTable('shop_option_preset_values');

        $sql = "SELECT
                    o.preset_option_id,
                    o.preset_id,
                    o.option_name,
                    o.option_type,
                    o.is_required,
                    o.sort_order AS option_sort_order,
                    v.preset_value_id,
                    v.value_name,
                    v.extra_price,
                    v.sort_order AS value_sort_order
                FROM {$optionTable} AS o
                LEFT JOIN {$valueTable} AS v
                    ON o.preset_option_id = v.preset_option_id
                WHERE o.preset_id = ?
                ORDER BY o.sort_order ASC, v.sort_order ASC";

        $rows = $this->getDb()->select($sql, [$presetId]);

        // 옵션별로 그룹핑
        $options = [];
        foreach ($rows as $row) {
            $optionId = $row['preset_option_id'];

            if (!isset($options[$optionId])) {
                $options[$optionId] = [
                    'preset_option_id' => $row['preset_option_id'],
                    'preset_id' => $row['preset_id'],
                    'option_name' => $row['option_name'],
                    'option_type' => $row['option_type'],
                    'is_required' => $row['is_required'],
                    'sort_order' => $row['option_sort_order'],
                    'values' => [],
                ];
            }

            if ($row['preset_value_id'] !== null) {
                $options[$optionId]['values'][] = [
                    'preset_value_id' => $row['preset_value_id'],
                    'value_name' => $row['value_name'],
                    'extra_price' => $row['extra_price'],
                    'sort_order' => $row['value_sort_order'],
                ];
            }
        }

        return array_values($options);
    }

    /**
     * 프리셋 옵션 생성
     *
     * @param int $presetId 프리셋 ID
     * @param array $data 옵션 데이터
     * @return int 생성된 preset_option_id
     */
    public function createOption(int $presetId, array $data): int
    {
        $data['preset_id'] = $presetId;

        return $this->getDb()->table('shop_option_preset_options')->insert($data);
    }

    /**
     * 프리셋 옵션 값 생성
     *
     * @param int $presetOptionId 프리셋 옵션 ID
     * @param array $data 값 데이터
     * @return int 생성된 preset_value_id
     */
    public function createValue(int $presetOptionId, array $data): int
    {
        $data['preset_option_id'] = $presetOptionId;

        return $this->getDb()->table('shop_option_preset_values')->insert($data);
    }

    /**
     * 프리셋 옵션+값 전체 삭제
     *
     * 프리셋에 속한 모든 옵션과 값을 삭제 (프리셋 재구성 시 사용)
     *
     * @param int $presetId 프리셋 ID
     */
    public function deletePresetOptions(int $presetId): void
    {
        // 옵션 ID 목록 조회
        $optionIds = $this->getDb()->table('shop_option_preset_options')
            ->where('preset_id', '=', $presetId)
            ->get();

        // 각 옵션의 값 삭제
        foreach ($optionIds as $option) {
            $this->getDb()->table('shop_option_preset_values')
                ->where('preset_option_id', '=', $option['preset_option_id'])
                ->delete();
        }

        // 옵션 삭제
        $this->getDb()->table('shop_option_preset_options')
            ->where('preset_id', '=', $presetId)
            ->delete();
    }

    /**
     * 생성 타임스탬프 필드명
     */
    protected function getCreatedAtField(): ?string
    {
        return 'created_at';
    }

    /**
     * 수정 타임스탬프 필드명
     */
    protected function getUpdatedAtField(): ?string
    {
        return 'updated_at';
    }
}
