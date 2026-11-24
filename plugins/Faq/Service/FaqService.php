<?php
declare(strict_types=1);
namespace Mublo\Plugin\Faq\Service;

use Mublo\Contract\Faq\FaqQueryInterface;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Result\Result;
use Mublo\Helper\Editor\EditorHelper;
use Mublo\Plugin\Faq\Event\FaqContentChangedEvent;
use Mublo\Plugin\Faq\Event\FaqItemDeletedEvent;
use Mublo\Plugin\Faq\Event\FaqItemFormEvent;
use Mublo\Plugin\Faq\Event\FaqItemSavedEvent;
use Mublo\Plugin\Faq\Event\FaqItemsLoadedEvent;
use Mublo\Plugin\Faq\Repository\FaqRepository;

/**
 * FaqService
 *
 * FAQ 비즈니스 로직 + FaqQueryInterface 구현
 *
 * 확장 지점 (패키지가 구독 — 플러그인은 붙는 값의 뜻을 모른다):
 *  - FaqItemFormEvent                        : 관리자 항목 편집 창에 확장 칸 (collectItemFormSections)
 *  - FaqItemsLoadedEvent                     : 관리자 목록(ext·badges) / 손님 화면(related_links)
 *  - FaqItemSavedEvent / FaqItemDeletedEvent : 항목 저장·삭제 뒤 (카테고리 삭제로 함께 지워진 항목 포함)
 */
class FaqService implements FaqQueryInterface
{
    private FaqRepository $faqRepository;

    public function __construct(
        FaqRepository $faqRepository,
        private readonly ?EventDispatcher $eventDispatcher = null
    )
    {
        $this->faqRepository = $faqRepository;
    }

    // ─────────────────────────────────────────
    // 확장 지점
    // ─────────────────────────────────────────

    /**
     * 관리자 항목 편집 창에 확장이 넣을 HTML 조각 (FaqItemFormEvent)
     *
     * @return string[]
     */
    public function collectItemFormSections(int $domainId): array
    {
        if ($this->eventDispatcher === null) {
            return [];
        }

        $event = new FaqItemFormEvent($domainId);
        $this->eventDispatcher->dispatch($event);

        return $event->getSections();
    }

    /**
     * 손님 화면용 — 그룹 항목마다 확장이 단 관련 링크(related_links)·제목(related_title)을 붙인다.
     *
     * @param array $grouped [{category_name, category_slug, items: [{faq_id, question, answer}]}]
     */
    public function withRelatedLinks(int $domainId, array $grouped): array
    {
        $ids = [];
        foreach ($grouped as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                $ids[] = (int) ($item['faq_id'] ?? 0);
            }
        }
        $ids = array_values(array_filter(array_unique($ids)));
        if ($ids === [] || $this->eventDispatcher === null) {
            return $grouped;
        }

        $event = new FaqItemsLoadedEvent($domainId, $ids, FaqItemsLoadedEvent::SCOPE_FRONT);
        $this->eventDispatcher->dispatch($event);

        foreach ($grouped as $g => $group) {
            foreach ((array) ($group['items'] ?? []) as $i => $item) {
                $grouped[$g]['items'][$i]['related_links'] = $event->getRelatedLinks((int) ($item['faq_id'] ?? 0));
                $grouped[$g]['items'][$i]['related_title'] = $event->getRelatedTitle();
            }
        }

        return $grouped;
    }

    /**
     * 관리자 목록용 — 항목마다 확장 값(ext)·짧은 표시(badges)를 붙인다.
     *
     * ext 는 JSON 으로 내려갈 때 빈 값도 객체({})가 되도록 object 로 둔다.
     */
    private function withAdminExtras(int $domainId, array $items): array
    {
        $ids = array_values(array_filter(array_map(static fn($i) => (int) ($i['faq_id'] ?? 0), $items)));
        if ($ids === [] || $this->eventDispatcher === null) {
            return $items;
        }

        $event = new FaqItemsLoadedEvent($domainId, $ids, FaqItemsLoadedEvent::SCOPE_ADMIN);
        $this->eventDispatcher->dispatch($event);

        foreach ($items as $i => $item) {
            $id = (int) ($item['faq_id'] ?? 0);
            $items[$i]['ext'] = (object) $event->getExtras($id);
            $items[$i]['badges'] = $event->getBadges($id);
        }

        return $items;
    }

    // ─────────────────────────────────────────
    // Contract 구현 (FaqQueryInterface)
    // ─────────────────────────────────────────

    public function getCategories(int $domainId): array
    {
        return $this->faqRepository->findCategoriesWithCount($domainId);
    }

    public function getByCategorySlugs(int $domainId, array $slugs): array
    {
        $rows = $this->faqRepository->findByCategorySlugs($domainId, $slugs);

        $grouped = [];
        foreach ($rows as $row) {
            $slug = $row['category_slug'];
            if (!isset($grouped[$slug])) {
                $grouped[$slug] = [];
            }
            $grouped[$slug][] = [
                'faq_id' => $row['faq_id'],
                'question' => $row['question'],
                'answer' => $row['answer'],
            ];
        }

        return $grouped;
    }

    public function getGroupedAll(int $domainId): array
    {
        $rows = $this->faqRepository->findGroupedAll($domainId);

        $grouped = [];
        $categoryMap = [];

        foreach ($rows as $row) {
            $catId = $row['category_id'];
            if (!isset($categoryMap[$catId])) {
                $categoryMap[$catId] = count($grouped);
                $grouped[] = [
                    'category_id' => $catId,
                    'category_name' => $row['category_name'],
                    'category_slug' => $row['category_slug'],
                    'items' => [],
                ];
            }

            $idx = $categoryMap[$catId];
            $grouped[$idx]['items'][] = [
                'faq_id' => $row['faq_id'],
                'question' => $row['question'],
                'answer' => $row['answer'],
            ];
        }

        return $grouped;
    }

    public function getGroupedPaginated(int $domainId, int $page, int $perPage): array
    {
        $totalItems = $this->faqRepository->countActiveItems($domainId);
        $totalPages  = $totalItems > 0 ? (int) ceil($totalItems / $perPage) : 1;
        $page        = max(1, min($page, $totalPages));
        $offset      = ($page - 1) * $perPage;

        $rows = $this->faqRepository->findActiveItemsPaginated($domainId, $offset, $perPage);

        $groups = [];
        foreach ($rows as $row) {
            $slug = $row['category_slug'];
            if (!isset($groups[$slug])) {
                $groups[$slug] = [
                    'category_name' => $row['category_name'],
                    'category_slug' => $slug,
                    'items' => [],
                ];
            }
            $groups[$slug]['items'][] = [
                'faq_id'   => $row['faq_id'],
                'question' => $row['question'],
                'answer'   => $row['answer'],
            ];
        }

        return [
            'groups'      => array_values($groups),
            'totalItems'  => $totalItems,
            'perPage'     => $perPage,
            'currentPage' => $page,
            'totalPages'  => $totalPages,
        ];
    }

    // ─────────────────────────────────────────
    // 카테고리 CRUD (Admin)
    // ─────────────────────────────────────────

    /**
     * 카테고리 목록 (관리자용, 비활성 포함)
     */
    public function getCategoryList(int $domainId): Result
    {
        $categories = $this->faqRepository->findCategories($domainId);

        return Result::success('', ['categories' => $categories]);
    }

    /**
     * 유니크 슬러그 자동 생성 (8자 랜덤)
     */
    private function generateUniqueSlug(int $domainId): string
    {
        do {
            $slug = bin2hex(random_bytes(4)); // 8자 hex
        } while ($this->faqRepository->existsSlug($domainId, $slug));

        return $slug;
    }

    /**
     * 카테고리 생성
     */
    public function createCategory(int $domainId, array $data): Result
    {
        $name = trim($data['category_name'] ?? '');

        if ($name === '') {
            return Result::failure('카테고리명을 입력해 주세요.');
        }

        $slug = $this->generateUniqueSlug($domainId);

        $categoryId = $this->faqRepository->insertCategory([
            'domain_id' => $domainId,
            'category_name' => $name,
            'category_slug' => $slug,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
        ]);

        if (!$categoryId) {
            return Result::failure('카테고리 생성에 실패했습니다.');
        }

        $this->dispatchChanged($domainId);

        return Result::success('카테고리가 생성되었습니다.', ['category_id' => $categoryId]);
    }

    /**
     * 카테고리 수정
     */
    public function updateCategory(int $domainId, int $categoryId, array $data): Result
    {
        $category = $this->faqRepository->findCategory($categoryId, $domainId);
        if (!$category) {
            return Result::failure('카테고리를 찾을 수 없습니다.');
        }

        $name = trim($data['category_name'] ?? '');

        if ($name === '') {
            return Result::failure('카테고리명을 입력해 주세요.');
        }

        $this->faqRepository->updateCategory($categoryId, $domainId, [
            'category_name' => $name,
            'sort_order' => (int) ($data['sort_order'] ?? $category['sort_order']),
            'is_active' => (int) ($data['is_active'] ?? $category['is_active']),
        ]);

        $this->dispatchChanged($domainId);

        return Result::success('카테고리가 수정되었습니다.');
    }

    /**
     * 카테고리 삭제 (하위 FAQ 항목도 CASCADE 삭제)
     */
    public function deleteCategory(int $domainId, int $categoryId): Result
    {
        $category = $this->faqRepository->findCategory($categoryId, $domainId);
        if (!$category) {
            return Result::failure('카테고리를 찾을 수 없습니다.');
        }

        // 하위 항목은 FK CASCADE 로 함께 지워진다 — 확장이 정리하도록 지우기 전에 번호를 모은다
        $faqIds = array_map(
            static fn(array $item): int => (int) $item['faq_id'],
            $this->faqRepository->findItems($domainId, $categoryId)
        );

        $this->faqRepository->deleteCategory($categoryId, $domainId);

        if ($faqIds !== []) {
            $this->eventDispatcher?->dispatch(new FaqItemDeletedEvent($domainId, $faqIds));
        }
        $this->dispatchChanged($domainId);

        return Result::success('카테고리가 삭제되었습니다.');
    }

    // ─────────────────────────────────────────
    // FAQ 항목 CRUD (Admin)
    // ─────────────────────────────────────────

    /**
     * 카테고리별 FAQ 항목 목록 (관리자용)
     */
    public function getItemsByCategory(int $domainId, ?int $categoryId = null): Result
    {
        $items = $this->faqRepository->findItems($domainId, $categoryId);

        return Result::success('', ['items' => $this->withAdminExtras($domainId, $items)]);
    }

    /**
     * FAQ 항목 단건 조회
     */
    public function getItem(int $faqId): Result
    {
        $item = $this->faqRepository->findItem($faqId);

        if (!$item) {
            return Result::failure('FAQ 항목을 찾을 수 없습니다.');
        }

        return Result::success('', ['item' => $item]);
    }

    /**
     * FAQ 항목 생성
     */
    public function createItem(int $domainId, array $data): Result
    {
        $categoryId = (int) ($data['category_id'] ?? 0);
        $question = trim($data['question'] ?? '');
        $answer = trim($data['answer'] ?? '');

        if ($categoryId <= 0) {
            return Result::failure('카테고리를 선택해 주세요.');
        }

        $category = $this->faqRepository->findCategory($categoryId, $domainId);
        if (!$category) {
            return Result::failure('카테고리를 찾을 수 없습니다.');
        }

        if ($question === '') {
            return Result::failure('질문을 입력해 주세요.');
        }

        if ($answer === '') {
            return Result::failure('답변을 입력해 주세요.');
        }

        $faqId = $this->faqRepository->insertItem([
            'domain_id' => $domainId,
            'category_id' => $categoryId,
            'question' => $question,
            'answer' => $answer,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
        ]);

        if (!$faqId) {
            return Result::failure('FAQ 항목 생성에 실패했습니다.');
        }

        // 에디터 임시 이미지 → 최종 경로 이동
        $processedAnswer = EditorHelper::processImages($answer, 'faq/' . $faqId);
        if ($processedAnswer !== $answer) {
            $this->faqRepository->updateItem($faqId, $domainId, ['answer' => $processedAnswer]);
        }

        $this->eventDispatcher?->dispatch(new FaqItemSavedEvent($domainId, (int) $faqId, $data, false));
        $this->dispatchChanged($domainId);

        return Result::success('FAQ 항목이 등록되었습니다.', ['faq_id' => $faqId]);
    }

    /**
     * FAQ 항목 수정
     */
    public function updateItem(int $domainId, int $faqId, array $data): Result
    {
        $item = $this->faqRepository->findItem($faqId, $domainId);
        if (!$item) {
            return Result::failure('FAQ 항목을 찾을 수 없습니다.');
        }

        $question = trim($data['question'] ?? '');
        $answer = trim($data['answer'] ?? '');

        if ($question === '') {
            return Result::failure('질문을 입력해 주세요.');
        }

        if ($answer === '') {
            return Result::failure('답변을 입력해 주세요.');
        }

        $updateData = [
            'question' => $question,
            'answer' => $answer,
            'sort_order' => (int) ($data['sort_order'] ?? $item['sort_order']),
            'is_active' => (int) ($data['is_active'] ?? $item['is_active']),
        ];

        if (isset($data['category_id'])) {
            $categoryId = (int) $data['category_id'];
            $category = $this->faqRepository->findCategory($categoryId, $domainId);
            if (!$category) {
                return Result::failure('카테고리를 찾을 수 없습니다.');
            }
            $updateData['category_id'] = $categoryId;
        }

        // 에디터 임시 이미지 → 최종 경로 이동
        $processedAnswer = EditorHelper::processImages($updateData['answer'], 'faq/' . $faqId);
        if ($processedAnswer !== $updateData['answer']) {
            $updateData['answer'] = $processedAnswer;
        }

        $this->faqRepository->updateItem($faqId, $domainId, $updateData);

        $this->eventDispatcher?->dispatch(new FaqItemSavedEvent($domainId, $faqId, $data, true));
        $this->dispatchChanged($domainId);

        return Result::success('FAQ 항목이 수정되었습니다.');
    }

    /**
     * FAQ 항목 삭제
     */
    public function deleteItem(int $domainId, int $faqId): Result
    {
        $item = $this->faqRepository->findItem($faqId, $domainId);
        if (!$item) {
            return Result::failure('FAQ 항목을 찾을 수 없습니다.');
        }

        $this->faqRepository->deleteItem($faqId, $domainId);

        $this->eventDispatcher?->dispatch(new FaqItemDeletedEvent($domainId, [$faqId]));
        $this->dispatchChanged($domainId);

        return Result::success('FAQ 항목이 삭제되었습니다.');
    }

    /**
     * 정렬 순서 일괄 변경
     *
     * @param array $items [['faq_id' => int, 'sort_order' => int], ...]
     */
    public function updateSortOrder(int $domainId, array $items): Result
    {
        foreach ($items as $item) {
            $faqId = (int) ($item['faq_id'] ?? 0);
            $order = (int) ($item['sort_order'] ?? 0);

            if ($faqId > 0) {
                $this->faqRepository->updateItemSortOrder($faqId, $domainId, $order);
            }
        }

        $this->dispatchChanged($domainId);

        return Result::success('정렬 순서가 변경되었습니다.');
    }

    private function dispatchChanged(int $domainId): void
    {
        $this->eventDispatcher?->dispatch(new FaqContentChangedEvent($domainId));
    }
}
