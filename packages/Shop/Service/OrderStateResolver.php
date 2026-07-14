<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Packages\Shop\Enum\OrderAction;

/**
 * OrderStateResolver
 *
 * Config에서 order_states JSON을 로드하고 전이 규칙을 검증하는 전담 클래스.
 * OrderService가 직접 Config를 파싱하지 않도록 책임 분리.
 *
 * 책임:
 * - 상태 전이 가능 여부 검증 (to 단방향)
 * - 상태 id → 라벨/액션 변환
 * - 이동 가능 상태 목록 조회
 *
 * 금지:
 * - Config 저장 (ShopConfigService 담당)
 * - 비즈니스 로직 (OrderService 담당)
 */
class OrderStateResolver
{
    private ShopConfigService $configService;

    /** @var array<int, array[]> 도메인별 캐시 */
    private array $cache = [];

    public function __construct(ShopConfigService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * 상태 전이 가능 여부 검증
     *
     * current.to에 target이 있으면 허용 (단방향)
     *
     * @param int $domainId 도메인 ID
     * @param string $currentStateId 현재 상태 id
     * @param string $targetStateId 목표 상태 id
     * @return bool
     */
    public function canTransition(int $domainId, string $currentStateId, string $targetStateId): bool
    {
        $states = $this->getAllStates($domainId);
        $current = $this->findById($states, $currentStateId);
        $target = $this->findById($states, $targetStateId);

        if (!$current || !$target) {
            return false;
        }

        // terminal 상태에서는 전이 불가
        if ($current['terminal'] ?? false) {
            return false;
        }

        return in_array($targetStateId, $current['to'] ?? [], true);
    }

    /**
     * 상태 id로 상태 정의 조회
     *
     * @param int $domainId 도메인 ID
     * @param string $stateId 상태 id
     * @return array|null {id, label, description, action, to, terminal, system, sort_order}
     */
    public function getState(int $domainId, string $stateId): ?array
    {
        $states = $this->getAllStates($domainId);
        return $this->findById($states, $stateId);
    }

    /**
     * 현재 상태에서 이동 가능한 상태 목록
     *
     * @param int $domainId 도메인 ID
     * @param string $currentStateId 현재 상태 id
     * @return array[] 이동 가능한 상태 정의 배열
     */
    public function getAvailableTransitions(int $domainId, string $currentStateId): array
    {
        $states = $this->getAllStates($domainId);
        $current = $this->findById($states, $currentStateId);

        if (!$current || ($current['terminal'] ?? false)) {
            return [];
        }

        $available = [];
        foreach ($states as $state) {
            if ($state['id'] === $currentStateId) {
                continue;
            }
            if ($this->canTransition($domainId, $currentStateId, $state['id'])) {
                $available[] = $state;
            }
        }

        return $available;
    }

    /**
     * 상태 id → 라벨 변환 (스냅샷용)
     *
     * @param int $domainId 도메인 ID
     * @param string $stateId 상태 id
     * @return string 라벨 (없으면 id 반환)
     */
    public function getLabel(int $domainId, string $stateId): string
    {
        $state = $this->getState($domainId, $stateId);
        if ($state !== null) {
            return $state['label'] ?? $stateId;
        }
        // 그래프 밖 내부 상태(예: attempting)는 enum 기본 라벨로 폴백
        return OrderAction::tryFrom($stateId)?->defaultLabel() ?? $stateId;
    }

    /**
     * 상태 id → OrderAction Enum 변환
     *
     * 시스템 액션이면 Enum, 커스텀이면 null
     *
     * @param string $stateId 상태 id
     * @param array $stateDef 상태 정의 배열
     * @return OrderAction|null
     */
    public function getAction(string $stateId, array $stateDef): ?OrderAction
    {
        $actionValue = $stateDef['action'] ?? 'custom';

        if ($actionValue === 'custom') {
            return null;
        }

        return OrderAction::tryFrom($actionValue);
    }

    /**
     * order_states JSON 전체 로드 (캐싱)
     *
     * @param int $domainId 도메인 ID
     * @return array[] 상태 정의 배열
     */
    public function getAllStates(int $domainId): array
    {
        if (isset($this->cache[$domainId])) {
            return $this->cache[$domainId];
        }

        $result = $this->configService->getConfig($domainId);
        $config = $result->get('config', []);
        $orderStatesJson = $config['order_states'] ?? '';

        if (empty($orderStatesJson)) {
            $this->cache[$domainId] = OrderAction::defaultStates();
            return $this->cache[$domainId];
        }

        $decoded = is_string($orderStatesJson)
            ? json_decode($orderStatesJson, true)
            : $orderStatesJson;

        $states = is_array($decoded) && !empty($decoded)
            ? $decoded
            : OrderAction::defaultStates();

        // 기본 상태 정의에서 누락 필드 보충 (기존 저장 데이터 호환)
        $defaults = [];
        foreach (OrderAction::defaultStates() as $def) {
            $defaults[$def['id']] = $def;
        }
        foreach ($states as &$state) {
            $id = $state['id'] ?? '';
            if (isset($defaults[$id])) {
                // 시스템 상태: 기본값에서 누락 키 보충
                $state += $defaults[$id];
            } else {
                // 커스텀 상태: 구조 키 기본값 보충
                $state += [
                    'delivery_editable' => false,
                    'terminal' => false,
                    'system' => false,
                ];
            }
        }
        unset($state);

        $this->cache[$domainId] = $states;

        return $this->cache[$domainId];
    }

    /**
     * 현재 상태에서 배송정보 편집 가능 여부
     */
    public function isDeliveryEditable(int $domainId, string $stateId): bool
    {
        $state = $this->getState($domainId, $stateId);
        return (bool) ($state['delivery_editable'] ?? false);
    }

    /**
     * 캐시 초기화 (설정 저장 후 호출)
     */
    public function clearCache(?int $domainId = null): void
    {
        if ($domainId !== null) {
            unset($this->cache[$domainId]);
        } else {
            $this->cache = [];
        }
    }

    /**
     * id로 상태 검색
     */
    private function findById(array $states, string $id): ?array
    {
        foreach ($states as $state) {
            if (($state['id'] ?? '') === $id) {
                return $state;
            }
        }
        return null;
    }
}
