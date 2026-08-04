<?php
declare(strict_types=1);
namespace Mublo\Service\Menu;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Menu\MenuItemsFilterEvent;
use Mublo\Core\Result\Result;
use Mublo\Enum\Block\LayoutType;
use Mublo\Entity\Menu\MenuItem;
use Mublo\Entity\Menu\MenuNode;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Repository\Menu\MenuTreeRepository;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Infrastructure\Code\CodeGenerator;

/**
 * MenuService
 *
 * 메뉴 관리 서비스
 * - 메뉴 아이템 CRUD
 * - 메뉴 트리 구성
 * - 유틸리티/푸터 메뉴 관리
 */
class MenuService
{
    /** 사이드바 너비 오버라이드 상한(px) — 초과값은 상속(NULL)으로 떨군다. */
    private const SIDEBAR_WIDTH_MAX = 2000;

    private MenuItemRepository $itemRepository;
    private MenuTreeRepository $treeRepository;
    private CodeGenerator $codeGenerator;
    private ?CacheInterface $cache;
    private ?EventDispatcher $eventDispatcher;
    private ?Context $context;

    public function __construct(
        MenuItemRepository $itemRepository,
        MenuTreeRepository $treeRepository,
        CodeGenerator $codeGenerator,
        ?CacheInterface $cache = null,
        ?EventDispatcher $eventDispatcher = null,
        ?Context $context = null
    ) {
        $this->itemRepository = $itemRepository;
        $this->treeRepository = $treeRepository;
        $this->codeGenerator = $codeGenerator;
        $this->cache = $cache;
        $this->eventDispatcher = $eventDispatcher;
        $this->context = $context;
    }

    /**
     * 프론트 메뉴 아이템에 확장 필터를 적용한다.
     *
     * 브랜드샵·파트너 사이트처럼 같은 도메인 안에서도 문맥에 따라 감춰야 할 메뉴가 있다.
     * 그 판단은 코어가 알 수 없으므로 MenuItemsFilterEvent 로 확장에 넘긴다.
     *
     * 관리자 요청에서는 발행하지 않는다. 유틸리티·푸터·마이페이지 저장은 "포함 목록
     * 전체 교체"라, 표시용 필터로 감춰진 항목이 목록에서 빠진 채 저장되면 그 항목의
     * 노출 설정이 지워진다 — 표시 필터가 데이터를 지우는 결과가 된다. 구독자의 선의에
     * 기대지 않고 여기서 구조적으로 막는다.
     *
     * 트리는 평면 목록 단계에서 거른다. getTreeHierarchy() 가 이 결과로 계층을 세우므로
     * 깊이와 무관하게 같은 규칙이 적용된다.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function applyFrontItemsFilter(string $scope, int $domainId, array $items): array
    {
        if ($this->eventDispatcher === null || $this->context === null || !$this->context->isFront()) {
            return $items;
        }

        return $this->eventDispatcher->dispatch(
            new MenuItemsFilterEvent($domainId, $scope, $items, $this->context)
        )->getItems();
    }

    // ========================================
    // 메뉴 아이템 관리
    // ========================================

    /**
     * 도메인별 메뉴 아이템 목록 조회
     */
    public function getItems(int $domainId, bool $activeOnly = false): array
    {
        return $this->itemRepository->findByDomain($domainId, $activeOnly);
    }

    /** 현재 도메인에서 지정 URL 접두사를 사용하는 메뉴 아이템을 조회한다. */
    public function findItemsByUrlPrefix(int $domainId, string $urlPrefix): array
    {
        return $this->itemRepository->findByUrlPrefix($domainId, $urlPrefix);
    }

    /**
     * 검색/페이지네이션이 적용된 메뉴 아이템 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 항목 수
     * @param array $search 검색 조건 ['keyword' => '', 'field' => '']
     * @return array ['items' => [], 'pagination' => []]
     */
    /**
     * 정렬 컬럼/방향 정규화 (헤더 표시·링크 생성용). 기본 item_id DESC.
     *
     * @return array{0:string,1:string}
     */
    public function normalizeSort(?string $sort, ?string $order): array
    {
        return $this->itemRepository->resolveSort($sort, $order);
    }

    public function getItemsPaginated(int $domainId, int $page = 1, int $perPage = 20, array $search = [], ?string $sort = null, ?string $order = null): array
    {
        $result = $this->itemRepository->findPaginated($domainId, $page, $perPage, $search, $sort, $order);

        $totalPages = (int) ceil($result['total'] / $perPage);

        return [
            'items' => $result['items'],
            'pagination' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'totalItems' => $result['total'],
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * 도메인별 고유 제공자 목록 조회
     *
     * 제공자 유형별로 그룹화된 배열 반환:
     * ['core' => [], 'plugin' => ['Mshop', 'AutoForm'], 'package' => ['Shop']]
     */
    public function getProviderOptions(int $domainId): array
    {
        $rows = $this->itemRepository->findDistinctProviders($domainId);

        $grouped = ['core' => [], 'plugin' => [], 'package' => []];
        foreach ($rows as $row) {
            $type = $row['provider_type'] ?? 'core';
            $name = $row['provider_name'] ?? '';
            if (isset($grouped[$type]) && $name !== '' && $name !== null) {
                $grouped[$type][] = $name;
            }
        }

        return $grouped;
    }

    /**
     * 검색 필드 옵션 반환
     */
    public function getSearchFields(): array
    {
        return [
            'label' => '메뉴명',
            'url' => 'URL',
            'menu_code' => '메뉴코드',
            'provider_name' => '제공자',
        ];
    }

    /**
     * 메뉴 아이템 단건 조회
     *
     * @param int $itemId 아이템 ID
     * @param int|null $domainId 도메인 ID (소유권 검증, null이면 검증 생략)
     */
    public function getItem(int $itemId, ?int $domainId = null): ?MenuItem
    {
        $item = $this->itemRepository->find($itemId);

        // 도메인 소유권 검증
        if ($item !== null && $domainId !== null && $item->getDomainId() !== $domainId) {
            return null;
        }

        return $item;
    }

    /**
     * 메뉴 아이템 생성
     */
    public function createItem(int $domainId, array $data): Result
    {
        // 필수값 검증
        if (empty($data['label'])) {
            return Result::failure('메뉴명은 필수입니다.');
        }

        // 메뉴 코드 생성 (unique_codes 테이블에서 관리)
        $menuCode = $this->codeGenerator->generate('menu', 8);

        $insertData = [
            'domain_id' => $domainId,
            'menu_code' => $menuCode,
            'label' => $data['label'],
            'url' => $data['url'] ?? null,
            'icon' => $data['icon'] ?? null,
            'target' => $data['target'] ?? '_self',
            'visibility' => $data['visibility'] ?? 'all',
            'pair_code' => $data['pair_code'] ?? null,
            'min_level' => (int) ($data['min_level'] ?? 0),
            'required_permission' => $data['required_permission'] ?? null,
            'show_on_pc' => (int) ($data['show_on_pc'] ?? 1),
            'show_on_mobile' => (int) ($data['show_on_mobile'] ?? 1),
            'show_in_utility' => (int) ($data['show_in_utility'] ?? 0),
            'show_in_footer' => (int) ($data['show_in_footer'] ?? 0),
            'show_in_mypage' => (int) ($data['show_in_mypage'] ?? 0),
            'utility_order' => (int) ($data['utility_order'] ?? 0),
            'footer_order' => (int) ($data['footer_order'] ?? 0),
            'mypage_order' => (int) ($data['mypage_order'] ?? 0),
            'is_system' => (int) ($data['is_system'] ?? 0),
            'provider_type' => $data['provider_type'] ?? 'core',
            'provider_name' => $data['provider_name'] ?? null,
            'layout_type' => $this->clampLayoutType($data['layout_type'] ?? null),
            'sidebar_left_width' => $this->clampSidebarWidth($data['sidebar_left_width'] ?? null),
            'sidebar_left_mobile' => $this->normalizeSidebarMobile($data['sidebar_left_mobile'] ?? null),
            'sidebar_right_width' => $this->clampSidebarWidth($data['sidebar_right_width'] ?? null),
            'sidebar_right_mobile' => $this->normalizeSidebarMobile($data['sidebar_right_mobile'] ?? null),
            'is_active' => (int) ($data['is_active'] ?? 1),
        ];

        $itemId = $this->itemRepository->create($insertData);

        if (!$itemId) {
            return Result::failure('메뉴 생성에 실패했습니다.');
        }

        $this->invalidateUrlMapCache($domainId);

        return Result::success('메뉴가 생성되었습니다.', [
            'item_id' => $itemId,
            'menu_code' => $menuCode,
        ]);
    }

    /**
     * 메뉴 아이템 수정
     *
     * @param int $itemId 아이템 ID
     * @param array $data 수정 데이터
     * @param int|null $domainId 도메인 ID (소유권 검증, null이면 검증 생략)
     */
    public function updateItem(int $itemId, array $data, ?int $domainId = null): Result
    {
        $item = $this->getItem($itemId, $domainId);
        if (!$item) {
            return Result::failure('메뉴를 찾을 수 없습니다.');
        }

        $updateData = [];
        $allowedFields = [
            'label', 'url', 'icon', 'target', 'visibility', 'pair_code',
            'min_level', 'required_permission', 'show_on_pc', 'show_on_mobile',
            'show_in_utility', 'show_in_footer', 'show_in_mypage',
            'utility_order', 'footer_order', 'mypage_order', 'is_system',
            'is_active', 'provider_type', 'provider_name',
        ];

        $intFields = [
            'min_level', 'show_on_pc', 'show_on_mobile',
            'show_in_utility', 'show_in_footer', 'show_in_mypage',
            'utility_order', 'footer_order', 'mypage_order', 'is_system',
            'is_active',
        ];

        // 레이아웃 오버라이드 필드는 NULL(상속)을 보존하고 범위를 검증해야 하므로 일반 (int) 캐스트에서 제외한다.
        // 필드별 정규화기: 빈값/범위 밖 → NULL(상속), 유효값 → 정규화된 int.
        $layoutFieldNormalizers = [
            'layout_type' => fn ($v): ?int => $this->clampLayoutType($v),
            'sidebar_left_width' => fn ($v): ?int => $this->clampSidebarWidth($v),
            'sidebar_left_mobile' => fn ($v): ?int => $this->normalizeSidebarMobile($v),
            'sidebar_right_width' => fn ($v): ?int => $this->clampSidebarWidth($v),
            'sidebar_right_mobile' => fn ($v): ?int => $this->normalizeSidebarMobile($v),
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = in_array($field, $intFields) ? (int) $data[$field] : $data[$field];
            }
        }

        foreach ($layoutFieldNormalizers as $field => $normalizer) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $normalizer($data[$field]);
            }
        }

        if (empty($updateData)) {
            return Result::failure('수정할 데이터가 없습니다.');
        }

        $this->itemRepository->update($itemId, $updateData);

        // 트리의 path_name 업데이트 (라벨이 변경된 경우)
        if (isset($data['label']) && $data['label'] !== $item->getLabel()) {
            $this->updateTreePathNames($item->getDomainId(), $item->getMenuCode(), $data['label']);
        }

        $this->invalidateUrlMapCache($item->getDomainId());

        return Result::success('메뉴가 수정되었습니다.');
    }

    /**
     * 메뉴 아이템 삭제
     *
     * @param int $itemId 아이템 ID
     * @param int|null $domainId 도메인 ID (소유권 검증, null이면 검증 생략)
     */
    public function deleteItem(int $itemId, ?int $domainId = null): Result
    {
        $item = $this->getItem($itemId, $domainId);
        if (!$item) {
            return Result::failure('메뉴를 찾을 수 없습니다.');
        }

        $domainId = $item->getDomainId();

        try {
            $this->itemRepository->getDb()->transaction(function () use ($item, $itemId) {
                // 트리에서도 삭제
                $this->treeRepository->deleteByMenuCode($item->getDomainId(), $item->getMenuCode());

                // unique_codes에서 코드 삭제
                $this->codeGenerator->delete('menu', $item->getMenuCode());

                // 아이템 삭제
                $this->itemRepository->delete($itemId);
            });

            $this->invalidateUrlMapCache($domainId);

            return Result::success('메뉴가 삭제되었습니다.');
        } catch (\Throwable $e) {
            return Result::failure('메뉴 삭제에 실패했습니다.');
        }
    }

    /**
     * 메뉴 아이템 일괄 수정
     *
     * @param int   $domainId  현재 도메인 (소유권 경계 — 이 도메인 소유 메뉴만 수정 가능)
     * @param array $itemIds   수정할 아이템 ID 목록
     * @param array $fieldData 필드별 데이터 ['visibility' => [item_id => value], ...]
     * @return Result
     */
    public function bulkUpdateItems(int $domainId, array $itemIds, array $fieldData): Result
    {
        // 정수화 + 중복 제거 (crafted 요청의 문자열·중복 방어)
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if (empty($itemIds)) {
            return Result::failure('수정할 항목이 없습니다.');
        }

        // IDOR 방어: 요청된 item_id 가 모두 이 도메인 소유인지 사전 검증.
        // 하나라도 다른 도메인이거나 존재하지 않으면 전체 요청을 거부한다(부분 수정도 하지 않음).
        $ownedIds = $this->itemRepository->findOwnedItemIds($domainId, $itemIds);
        if (count($ownedIds) !== count($itemIds)) {
            return Result::failure('다른 도메인이거나 존재하지 않는 메뉴가 포함되어 있습니다.');
        }

        $allowedFields = ['min_level', 'show_on_pc', 'show_on_mobile', 'is_active'];

        try {
            $updatedCount = $this->itemRepository->getDb()->transaction(function () use ($domainId, $itemIds, $fieldData, $allowedFields) {
                $count = 0;

                foreach ($itemIds as $itemId) {
                    $updateData = [];

                    foreach ($allowedFields as $field) {
                        if (isset($fieldData[$field][$itemId])) {
                            $value = $fieldData[$field][$itemId];
                            // 모든 필드가 정수형
                            $updateData[$field] = (int) $value;
                        }
                    }

                    // layout_type 은 상속(NULL)·범위 검증이 필요하므로 별도 정규화 (빈값/범위 밖 → NULL 상속).
                    // 빈 문자열도 "상속으로 변경"이라는 유효한 지시이므로 array_key_exists 로 판정한다.
                    if (array_key_exists($itemId, $fieldData['layout_type'] ?? [])) {
                        $updateData['layout_type'] = $this->clampLayoutType($fieldData['layout_type'][$itemId]);
                    }

                    if (!empty($updateData)) {
                        // 도메인 스코프 UPDATE — 사전 검증을 통과했어도 WHERE 에 domain_id 를 걸어 심층 방어.
                        $this->itemRepository->updateInDomain($itemId, $domainId, $updateData);
                        $count++;
                    }
                }

                return $count;
            });

            if ($updatedCount === 0) {
                return Result::failure('수정된 항목이 없습니다.');
            }

            // 전달받은 도메인으로 직접 캐시 무효화 (재조회 없이).
            $this->invalidateUrlMapCache($domainId);

            return Result::success("{$updatedCount}개 항목이 수정되었습니다.", [
                'updated_count' => $updatedCount,
            ]);
        } catch (\Throwable $e) {
            return Result::failure('일괄 수정에 실패했습니다.');
        }
    }

    // ========================================
    // 메뉴 트리 관리
    // ========================================

    /**
     * 트리 구조 조회 (메뉴 아이템 정보 포함)
     */
    public function getTree(int $domainId, bool $activeOnly = true): array
    {
        return $this->applyFrontItemsFilter(
            MenuItemsFilterEvent::SCOPE_TREE,
            $domainId,
            $this->treeRepository->findTreeWithItems($domainId, $activeOnly)
        );
    }

    /**
     * 트리 구조를 계층형으로 변환
     */
    public function getTreeHierarchy(int $domainId, bool $activeOnly = true): array
    {
        $flatTree = $this->getTree($domainId, $activeOnly);
        return $this->buildHierarchy($flatTree);
    }

    /**
     * 평면 트리를 계층형으로 변환
     */
    private function buildHierarchy(array $flatTree): array
    {
        $hierarchy = [];
        $map = [];

        // 1차: path_code를 키로 매핑
        foreach ($flatTree as $node) {
            $node['children'] = [];
            $map[$node['path_code']] = $node;
        }

        // 2차: 부모-자식 연결
        foreach ($map as $pathCode => $node) {
            if (empty($node['parent_code'])) {
                $hierarchy[] = &$map[$pathCode];
            } else {
                if (isset($map[$node['parent_code']])) {
                    $map[$node['parent_code']]['children'][] = &$map[$pathCode];
                }
            }
        }

        return $hierarchy;
    }

    /**
     * 트리에 노드 추가
     */
    public function addToTree(int $domainId, string $menuCode, ?string $parentCode = null): Result
    {
        // 메뉴 아이템 존재 확인
        $item = $this->itemRepository->findByMenuCode($domainId, $menuCode);
        if (!$item) {
            return Result::failure('메뉴 아이템을 찾을 수 없습니다.');
        }

        // 부모 정보 확인
        $depth = 1;
        $pathCode = $menuCode;
        $pathName = $item['label'];

        if ($parentCode !== null) {
            $parent = $this->treeRepository->findByPathCode($domainId, $parentCode);
            if (!$parent) {
                return Result::failure('부모 메뉴를 찾을 수 없습니다.');
            }
            $depth = $parent['depth'] + 1;
            $pathCode = $parentCode . '>' . $menuCode;
            $pathName = $parent['path_name'] . '>' . $item['label'];
        }

        // 정렬 순서
        $sortOrder = $this->treeRepository->getMaxSortOrder($domainId, $parentCode) + 1;

        $nodeData = [
            'domain_id' => $domainId,
            'menu_code' => $menuCode,
            'path_code' => $pathCode,
            'path_name' => $pathName,
            'parent_code' => $parentCode,
            'depth' => $depth,
            'sort_order' => $sortOrder,
        ];

        $nodeId = $this->treeRepository->create($nodeData);

        if (!$nodeId) {
            return Result::failure('트리 추가에 실패했습니다.');
        }

        return Result::success('메뉴가 트리에 추가되었습니다.', [
            'node_id' => $nodeId,
            'path_code' => $pathCode,
        ]);
    }

    /**
     * 프로비저닝 키로 메뉴 아이템을 멱등 보장
     *
     * 확장이 사이트를 프로그래밍으로 구축할 때 쓴다. 같은 키로 다시 부르면
     * 기존 아이템을 그대로 반환하며 **라벨을 덮지 않는다** — 운영자가 고친
     * 이름을 재시도가 되돌리면 안 되기 때문이다.
     *
     * ## 스키마를 늘리지 않는다
     *
     * 확장이 자기 메뉴를 다시 찾는 방법은 이미 있다 —
     * `(provider_type, provider_name)` 조회다. Faq·Manual·Promotion 이 설치
     * 시 그 방식으로 중복 등록을 막는다.
     *
     * ```php
     * // plugins/Faq/FaqProvider.php
     * if (!empty($menuItemRepo->findByProvider($domainId, 'plugin', 'Faq'))) {
     *     return;   // 이미 등록돼 있다
     * }
     * ```
     *
     * 그 관례를 그대로 쓰되, 확장이 **여러 개**를 만드는 경우까지 다루려고
     * 키를 `url` 에 실어 구분한다. 프로비저닝으로 만든 항목은 URL 이
     * `#{키}` 이거나 호출자가 준 URL 이고, 어느 쪽이든 그 확장 소유
     * 목록 안에서 유일하다.
     *
     * **전용 컬럼을 두지 않는 이유:** 얻는 것은 "운영자가 라벨을 고친 뒤
     * 워커가 재시도할 때 중복이 안 생긴다" 는 좁은 경우인데, 대가는 코어
     * 스키마 변경이다. 기존 확장들이 그 대가 없이 살아온 자리다.
     *
     * @param array $data label(필수) · provider_type(필수) · provider_name(필수) · url 등
     * @return Result data: {menu_code, item_id, created}
     */
    public function ensureItem(int $domainId, string $provisioningKey, array $data): Result
    {
        $provisioningKey = trim($provisioningKey);
        if ($provisioningKey === '') {
            return Result::failure('프로비저닝 키는 필수입니다.');
        }

        $providerType = $data['provider_type'] ?? '';
        if (!in_array($providerType, ['core', 'plugin', 'package'], true)) {
            return Result::failure('provider_type 은 core·plugin·package 중 하나여야 합니다.');
        }

        $providerName = isset($data['provider_name']) && trim((string) $data['provider_name']) !== ''
            ? strtolower(trim((string) $data['provider_name']))
            : null;

        if ($providerType !== 'core' && $providerName === null) {
            return Result::failure('plugin·package 는 provider_name 이 필요합니다.');
        }

        // URL 이 이 항목의 식별자가 된다. 그룹 메뉴처럼 갈 곳이 없으면
        // 앵커로 둔다 — 클릭해도 페이지를 벗어나지 않고, 같은 확장 안에서
        // 키가 유일하므로 재조회로 찾을 수 있다.
        $url = trim((string) ($data['url'] ?? ''));
        if ($url === '') {
            $url = '#' . $provisioningKey;
        }
        $data['url'] = $url;

        $found = $this->findProvisionedItem($domainId, $providerType, $providerName, $url);
        if ($found !== null) {
            return Result::success('기존 메뉴를 사용합니다.', [
                'menu_code' => $found['menu_code'],
                'item_id' => (int) $found['item_id'],
                'created' => false,
            ]);
        }

        $data['provider_type'] = $providerType;
        $data['provider_name'] = $providerName;

        $result = $this->createItem($domainId, $data);
        if (!$result->isSuccess()) {
            return $result;
        }

        return Result::success('메뉴를 생성했습니다.', [
            'menu_code' => $result->getData()['menu_code'],
            'item_id' => (int) $result->getData()['item_id'],
            'created' => true,
        ]);
    }

    /**
     * 같은 확장이 만든 항목 중 URL 이 일치하는 것
     *
     * @return array<string, mixed>|null
     */
    private function findProvisionedItem(int $domainId, string $providerType, ?string $providerName, string $url): ?array
    {
        foreach ($this->itemRepository->findByProvider($domainId, $providerType, $providerName) as $item) {
            if (($item['url'] ?? '') === $url) {
                return $item;
            }
        }

        return null;
    }

    /**
     * 트리 배치를 멱등 보장
     *
     * 같은 위치에 이미 있으면 그대로 성공한다. **다른 위치의 같은 메뉴는 건드리지
     * 않는다** — 코어가 의도적으로 허용한 복수 배치이거나 운영자 편집일 수 있다.
     * 메뉴 구조를 바꾸는 것은 별도의 명시적 편집 작업이다.
     *
     * @param string|null $parentCode 부모 노드의 path_code (null 이면 1차 메뉴)
     * @return Result data: {node_id, path_code, created}
     */
    public function ensurePlacement(int $domainId, string $menuCode, ?string $parentCode = null): Result
    {
        $pathCode = $parentCode !== null ? $parentCode . '>' . $menuCode : $menuCode;

        $existing = $this->treeRepository->findByPathCode($domainId, $pathCode);
        if ($existing !== null) {
            return Result::success('이미 같은 위치에 있습니다.', [
                'node_id' => (int) $existing['node_id'],
                'path_code' => $pathCode,
                'created' => false,
            ]);
        }

        try {
            $result = $this->addToTree($domainId, $menuCode, $parentCode);
        } catch (\Throwable $e) {
            // 동시 호출이 먼저 배치했을 수 있다. 그 행이 있으면 같은 결과를 준다.
            // DB 제약으로 막지 않는 이유: 같은 노드를 같은 자리에 동시에 넣는 것은
            // 워커 하나가 도는 프로비저닝에서 일어나지 않고, 그걸 막자고 코어
            // 스키마를 늘리면 기존 설치가 마이그레이션 없이 돌지 못한다.
            $raced = $this->treeRepository->findByPathCode($domainId, $pathCode);
            if ($raced === null) {
                throw $e;
            }

            return Result::success('이미 같은 위치에 있습니다.', [
                'node_id' => (int) $raced['node_id'],
                'path_code' => $pathCode,
                'created' => false,
            ]);
        }

        if (!$result->isSuccess()) {
            return $result;
        }

        return Result::success('메뉴를 배치했습니다.', [
            'node_id' => (int) $result->getData()['node_id'],
            'path_code' => $result->getData()['path_code'],
            'created' => true,
        ]);
    }

    /**
     * 블록 페이지의 자동 등록 메뉴를 트리에 멱등 배치
     *
     * `BlockPageMenuSubscriber` 가 페이지 생성 시 menu_items 를 자동 등록하지만
     * menu_code 를 반환하지 않는다(이벤트 구독자라 돌려줄 데가 없다). 구독자와
     * 같은 조회 경로로 아이템을 찾아 ensurePlacement() 에 위임한다.
     *
     * @return Result data: {node_id, path_code, menu_code, created}
     */
    public function ensurePageMenuPlacement(int $domainId, string $pageCode, ?string $parentCode = null): Result
    {
        $targetUrl = '/p/' . $pageCode;
        $menuCode = null;

        foreach ($this->itemRepository->findByProvider($domainId, 'core', 'blockpage') as $item) {
            if (($item['url'] ?? '') === $targetUrl) {
                $menuCode = (string) $item['menu_code'];
                break;
            }
        }

        if ($menuCode === null) {
            return Result::failure("페이지 '{$pageCode}' 의 메뉴 아이템을 찾을 수 없습니다.");
        }

        $result = $this->ensurePlacement($domainId, $menuCode, $parentCode);
        if (!$result->isSuccess()) {
            return $result;
        }

        return Result::success($result->getMessage(), $result->getData() + ['menu_code' => $menuCode]);
    }

    /**
     * 트리에서 노드 제거
     */
    public function removeFromTree(int $nodeId): Result
    {
        $node = $this->treeRepository->find($nodeId);
        if (!$node) {
            return Result::failure('노드를 찾을 수 없습니다.');
        }

        // 자식 노드도 함께 삭제
        $this->treeRepository->deleteByPathPrefix($node->getDomainId(), $node->getPathCode());

        return Result::success('메뉴가 트리에서 제거되었습니다.');
    }

    /**
     * 트리 전체 저장 (재구성)
     */
    public function saveTree(int $domainId, array $treeData): Result
    {
        try {
            $this->treeRepository->getDb()->transaction(function () use ($domainId, $treeData) {
                // 기존 트리 삭제
                $this->treeRepository->deleteByDomain($domainId);

                // 새 트리 구성
                $this->insertTreeNodes($domainId, $treeData, null, 1);
            });

            return Result::success('메뉴 트리가 저장되었습니다.');
        } catch (\Throwable $e) {
            return Result::failure('메뉴 트리 저장에 실패했습니다.');
        }
    }

    /**
     * 트리 노드 재귀 삽입
     */
    private function insertTreeNodes(int $domainId, array $nodes, ?string $parentCode, int $depth): void
    {
        $sortOrder = 1;

        foreach ($nodes as $node) {
            $menuCode = $node['menu_code'];
            $item = $this->itemRepository->findByMenuCode($domainId, $menuCode);

            if (!$item) {
                continue;
            }

            $pathCode = $parentCode ? $parentCode . '>' . $menuCode : $menuCode;

            // 부모의 path_name 조회
            $pathName = $item['label'];
            if ($parentCode !== null) {
                $parentNode = $this->treeRepository->findByPathCode($domainId, $parentCode);
                if ($parentNode) {
                    $pathName = $parentNode['path_name'] . '>' . $item['label'];
                }
            }

            $nodeData = [
                'domain_id' => $domainId,
                'menu_code' => $menuCode,
                'path_code' => $pathCode,
                'path_name' => $pathName,
                'parent_code' => $parentCode,
                'depth' => $depth,
                'sort_order' => $sortOrder,
            ];

            $this->treeRepository->create($nodeData);

            // 자식 노드 처리
            if (!empty($node['children'])) {
                $this->insertTreeNodes($domainId, $node['children'], $pathCode, $depth + 1);
            }

            $sortOrder++;
        }
    }

    /**
     * 트리 path_name 업데이트 (메뉴 라벨 변경 시)
     */
    private function updateTreePathNames(int $domainId, string $menuCode, string $newLabel): void
    {
        $nodes = $this->treeRepository->findByMenuCode($domainId, $menuCode);

        foreach ($nodes as $node) {
            // 해당 노드의 path_name에서 이 메뉴의 라벨만 변경
            $pathParts = explode('>', $node['path_name']);
            $codeParts = explode('>', $node['path_code']);

            $index = array_search($menuCode, $codeParts);
            if ($index !== false && isset($pathParts[$index])) {
                $pathParts[$index] = $newLabel;
                $newPathName = implode('>', $pathParts);

                $this->treeRepository->update($node['node_id'], ['path_name' => $newPathName]);
            }

            // 자식 노드들의 path_name도 업데이트
            $this->updateChildrenPathNames($domainId, $node['path_code'], $menuCode, $newLabel);
        }
    }

    /**
     * 자식 노드들의 path_name 업데이트
     */
    private function updateChildrenPathNames(int $domainId, string $parentPathCode, string $menuCode, string $newLabel): void
    {
        $children = $this->treeRepository->findChildren($domainId, $parentPathCode);

        foreach ($children as $child) {
            $pathParts = explode('>', $child['path_name']);
            $codeParts = explode('>', $child['path_code']);

            $index = array_search($menuCode, $codeParts);
            if ($index !== false && isset($pathParts[$index])) {
                $pathParts[$index] = $newLabel;
                $newPathName = implode('>', $pathParts);

                $this->treeRepository->update($child['node_id'], ['path_name' => $newPathName]);
            }

            // 재귀 호출
            $this->updateChildrenPathNames($domainId, $child['path_code'], $menuCode, $newLabel);
        }
    }

    // ========================================
    // pair_code 확장
    // ========================================

    /**
     * 선택된 item_ids에서 pair_code 짝을 찾아 자동 추가
     *
     * 같은 pair_code를 가진 다른 아이템(활성 상태)을 자동으로 포함시킨다.
     * 예: 로그인(guest)만 선택 → 로그아웃(member)도 자동 추가
     */
    private function expandWithPairedItems(int $domainId, array $itemIds): array
    {
        if (empty($itemIds)) {
            return $itemIds;
        }

        $pairCodes = $this->itemRepository->findPairCodesByIds($domainId, $itemIds);

        if (empty($pairCodes)) {
            return $itemIds;
        }

        $pairedIds = $this->itemRepository->findPairedItemIds($domainId, $pairCodes, $itemIds);

        return array_merge($itemIds, $pairedIds);
    }

    // ========================================
    // 유틸리티/푸터 메뉴 관리
    // ========================================

    /**
     * 유틸리티 메뉴 조회
     */
    public function getUtilityMenus(int $domainId): array
    {
        return $this->applyFrontItemsFilter(
            MenuItemsFilterEvent::SCOPE_UTILITY,
            $domainId,
            $this->itemRepository->findUtilityMenus($domainId)
        );
    }

    /**
     * 푸터 메뉴 조회
     */
    public function getFooterMenus(int $domainId): array
    {
        return $this->applyFrontItemsFilter(
            MenuItemsFilterEvent::SCOPE_FOOTER,
            $domainId,
            $this->itemRepository->findFooterMenus($domainId)
        );
    }

    /**
     * 유틸리티 메뉴 저장 (포함 목록 + 순서 전체 교체)
     */
    public function saveUtilityOrder(int $domainId, array $itemIds): Result
    {
        $itemIds = $this->expandWithPairedItems($domainId, $itemIds);

        try {
            $this->itemRepository->getDb()->transaction(function () use ($domainId, $itemIds) {
                $this->itemRepository->resetUtilityFlags($domainId);
                $order = 1;
                foreach ($itemIds as $itemId) {
                    $this->itemRepository->setUtilityActive((int) $itemId, $domainId, $order++);
                }
            });

            return Result::success('유틸리티 메뉴가 저장되었습니다.');
        } catch (\Throwable $e) {
            return Result::failure('유틸리티 메뉴 저장에 실패했습니다.');
        }
    }

    /**
     * 푸터 메뉴 저장 (포함 목록 + 순서 전체 교체)
     */
    public function saveFooterOrder(int $domainId, array $itemIds): Result
    {
        $itemIds = $this->expandWithPairedItems($domainId, $itemIds);

        try {
            $this->itemRepository->getDb()->transaction(function () use ($domainId, $itemIds) {
                $this->itemRepository->resetFooterFlags($domainId);
                $order = 1;
                foreach ($itemIds as $itemId) {
                    $this->itemRepository->setFooterActive((int) $itemId, $domainId, $order++);
                }
            });

            return Result::success('푸터 메뉴가 저장되었습니다.');
        } catch (\Throwable $e) {
            return Result::failure('푸터 메뉴 저장에 실패했습니다.');
        }
    }

    /**
     * 마이페이지 메뉴 조회
     */
    public function getMypageMenus(int $domainId): array
    {
        return $this->applyFrontItemsFilter(
            MenuItemsFilterEvent::SCOPE_MYPAGE,
            $domainId,
            $this->itemRepository->findMypageMenus($domainId)
        );
    }

    /**
     * 마이페이지 메뉴 저장 (포함 목록 + 순서 전체 교체, 시스템 메뉴 제외)
     */
    public function saveMypageOrder(int $domainId, array $itemIds): Result
    {
        $itemIds = $this->expandWithPairedItems($domainId, $itemIds);

        try {
            $this->itemRepository->getDb()->transaction(function () use ($domainId, $itemIds) {
                // 시스템 메뉴(회원정보=head/회원탈퇴=tail)는 sentinel order(0/9999)를 유지한다.
                // resetMypageFlags가 is_system 행을 건드리지 않고, 재부여 루프에서도 제외하여
                // 운영자 저장·패키지 재시딩과 무관하게 항상 양끝에 고정되도록 한다.
                $systemIds = $this->itemRepository->findSystemItemIds($domainId);
                $this->itemRepository->resetMypageFlags($domainId);
                $order = 1;
                foreach ($itemIds as $itemId) {
                    if (in_array((int) $itemId, $systemIds, true)) {
                        continue;
                    }
                    $this->itemRepository->setMypageActive((int) $itemId, $domainId, $order++);
                }
            });

            return Result::success('마이페이지 메뉴가 저장되었습니다.');
        } catch (\Throwable $e) {
            return Result::failure('마이페이지 메뉴 저장에 실패했습니다.');
        }
    }

    // ========================================
    // 옵션 목록
    // ========================================

    /**
     * target 옵션
     */
    public function getTargetOptions(): array
    {
        return [
            '_self' => '현재 창',
            '_blank' => '새 창',
        ];
    }

    // ========================================
    // 캐시 관리
    // ========================================

    /**
     * 메뉴 URL 맵 캐시 무효화
     *
     * ContextBuilder의 메뉴 매칭에서 사용하는 캐시를 삭제합니다.
     * 메뉴 생성/수정/삭제 시 호출되어 다음 요청에서 최신 데이터를 사용하게 합니다.
     */
    private function invalidateUrlMapCache(int $domainId): void
    {
        $this->cache?->delete("menu:urlmap:{$domainId}");
    }

    /**
     * 빈 문자열/null 은 NULL(상속)로, 정수 형식(정수 또는 순수 정수 문자열)만 int 로 파싱한다.
     *
     * '1abc'·'3.9'·'abc' 처럼 정수 형식이 아니면 NULL(상속)로 떨군다 —
     * (int) 캐스트로 malformed 입력을 조용히 정상값(1, 3, 0)으로 바꾸지 않기 위함.
     * 레이아웃 오버라이드 컬럼은 일반 (int) 캐스트를 쓰면 상속(NULL)이 0으로도 뭉개진다.
     */
    private function strictNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    /**
     * layout_type 을 유효 범위(LayoutType 1~4)로 검증한다.
     * 빈값·정수 아님·범위 밖(0, 999 등)은 모두 NULL(상속)로 떨군다 — 관리자 폼 밖의 조작된 요청 방어.
     */
    private function clampLayoutType(mixed $value): ?int
    {
        $int = $this->strictNullableInt($value);
        if ($int === null) {
            return null;
        }
        return ($int >= LayoutType::FULL->value && $int <= LayoutType::BOTH->value) ? $int : null;
    }

    /**
     * 사이드바 너비를 정상 픽셀 범위(1~self::SIDEBAR_WIDTH_MAX)로 검증한다.
     * 빈값·정수 아님·0 이하·과대값은 NULL(상속)로 떨궈 사이트 기본 폭을 쓰게 한다.
     */
    private function clampSidebarWidth(mixed $value): ?int
    {
        $int = $this->strictNullableInt($value);
        if ($int === null) {
            return null;
        }
        return ($int >= 1 && $int <= self::SIDEBAR_WIDTH_MAX) ? $int : null;
    }

    /**
     * 사이드바 모바일 출력 플래그를 NULL(상속)/0/1 로 정규화한다.
     * 정확히 0/1 만 허용하고, '2'·'abc' 등은 NULL(상속)로 떨군다.
     */
    private function normalizeSidebarMobile(mixed $value): ?int
    {
        $int = $this->strictNullableInt($value);
        if ($int === null) {
            return null;
        }
        return ($int === 0 || $int === 1) ? $int : null;
    }

    // ========================================
    // 기본 메뉴 시딩
    // ========================================

    /**
     * 신규 도메인에 기본 메뉴 시딩
     *
     * 최초 설치 시 seeder(001_seed_menu_data.sql)와 동일한 코어 메뉴 구성을 생성한다.
     * - 메인 메뉴: 홈
     * - 유틸리티 메뉴: 로그인/회원가입 (비회원), 마이페이지/로그아웃 (회원)
     * - 마이페이지 메뉴: 회원정보수정, 알림, 포인트 지갑, 회원탈퇴
     * - 푸터 메뉴: 이용약관, 개인정보보호정책
     * - 메뉴 트리: 홈 (루트 노드)
     * (커뮤니티/내가 쓴 글/댓글은 Board 패키지가 후보 메뉴로 시딩)
     */
    public function seedDefaultMenus(int $domainId): Result
    {
        $definitions = $this->getDefaultMenuDefinitions();
        $createdCodes = [];

        foreach ($definitions as $key => $def) {
            $result = $this->createItem($domainId, $def);
            if ($result->isFailure()) {
                return Result::failure("기본 메뉴 생성 실패: {$def['label']}");
            }
            $createdCodes[$key] = $result->get('menu_code');
        }

        // 메인 메뉴 트리에 홈 배치 (커뮤니티는 Board 후보 메뉴 → 운영자가 배치)
        if (isset($createdCodes['home'])) {
            $treeResult = $this->addToTree($domainId, $createdCodes['home']);
            if ($treeResult->isFailure()) {
                return Result::failure('기본 홈 메뉴를 트리에 추가하지 못했습니다.');
            }
        }

        return Result::success('기본 메뉴가 생성되었습니다.');
    }

    /**
     * 기본 메뉴 정의
     *
     * createItem()에 전달할 데이터 배열
     */
    private function getDefaultMenuDefinitions(): array
    {
        return [
            // === 메인 메뉴 ===
            'home' => [
                'label' => '홈', 'url' => '/',
                'show_in_footer' => 1, 'footer_order' => 1,
                'provider_type' => 'core',
            ],
            // 커뮤니티/내가 쓴 글/댓글은 Board 패키지 소유 → Board가 후보 메뉴로 시딩(코어 기본 메뉴 아님).

            // === 유틸리티 메뉴 (비회원) ===
            'login' => [
                'label' => '로그인', 'url' => '/login',
                'visibility' => 'guest', 'pair_code' => 'auth',
                'show_in_utility' => 1, 'utility_order' => 1,
                'provider_type' => 'core',
            ],
            'register' => [
                'label' => '회원가입', 'url' => '/member/register',
                'visibility' => 'guest', 'pair_code' => 'account',
                'show_in_utility' => 1, 'utility_order' => 2,
                'provider_type' => 'core',
            ],

            // === 유틸리티 메뉴 (회원) ===
            'mypage' => [
                'label' => '마이페이지', 'url' => '/mypage',
                'visibility' => 'member', 'pair_code' => 'account',
                'show_in_utility' => 1, 'utility_order' => 1,
                'provider_type' => 'core',
            ],
            'logout' => [
                'label' => '로그아웃', 'url' => '/logout',
                'visibility' => 'member', 'pair_code' => 'auth',
                'show_in_utility' => 1, 'utility_order' => 2,
                'provider_type' => 'core',
            ],

            // === 마이페이지 서브 메뉴 ===
            // 시스템 메뉴는 sentinel order로 양끝 고정: 회원정보=0(head) / 회원탈퇴=9999(tail).
            // 일반 메뉴는 저장 시 1..N으로 재부여되므로 항상 그 사이에 위치한다.
            'mypage_profile' => [
                'label' => '회원정보', 'url' => '/mypage/profile',
                'visibility' => 'member', 'show_in_mypage' => 1, 'mypage_order' => 0,
                'is_system' => 1, 'provider_type' => 'core',
            ],
            'mypage_notifications' => [
                'label' => '알림', 'url' => '/mypage/notifications',
                'visibility' => 'member', 'show_in_mypage' => 1, 'mypage_order' => 1,
                'provider_type' => 'core',
            ],
            'mypage_balance' => [
                'label' => '포인트 지갑', 'url' => '/mypage/balance',
                'visibility' => 'member', 'show_in_mypage' => 1, 'mypage_order' => 2,
                'provider_type' => 'core',
            ],
            // 내가 쓴 글/댓글은 Board 후보 메뉴로 이관 → Board가 시딩(코어 기본 메뉴 아님).
            'mypage_withdraw' => [
                'label' => '회원탈퇴', 'url' => '/mypage/withdraw',
                'visibility' => 'member', 'show_in_mypage' => 1, 'mypage_order' => 9999,
                'is_system' => 1, 'provider_type' => 'core',
            ],

            // === 푸터 메뉴 ===
            'terms' => [
                'label' => '이용약관', 'url' => '/terms',
                'show_in_footer' => 1, 'footer_order' => 2,
                'provider_type' => 'core',
            ],
            'privacy' => [
                'label' => '개인정보보호정책', 'url' => '/privacy',
                'show_in_footer' => 1, 'footer_order' => 3,
                'provider_type' => 'core',
            ],
        ];
    }
}
