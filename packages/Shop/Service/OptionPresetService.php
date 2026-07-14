<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Packages\Shop\Repository\OptionPresetRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;

/**
 * OptionPreset Service
 *
 * 옵션 프리셋 비즈니스 로직 담당
 *
 * 책임:
 * - 옵션 프리셋 CRUD
 * - 프리셋 → 상품 옵션 복사 적용
 * - 프리셋 옵션/값 관리
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class OptionPresetService
{
    private OptionPresetRepository $presetRepository;
    private ProductOptionRepository $productOptionRepository;
    private ?EventDispatcher $eventDispatcher;

    public function __construct(
        OptionPresetRepository $presetRepository,
        ProductOptionRepository $productOptionRepository,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $this->presetRepository = $presetRepository;
        $this->productOptionRepository = $productOptionRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * 이벤트 발행 헬퍼
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    /**
     * 도메인별 옵션 프리셋 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return Result
     */
    public function getList(int $domainId): Result
    {
        $presets = $this->presetRepository->getList($domainId);
        $items = array_map(fn($p) => $p->toArray(), $presets);

        return Result::success('옵션 프리셋 목록을 조회했습니다.', ['items' => $items]);
    }

    /**
     * 옵션 프리셋 상세 조회 (옵션 + 값 포함)
     *
     * @param int $presetId 프리셋 ID
     * @return Result
     */
    public function getDetail(int $presetId): Result
    {
        $preset = $this->presetRepository->find($presetId);
        if (!$preset) {
            return Result::failure('옵션 프리셋을 찾을 수 없습니다.');
        }

        $presetData = $preset instanceof \Mublo\Packages\Shop\Entity\OptionPreset
            ? $preset->toArray()
            : (array) $preset;

        // 프리셋에 속한 옵션 + 값 (이미 그룹핑되어 반환)
        $presetData['options'] = $this->presetRepository->getPresetOptions($presetId);

        return Result::success('옵션 프리셋 상세를 조회했습니다.', ['preset' => $presetData]);
    }

    /**
     * 옵션 프리셋 생성
     *
     * 프리셋 + 옵션 + 옵션값을 동시에 생성
     *
     * @param int $domainId 도메인 ID
     * @param array $data 프리셋 데이터
     * @return Result
     */
    public function create(int $domainId, array $data): Result
    {
        if (empty($data['name'])) {
            return Result::failure('프리셋명을 입력해주세요.');
        }

        $db = $this->presetRepository->getDb();

        try {
            $db->beginTransaction();

            $optionMode = in_array($data['option_mode'] ?? '', ['SINGLE', 'COMBINATION'], true)
                ? $data['option_mode']
                : 'SINGLE';

            $presetData = [
                'domain_id' => $domainId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'option_mode' => $optionMode,
            ];
            $presetId = $this->presetRepository->create($presetData);

            if (!$presetId) {
                $db->rollBack();
                return Result::failure('옵션 프리셋 생성에 실패했습니다.');
            }

            // 옵션 + 값 생성
            $options = $data['options'] ?? [];
            $this->savePresetOptions($presetId, $options);

            $db->commit();

            return Result::success('옵션 프리셋이 생성되었습니다.', ['preset_id' => $presetId]);
        } catch (\Throwable $e) {
            $db->rollBack();
            return Result::failure('옵션 프리셋 생성 중 오류가 발생했습니다.');
        }
    }

    /**
     * 옵션 프리셋 수정
     *
     * 기존 옵션을 삭제하고 새로 생성 (delete + recreate)
     *
     * @param int $presetId 프리셋 ID
     * @param array $data 수정 데이터
     * @return Result
     */
    public function update(int $presetId, array $data): Result
    {
        $preset = $this->presetRepository->find($presetId);
        if (!$preset) {
            return Result::failure('옵션 프리셋을 찾을 수 없습니다.');
        }

        $db = $this->presetRepository->getDb();

        try {
            $db->beginTransaction();

            // 프리셋 기본 정보 수정
            $updateData = [];
            if (isset($data['name'])) {
                $updateData['name'] = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $updateData['description'] = $data['description'];
            }
            if (isset($data['option_mode']) && in_array($data['option_mode'], ['SINGLE', 'COMBINATION'], true)) {
                $updateData['option_mode'] = $data['option_mode'];
            }
            if (!empty($updateData)) {
                $this->presetRepository->update($presetId, $updateData);
            }

            // 옵션 재구성 (기존 옵션+값 전체 삭제 → 새로 생성)
            if (isset($data['options'])) {
                $this->presetRepository->deletePresetOptions($presetId);
                $this->savePresetOptions($presetId, $data['options']);
            }

            $db->commit();

            return Result::success('옵션 프리셋이 수정되었습니다.');
        } catch (\Throwable $e) {
            $db->rollBack();
            return Result::failure('옵션 프리셋 수정 중 오류가 발생했습니다.');
        }
    }

    /**
     * 옵션 프리셋 삭제
     *
     * @param int $presetId 프리셋 ID
     * @return Result
     */
    public function delete(int $presetId): Result
    {
        $preset = $this->presetRepository->find($presetId);
        if (!$preset) {
            return Result::failure('옵션 프리셋을 찾을 수 없습니다.');
        }

        $db = $this->presetRepository->getDb();

        try {
            $db->beginTransaction();

            // 옵션 + 값 전체 삭제
            $this->presetRepository->deletePresetOptions($presetId);

            // 프리셋 삭제
            $this->presetRepository->delete($presetId);

            $db->commit();

            return Result::success('옵션 프리셋이 삭제되었습니다.');
        } catch (\Throwable $e) {
            $db->rollBack();
            return Result::failure('옵션 프리셋 삭제 중 오류가 발생했습니다.');
        }
    }

    /**
     * 프리셋 옵션을 상품에 복사 적용
     *
     * 프리셋의 옵션+값을 상품의 옵션+값으로 복사 (FK 연결 없음, 독립 복사)
     *
     * @param int $presetId 프리셋 ID
     * @param int $goodsId 상품 ID
     * @return Result
     */
    public function applyToProduct(int $presetId, int $goodsId): Result
    {
        $preset = $this->presetRepository->find($presetId);
        if (!$preset) {
            return Result::failure('옵션 프리셋을 찾을 수 없습니다.');
        }

        // 프리셋 옵션 + 값 조회 (values 중첩 포함)
        $presetOptions = $this->presetRepository->getPresetOptions($presetId);
        if (empty($presetOptions)) {
            return Result::failure('프리셋에 옵션이 없습니다.');
        }

        $db = $this->productOptionRepository->getDb();

        try {
            $db->beginTransaction();

            $createdOptionIds = [];

            foreach ($presetOptions as $presetOption) {
                // 상품 옵션 생성
                $optionData = [
                    'goods_id' => $goodsId,
                    'option_name' => $presetOption['option_name'] ?? '',
                    'option_type' => $presetOption['option_type'] ?? 'BASIC',
                    'is_required' => $presetOption['is_required'] ?? 1,
                    'sort_order' => $presetOption['sort_order'] ?? 0,
                ];
                $newOptionId = $this->productOptionRepository->createOption($optionData);

                if (!$newOptionId) {
                    $db->rollBack();
                    return Result::failure('상품 옵션 생성에 실패했습니다.');
                }

                $createdOptionIds[] = $newOptionId;

                // 프리셋 옵션의 값을 상품 옵션값으로 복사
                $values = $presetOption['values'] ?? [];
                foreach ($values as $presetValue) {
                    $valueData = [
                        'option_id' => $newOptionId,
                        'value_name' => $presetValue['value_name'] ?? '',
                        'extra_price' => (int) ($presetValue['extra_price'] ?? 0),
                        'stock_quantity' => 0,
                        'is_active' => 1,
                        'sort_order' => $presetValue['sort_order'] ?? 0,
                    ];
                    $this->productOptionRepository->createOptionValue($valueData);
                }
            }

            // 조합형이면 BASIC 옵션의 값으로 combo 자동 생성
            $optionMode = $preset->getOptionMode();
            $combos = [];
            if ($optionMode === 'COMBINATION') {
                $combos = $this->generateCombos($goodsId, $presetOptions);
            }

            $db->commit();

            return Result::success('프리셋 옵션이 상품에 적용되었습니다.', [
                'option_ids' => $createdOptionIds,
                'option_mode' => $optionMode,
                'combos_created' => count($combos),
            ]);
        } catch (\Throwable $e) {
            $db->rollBack();
            return Result::failure('프리셋 옵션 적용 중 오류가 발생했습니다.');
        }
    }

    // =========================================================================
    // Private
    // =========================================================================

    /**
     * BASIC 옵션 값의 카테시안 곱으로 조합(combo) 자동 생성
     *
     * 예: 색상(빨강,파랑) × 사이즈(S,M,L) → 빨강/S, 빨강/M, 빨강/L, 파랑/S, ...
     *
     * @param int $goodsId 상품 ID
     * @param array $presetOptions 프리셋 옵션 배열 (BASIC만 조합 대상)
     * @return array 생성된 combo_id 목록
     */
    private function generateCombos(int $goodsId, array $presetOptions): array
    {
        // BASIC 옵션의 값 이름만 추출
        $basicValueSets = [];
        foreach ($presetOptions as $option) {
            if (($option['option_type'] ?? 'BASIC') !== 'BASIC') {
                continue;
            }
            $values = $option['values'] ?? [];
            if (empty($values)) {
                continue;
            }
            $basicValueSets[] = array_column($values, 'value_name');
        }

        if (count($basicValueSets) < 1) {
            return [];
        }

        // 카테시안 곱 생성
        $combinations = $this->cartesianProduct($basicValueSets);

        $comboIds = [];
        foreach ($combinations as $combo) {
            $combinationKey = implode('/', $combo);
            $comboId = $this->productOptionRepository->createCombo([
                'goods_id' => $goodsId,
                'combination_key' => $combinationKey,
                'extra_price' => 0,
                'stock_quantity' => 0,
                'is_active' => 1,
            ]);
            if ($comboId) {
                $comboIds[] = $comboId;
            }
        }

        return $comboIds;
    }

    /**
     * 배열의 카테시안 곱
     *
     * @param array $sets [['빨강','파랑'], ['S','M','L']]
     * @return array [['빨강','S'], ['빨강','M'], ...]
     */
    private function cartesianProduct(array $sets): array
    {
        $result = [[]];
        foreach ($sets as $set) {
            $temp = [];
            foreach ($result as $existing) {
                foreach ($set as $item) {
                    $temp[] = array_merge($existing, [$item]);
                }
            }
            $result = $temp;
        }
        return $result;
    }

    /**
     * 프리셋 옵션 + 값 일괄 생성
     *
     * @param int $presetId 프리셋 ID
     * @param array $options 옵션 배열
     */
    private function savePresetOptions(int $presetId, array $options): void
    {
        foreach ($options as $sortIndex => $option) {
            $optionData = [
                'option_name' => $option['option_name'] ?? '',
                'option_type' => $option['option_type'] ?? 'BASIC',
                'is_required' => isset($option['is_required']) ? (int) (bool) $option['is_required'] : 1,
                'sort_order' => (int) ($option['sort_order'] ?? $sortIndex),
            ];
            $optionId = $this->presetRepository->createOption($presetId, $optionData);

            if ($optionId && !empty($option['values'])) {
                foreach ($option['values'] as $valIndex => $value) {
                    $valueData = [
                        'value_name' => $value['value_name'] ?? '',
                        'extra_price' => (int) ($value['extra_price'] ?? 0),
                        'sort_order' => (int) ($value['sort_order'] ?? $valIndex),
                    ];
                    $this->presetRepository->createValue($optionId, $valueData);
                }
            }
        }
    }
}
