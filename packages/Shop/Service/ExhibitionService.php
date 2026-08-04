<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Packages\Shop\Repository\ExhibitionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Event\ExhibitionCreatedEvent;
use Mublo\Packages\Shop\Event\ExhibitionUpdatedEvent;
use Mublo\Packages\Shop\Event\ExhibitionDeletedEvent;

/**
 * ExhibitionService
 *
 * 기획전 비즈니스 로직
 *
 * 책임:
 * - 기획전 CRUD
 * - 기획전 상품/카테고리 연결
 * - 기간 및 활성화 상태 검증
 */
class ExhibitionService
{
    private ExhibitionRepository $exhibitionRepository;
    private ?EventDispatcher $eventDispatcher;

    private const ALLOWED_FIELDS = [
        'title', 'description', 'slug',
        'banner_image', 'banner_mobile_image',
        'start_date', 'end_date',
        'is_active', 'sort_order',
    ];

    public function __construct(
        ExhibitionRepository $exhibitionRepository,
        private ProductRepository $productRepository,
        private CategoryRepository $categoryRepository,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $this->exhibitionRepository = $exhibitionRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @template T of EventInterface
     * @param T $event
     * @return T
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    public function getList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $result = $this->exhibitionRepository->getList($domainId, $filters, $page, $perPage);

        $items = array_map(fn($e) => $e->toArray(), $result['items']);

        // 연결 아이템 개수(상품/카테고리) 배치 집계
        $counts = $this->exhibitionRepository->getItemCountsByExhibitionIds(
            array_map('intval', array_column($items, 'exhibition_id'))
        );
        foreach ($items as &$it) {
            $c = $counts[(int) $it['exhibition_id']] ?? ['goods' => 0, 'category' => 0];
            $it['goods_count']    = $c['goods'];
            $it['category_count'] = $c['category'];
        }
        unset($it);

        return [
            'items'      => $items,
            'pagination' => $result['pagination'],
        ];
    }

    public function getDetail(int $domainId, int $exhibitionId): ?array
    {
        $exhibition = $this->exhibitionRepository->findInDomain($domainId, $exhibitionId);
        if (!$exhibition) {
            return null;
        }
        $data        = $exhibition->toArray();
        $data['items'] = $this->exhibitionRepository->getItems($exhibitionId);
        return $data;
    }

    public function getDetailBySlug(int $domainId, string $slug): ?array
    {
        $exhibition = $this->exhibitionRepository->findBySlug($domainId, $slug);
        if (!$exhibition) {
            return null;
        }
        $data          = $exhibition->toArray();
        $data['items'] = $this->exhibitionRepository->getItems($exhibition->getExhibitionId());
        return $data;
    }

    public function getActiveList(int $domainId): array
    {
        return array_map(
            fn($e) => $e->toArray(),
            $this->exhibitionRepository->getActiveList($domainId)
        );
    }

    public function create(int $domainId, array $data): Result
    {
        if (empty($data['title'])) {
            return Result::failure('기획전 제목을 입력해주세요.');
        }

        // 기간 검증
        if (!empty($data['start_date']) && !empty($data['end_date'])) {
            if (strtotime($data['start_date']) >= strtotime($data['end_date'])) {
                return Result::failure('종료일이 시작일보다 늦어야 합니다.');
            }
        }

        $insertData = $this->filterData($data);
        $insertData['domain_id'] = $domainId;

        // slug 형식 검증 + 중복 확인
        if (!empty($insertData['slug'])) {
            if ($err = $this->validateSlug((string) $insertData['slug'])) {
                return Result::failure($err);
            }
            if ($this->exhibitionRepository->slugExists($domainId, $insertData['slug'])) {
                return Result::failure('이미 사용 중인 슬러그입니다.');
            }
        }

        $id = $this->exhibitionRepository->create($insertData);
        if (!$id) {
            return Result::failure('기획전 생성에 실패했습니다.');
        }

        $this->dispatch(new ExhibitionCreatedEvent(
            $domainId,
            $id,
            $data['title'],
            $insertData['slug'] ?? null
        ));

        return Result::success('기획전이 등록되었습니다.', ['exhibition_id' => $id]);
    }

    public function update(int $domainId, int $exhibitionId, array $data): Result
    {
        $exhibition = $this->exhibitionRepository->findInDomain($domainId, $exhibitionId);
        if (!$exhibition) {
            return Result::failure('기획전을 찾을 수 없습니다.');
        }

        if (!empty($data['start_date']) && !empty($data['end_date'])) {
            if (strtotime($data['start_date']) >= strtotime($data['end_date'])) {
                return Result::failure('종료일이 시작일보다 늦어야 합니다.');
            }
        }

        $updateData = $this->filterData($data);

        // slug 형식 검증 + 중복 확인
        if (!empty($updateData['slug'])) {
            if ($err = $this->validateSlug((string) $updateData['slug'])) {
                return Result::failure($err);
            }
            if ($this->exhibitionRepository->slugExists($exhibition->getDomainId(), $updateData['slug'], $exhibitionId)) {
                return Result::failure('이미 사용 중인 슬러그입니다.');
            }
        }

        $oldSlug  = $exhibition->getSlug();
        $oldTitle = $exhibition->getTitle();

        $this->exhibitionRepository->updateInDomain($domainId, $exhibitionId, $updateData);

        // 슬러그/제목 변경분을 반영해 메뉴 아이템 동기화(구독자는 있을 때만 갱신, 없으면 no-op)
        $newSlug  = array_key_exists('slug', $updateData) ? $updateData['slug'] : $oldSlug;
        $newTitle = array_key_exists('title', $updateData) ? (string) $updateData['title'] : $oldTitle;

        $this->dispatch(new ExhibitionUpdatedEvent(
            $exhibition->getDomainId(),
            $exhibitionId,
            $newTitle,
            $oldSlug,
            $newSlug
        ));

        return Result::success('기획전이 수정되었습니다.');
    }

    public function delete(int $domainId, int $exhibitionId): Result
    {
        $exhibition = $this->exhibitionRepository->findInDomain($domainId, $exhibitionId);
        if (!$exhibition) {
            return Result::failure('기획전을 찾을 수 없습니다.');
        }

        $this->exhibitionRepository->deleteInDomain($domainId, $exhibitionId);

        $this->dispatch(new ExhibitionDeletedEvent($domainId, $exhibitionId, $exhibition->getSlug()));

        return Result::success('기획전이 삭제되었습니다.');
    }

    public function addItem(int $domainId, int $exhibitionId, array $data): Result
    {
        if (!$this->exhibitionRepository->findInDomain($domainId, $exhibitionId)) {
            return Result::failure('기획전을 찾을 수 없습니다.');
        }

        $targetType = $data['target_type'] ?? '';
        if (!in_array($targetType, ['goods', 'category'], true)) {
            return Result::failure('대상 유형이 올바르지 않습니다.');
        }

        if ($targetType === 'goods'
            && !$this->productRepository->findInDomain($domainId, (int) ($data['goods_id'] ?? 0))) {
            return Result::failure('현재 도메인의 상품을 찾을 수 없습니다.');
        }
        if ($targetType === 'category'
            && !$this->categoryRepository->findByCode($domainId, (string) ($data['category_code'] ?? ''))) {
            return Result::failure('현재 도메인의 카테고리를 찾을 수 없습니다.');
        }

        $insertData = [
            'exhibition_id' => $exhibitionId,
            'target_type'   => $targetType,
            'goods_id'      => isset($data['goods_id']) ? (int) $data['goods_id'] : null,
            'category_code' => $data['category_code'] ?? null,
            'sort_order'    => (int) ($data['sort_order'] ?? 0),
        ];

        $itemId = $this->exhibitionRepository->addItem($insertData);
        if (!$itemId) {
            return Result::failure('아이템 추가에 실패했습니다.');
        }

        return Result::success('아이템이 추가되었습니다.', ['item_id' => $itemId]);
    }

    public function deleteItem(int $domainId, int $itemId): Result
    {
        $deleted = $this->exhibitionRepository->deleteItemInDomain($domainId, $itemId);
        return $deleted
            ? Result::success('아이템이 삭제되었습니다.')
            : Result::failure('아이템 삭제에 실패했습니다.');
    }

    public function syncItems(int $domainId, int $exhibitionId, array $items): Result
    {
        if (!$this->exhibitionRepository->findInDomain($domainId, $exhibitionId)) {
            return Result::failure('기획전을 찾을 수 없습니다.');
        }

        $validatedItems = [];
        foreach ($items as $item) {
            $targetType = $item['target_type'] ?? 'goods';
            if ($targetType === 'goods'
                && !$this->productRepository->findInDomain($domainId, (int) ($item['goods_id'] ?? 0))) {
                return Result::failure('현재 도메인의 상품을 찾을 수 없습니다.');
            }
            if ($targetType === 'category'
                && !$this->categoryRepository->findByCode($domainId, (string) ($item['category_code'] ?? ''))) {
                return Result::failure('현재 도메인의 카테고리를 찾을 수 없습니다.');
            }
            if (!in_array($targetType, ['goods', 'category'], true)) {
                return Result::failure('대상 유형이 올바르지 않습니다.');
            }
            $item['target_type'] = $targetType;
            $validatedItems[] = $item;
        }

        $this->exhibitionRepository->deleteItemsByExhibition($exhibitionId);

        foreach ($validatedItems as $idx => $item) {
            $this->exhibitionRepository->addItem([
                'exhibition_id' => $exhibitionId,
                'target_type'   => $item['target_type'],
                'goods_id'      => isset($item['goods_id']) ? (int) $item['goods_id'] : null,
                'category_code' => $item['category_code'] ?? null,
                'sort_order'    => (int) ($item['sort_order'] ?? $idx),
            ]);
        }

        return Result::success('아이템이 동기화되었습니다.');
    }

    /**
     * 슬러그 형식 검증 — 영문·숫자·하이픈(-)만 허용, 숫자-only 금지(숫자 URL은 id 라우트에 가려짐).
     * @return string|null 오류 메시지(유효하면 null)
     */
    private function validateSlug(string $slug): ?string
    {
        if (ctype_digit($slug)) {
            return '슬러그는 숫자만으로 구성할 수 없습니다.';
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return '슬러그는 영문 소문자·숫자·하이픈(-)만 사용할 수 있습니다.';
        }
        return null;
    }

    private function filterData(array $data): array
    {
        $filtered = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));

        if (isset($filtered['is_active'])) {
            $filtered['is_active'] = (int) (bool) $filtered['is_active'];
        }
        if (isset($filtered['sort_order'])) {
            $filtered['sort_order'] = (int) $filtered['sort_order'];
        }

        // 빈 문자열 날짜 → null
        foreach (['start_date', 'end_date'] as $field) {
            if (isset($filtered[$field]) && $filtered[$field] === '') {
                $filtered[$field] = null;
            }
        }

        // slug: 소문자 변환 + 공백 트림, 빈 값 → null (대문자는 자동 소문자화)
        if (isset($filtered['slug'])) {
            $filtered['slug'] = strtolower(trim($filtered['slug']));
            if ($filtered['slug'] === '') {
                $filtered['slug'] = null;
            }
        }

        return $filtered;
    }
}
