<?php
declare(strict_types=1);
namespace Mublo\Plugin\Manual\Service;

use Mublo\Contract\Manual\ManualBook;
use Mublo\Contract\Manual\ManualPageDetail;
use Mublo\Contract\Manual\ManualPageNode;
use Mublo\Contract\Manual\ManualQueryInterface;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Result\Result;
use Mublo\Helper\Editor\EditorHelper;
use Mublo\Plugin\Manual\Dto\ManualRecentPage;
use Mublo\Plugin\Manual\Event\ManualContentChangedEvent;
use Mublo\Plugin\Manual\Repository\ManualRepository;

/**
 * ManualService
 *
 * 매뉴얼 비즈니스 로직 + ManualQueryInterface 구현.
 * Controller는 항상 이 Service를 거치며, 결과는 Result로 반환한다.
 */
class ManualService implements ManualQueryInterface
{
    public const SKIN_TUTORIAL_SLUG = 'skin-development';
    public const BOARD_MANUAL_SLUG = 'board-manual';
    public const SHOP_MANUAL_SLUG = 'shop-manual';

    /**
     * Manual 플러그인에 동봉되는 편집 가능한 번들 매뉴얼.
     *
     * slug는 도메인 안에서 가져오기 여부를 판별하는 안정 식별자다. 같은 slug의 최신
     * 번들이 있으면 다시 가져오지 않고, 구버전 기본 번들만 버전 표식에 따라 갱신한다.
     *
     * @var array<string, array{slug:string,file:string,label:string}>
     */
    private const BUNDLED_MANUALS = [
        'skin-development' => [
            'slug' => self::SKIN_TUTORIAL_SLUG,
            'file' => 'skin-development.php',
            'label' => '스킨 제작 가이드',
        ],
        'board' => [
            'slug' => self::BOARD_MANUAL_SLUG,
            'file' => 'board-manual.php',
            'label' => '게시판 매뉴얼',
        ],
        'shop' => [
            'slug' => self::SHOP_MANUAL_SLUG,
            'file' => 'shop-manual.php',
            'label' => 'Mublo Shop 매뉴얼',
        ],
    ];

    private ManualRepository $repository;
    private string $storagePath;
    private ?EventDispatcher $eventDispatcher;

    public function __construct(
        ManualRepository $repository,
        ?string $storagePath = null,
        ?EventDispatcher $eventDispatcher = null,
    )
    {
        $this->repository = $repository;
        $this->eventDispatcher = $eventDispatcher;
        $this->storagePath = rtrim(
            $storagePath ?? (defined('MUBLO_PUBLIC_STORAGE_PATH') ? MUBLO_PUBLIC_STORAGE_PATH : 'public/storage'),
            '/\\'
        );
    }

    // ─────────────────────────────────────────
    // Contract 구현 (ManualQueryInterface)
    // ─────────────────────────────────────────

    public function getActiveBooks(int $domainId): array
    {
        return array_map(
            fn (array $row): ManualBook => $this->mapBook($row),
            $this->repository->findBooks($domainId, true)
        );
    }

    public function getBookBySlug(int $domainId, string $slug): ?ManualBook
    {
        $row = $this->repository->findBookBySlug($domainId, $slug, true);
        return $row !== null ? $this->mapBook($row) : null;
    }

    public function hasSkinDevelopmentTutorial(int $domainId): bool
    {
        return $this->hasBundledManual($domainId, 'skin-development');
    }

    public function hasBoardManual(int $domainId): bool
    {
        return $this->hasBundledManual($domainId, 'board');
    }

    public function hasShopManual(int $domainId): bool
    {
        return $this->hasBundledManual($domainId, 'shop');
    }

    public function hasBundledManual(int $domainId, string $bundleKey): bool
    {
        $bundle = self::BUNDLED_MANUALS[$bundleKey] ?? null;
        return $bundle !== null
            && $this->repository->findBookBySlug($domainId, $bundle['slug']) !== null;
    }

    /**
     * 번들 스킨 제작 가이드를 현재 도메인의 편집 가능한 일반 매뉴얼로 복사한다.
     *
     * 게시판·쇼핑몰 매뉴얼과 달리 이 책은 운영자가 직접 선택해 가져오는 것이므로
     * ensureDefaultManuals 의 자동 경로에 넣지 않는다. 대신 가져오기를 다시 실행하면
     * 버전이 오른 개정본을 반영한다 — 그렇지 않으면 이미 가져간 도메인이 번들 수정을
     * 영원히 받지 못한다.
     */
    public function importSkinDevelopmentTutorial(int $domainId): Result
    {
        $result = $this->importBundledManual($domainId, 'skin-development');

        return $this->refreshOutdatedBundle($domainId, 'skin-development', $result);
    }

    public function importBoardManual(int $domainId): Result
    {
        return $this->importBundledManual($domainId, 'board');
    }

    public function importShopManual(int $domainId): Result
    {
        return $this->importBundledManual($domainId, 'shop');
    }

    /**
     * 기본 포함 패키지의 매뉴얼을 현재 도메인에 보장한다.
     *
     * 기존 책은 slug로 감지한다. 신규 설치뿐 아니라 이미 Manual을 사용 중인 도메인도
     * 다음 매뉴얼 진입 시 새 기본 책 또는 버전이 오른 기본 번들을 받을 수 있게 동작한다.
     *
     * @return array{board:Result,shop:Result}
     */
    public function ensureDefaultManuals(int $domainId): array
    {
        $results = [
            'board' => $this->importBoardManual($domainId),
            'shop' => $this->importShopManual($domainId),
        ];

        foreach ($results as $bundleKey => $result) {
            $results[$bundleKey] = $this->refreshOutdatedBundle($domainId, $bundleKey, $result);
        }

        return $results;
    }

    /**
     * 이미 생성된 기본 매뉴얼에 번들 개정본을 한 번 반영한다.
     *
     * 첫 페이지의 버전 표식이 현재 버전과 같으면 아무것도 수정하지 않는다. 버전이
     * 오래된 경우 번들에 포함된 페이지만 갱신하며, 관리자가 추가한 별도 페이지는 보존한다.
     */
    private function refreshOutdatedBundle(int $domainId, string $bundleKey, Result $result): Result
    {
        if (!$result->isSuccess() || !$result->get('already_exists')) {
            return $result;
        }

        $bundle = self::BUNDLED_MANUALS[$bundleKey] ?? null;
        $bookId = (int) $result->get('book_id', 0);
        if ($bundle === null || $bookId <= 0) {
            return $result;
        }

        $templatePath = dirname(__DIR__) . '/resources/manuals/' . $bundle['file'];
        $template = is_file($templatePath) ? require $templatePath : null;
        if (!is_array($template) || !is_array($template['pages'] ?? null)) {
            return $result;
        }

        $version = (int) ($template['version'] ?? 0);
        $firstPage = $template['pages'][0] ?? null;
        if ($version <= 0 || !is_array($firstPage)) {
            return $result;
        }

        $marker = '<!-- mublo-bundle:' . $bundleKey . ':v' . $version . ' -->';
        $currentFirstPage = $this->repository->findPageBySlug($bookId, (string) ($firstPage['slug'] ?? ''));
        if ($currentFirstPage !== null
            && str_contains((string) ($currentFirstPage['content'] ?? ''), $marker)
        ) {
            return $result;
        }

        try {
            $this->repository->transaction(function () use ($bookId, $template): void {
                $existingBySlug = [];
                foreach ($this->repository->findPages($bookId) as $page) {
                    $existingBySlug[(string) ($page['slug'] ?? '')] = $page;
                }

                $pageIds = [];
                $pageDepths = [];
                foreach ($template['pages'] as $page) {
                    if (!is_array($page)) {
                        throw new \RuntimeException('잘못된 번들 페이지');
                    }

                    $key = (string) ($page['key'] ?? '');
                    $slug = (string) ($page['slug'] ?? '');
                    $parentKey = (string) ($page['parent'] ?? '');
                    $parentId = $parentKey === '' ? null : ($pageIds[$parentKey] ?? null);
                    if ($key === '' || $slug === '' || ($parentKey !== '' && $parentId === null)) {
                        throw new \RuntimeException('번들 페이지 계층이 올바르지 않습니다.');
                    }

                    $depth = $parentKey === '' ? 0 : (($pageDepths[$parentKey] ?? 0) + 1);
                    $pageData = [
                        'parent_id' => $parentId,
                        'title' => (string) ($page['title'] ?? ''),
                        'slug' => $slug,
                        'content' => (string) ($page['content'] ?? ''),
                        'sort_order' => (int) ($page['sort_order'] ?? 0),
                        'is_active' => 1,
                        'depth' => $depth,
                    ];

                    $existing = $existingBySlug[$slug] ?? null;
                    if (is_array($existing)) {
                        $pageId = (int) ($existing['page_id'] ?? 0);
                        $this->repository->updatePage($pageId, $bookId, $pageData);
                    } else {
                        $pageData['book_id'] = $bookId;
                        $pageId = (int) ($this->repository->insertPage($pageData) ?? 0);
                    }

                    if ($pageId <= 0) {
                        throw new \RuntimeException('번들 페이지 갱신 실패');
                    }
                    $pageIds[$key] = $pageId;
                    $pageDepths[$key] = $depth;
                }
            });
        } catch (\Throwable) {
            return $result;
        }

        $this->notifyChanged($domainId, 'bundle_refreshed');
        return Result::success($bundle['label'] . ' 최신 개정본을 반영했습니다.', [
            'book_id' => $bookId,
            'already_exists' => true,
            'refreshed' => true,
        ]);
    }

    /**
     * 번들 매뉴얼을 현재 도메인의 일반 책/페이지로 복사한다.
     */
    private function importBundledManual(int $domainId, string $bundleKey): Result
    {
        $bundle = self::BUNDLED_MANUALS[$bundleKey] ?? null;
        if ($bundle === null) {
            return Result::failure('지원하지 않는 번들 매뉴얼입니다.');
        }

        $slug = $bundle['slug'];
        $label = $bundle['label'];
        $existing = $this->repository->findBookBySlug($domainId, $slug);
        if ($existing !== null) {
            return Result::success($label . ': 이미 추가되어 있습니다.', [
                'book_id' => (int) $existing['book_id'],
                'already_exists' => true,
            ]);
        }

        $templatePath = dirname(__DIR__) . '/resources/manuals/' . $bundle['file'];
        $template = is_file($templatePath) ? require $templatePath : null;
        if (!is_array($template) || !is_array($template['book'] ?? null)
            || !is_array($template['pages'] ?? null)) {
            return Result::failure($label . ' 번들 파일을 읽을 수 없습니다.');
        }

        $book = $template['book'];
        $pages = $template['pages'];

        try {
            $bookId = $this->repository->transaction(function () use ($domainId, $book, $pages, $slug, $label): int {
                $bookId = $this->repository->insertBook([
                    'domain_id' => $domainId,
                    'title' => (string) ($book['title'] ?? $label),
                    'slug' => $slug,
                    'description' => (string) ($book['description'] ?? ''),
                    'sort_order' => (int) ($book['sort_order'] ?? 0),
                    'is_active' => (int) ($book['is_active'] ?? 1),
                ]);
                if (!$bookId) {
                    throw new \RuntimeException('번들 매뉴얼 생성 실패');
                }

                $pageIds = [];
                $pageDepths = [];
                foreach ($pages as $page) {
                    if (!is_array($page)) {
                        throw new \RuntimeException('잘못된 번들 페이지');
                    }

                    $key = (string) ($page['key'] ?? '');
                    $parentKey = (string) ($page['parent'] ?? '');
                    $parentId = $parentKey === '' ? null : ($pageIds[$parentKey] ?? null);
                    if ($parentKey !== '' && $parentId === null) {
                        throw new \RuntimeException('번들 페이지 부모가 먼저 정의되지 않았습니다.');
                    }
                    $depth = $parentKey === '' ? 0 : (($pageDepths[$parentKey] ?? 0) + 1);

                    $pageId = $this->repository->insertPage([
                        'book_id' => $bookId,
                        'parent_id' => $parentId,
                        'title' => (string) ($page['title'] ?? ''),
                        'slug' => (string) ($page['slug'] ?? ''),
                        'content' => (string) ($page['content'] ?? ''),
                        'sort_order' => (int) ($page['sort_order'] ?? 0),
                        'is_active' => 1,
                        'depth' => $depth,
                    ]);
                    if (!$pageId || $key === '') {
                        throw new \RuntimeException('번들 페이지 생성 실패');
                    }
                    $pageIds[$key] = $pageId;
                    $pageDepths[$key] = $depth;
                }

                return $bookId;
            });
        } catch (\Throwable) {
            return Result::failure($label . ' 추가에 실패했습니다.');
        }

        $this->notifyChanged($domainId, 'bundle_imported');
        return Result::success($label . ' 추가가 완료되었습니다.', [
            'book_id' => $bookId,
            'already_exists' => false,
        ]);
    }

    public function getPageTree(int $bookId): array
    {
        $pages = $this->repository->findPages($bookId, true);
        return $this->mapTreeNodes($this->buildTree($pages));
    }

    public function getPageBySlug(int $bookId, string $slug): ?ManualPageDetail
    {
        $row = $this->repository->findPageBySlug($bookId, $slug, true);
        if ($row === null) {
            return null;
        }

        return new ManualPageDetail(
            pageId: (int) $row['page_id'],
            bookId: (int) $row['book_id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            content: isset($row['content']) ? (string) $row['content'] : null,
        );
    }

    /**
     * 최근 수정 활성 페이지 목록.
     *
     * @param list<string> $bookSlugs 비어 있으면 모든 활성 책
     * @return list<ManualRecentPage>
     */
    public function getRecentPages(int $domainId, array $bookSlugs = [], int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $bookSlugs = array_values(array_unique(array_filter(
            array_map(static fn (mixed $slug): string => trim((string) $slug), $bookSlugs),
            static fn (string $slug): bool => preg_match('/^[a-z0-9-]+$/', $slug) === 1
        )));

        return array_map(
            static fn (array $row): ManualRecentPage => new ManualRecentPage(
                pageId: (int) $row['page_id'],
                pageTitle: (string) $row['page_title'],
                pageSlug: (string) $row['page_slug'],
                bookTitle: (string) $row['book_title'],
                bookSlug: (string) $row['book_slug'],
                content: isset($row['content']) ? (string) $row['content'] : null,
                updatedAt: (string) $row['updated_at'],
            ),
            $this->repository->findRecentPages($domainId, $bookSlugs, $limit)
        );
    }

    /**
     * 책 행(row) → ManualBook DTO
     *
     * @param array<string, mixed> $row
     */
    private function mapBook(array $row): ManualBook
    {
        return new ManualBook(
            bookId: (int) $row['book_id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            description: isset($row['description']) && $row['description'] !== ''
                ? (string) $row['description']
                : null,
            sortOrder: (int) ($row['sort_order'] ?? 0),
        );
    }

    /**
     * buildTree() 의 배열 노드 → ManualPageNode DTO (재귀)
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return list<ManualPageNode>
     */
    private function mapTreeNodes(array $nodes): array
    {
        $mapped = [];
        foreach ($nodes as $node) {
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $mapped[] = new ManualPageNode(
                pageId: (int) $node['page_id'],
                parentId: isset($node['parent_id']) && $node['parent_id'] !== null
                    ? (int) $node['parent_id']
                    : null,
                title: (string) $node['title'],
                slug: (string) $node['slug'],
                depth: (int) ($node['depth'] ?? 0),
                sortOrder: (int) ($node['sort_order'] ?? 0),
                content: isset($node['content']) ? (string) $node['content'] : null,
                children: $this->mapTreeNodes($children),
            );
        }
        return $mapped;
    }

    /**
     * 플랫 페이지 배열 → 중첩 트리 (parent_id 기준, 깊이 무제한)
     *
     * @param array $pages sort_order 정렬된 플랫 목록
     * @return array 루트 노드 배열 (각 노드에 children 포함)
     */
    private function buildTree(array $pages): array
    {
        $byParent = [];
        foreach ($pages as $page) {
            $parent = $page['parent_id'] !== null ? (int) $page['parent_id'] : 0;
            $byParent[$parent][] = $page;
        }

        $build = function (int $parentId) use (&$build, $byParent): array {
            $nodes = [];
            foreach ($byParent[$parentId] ?? [] as $page) {
                $page['children'] = $build((int) $page['page_id']);
                $nodes[] = $page;
            }
            return $nodes;
        };

        return $build(0);
    }

    // ─────────────────────────────────────────
    // 책 CRUD (Admin)
    // ─────────────────────────────────────────

    /**
     * 책 목록 (관리자용, 페이지 수 포함, 비활성 포함)
     */
    public function getBookList(int $domainId): Result
    {
        $books = $this->repository->findBooksWithCount($domainId);
        return Result::success('', ['books' => $books]);
    }

    /**
     * 책 단건 조회 (관리자용)
     */
    public function getBook(int $domainId, int $bookId): Result
    {
        $book = $this->repository->findBook($bookId, $domainId);
        if (!$book) {
            return Result::failure('매뉴얼을 찾을 수 없습니다.');
        }
        return Result::success('', ['book' => $book]);
    }

    public function createBook(int $domainId, array $data): Result
    {
        $title = trim($data['title'] ?? '');
        if ($title === '') {
            return Result::failure('매뉴얼 제목을 입력해 주세요.');
        }

        $slug = $this->resolveBookSlug($domainId, $data['slug'] ?? '', null);

        $bookId = $this->repository->insertBook([
            'domain_id'   => $domainId,
            'title'       => $title,
            'slug'        => $slug,
            'description' => trim($data['description'] ?? '') ?: null,
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
            'is_active'   => (int) ($data['is_active'] ?? 1),
        ]);

        if (!$bookId) {
            return Result::failure('매뉴얼 생성에 실패했습니다.');
        }

        $this->notifyChanged($domainId, 'book_created');
        return Result::success('매뉴얼이 생성되었습니다.', ['book_id' => $bookId]);
    }

    public function updateBook(int $domainId, int $bookId, array $data): Result
    {
        $book = $this->repository->findBook($bookId, $domainId);
        if (!$book) {
            return Result::failure('매뉴얼을 찾을 수 없습니다.');
        }

        $title = trim($data['title'] ?? '');
        if ($title === '') {
            return Result::failure('매뉴얼 제목을 입력해 주세요.');
        }

        $slug = $this->resolveBookSlug($domainId, $data['slug'] ?? '', $bookId);

        $this->repository->updateBook($bookId, $domainId, [
            'title'       => $title,
            'slug'        => $slug,
            'description' => trim($data['description'] ?? '') ?: null,
            'sort_order'  => (int) ($data['sort_order'] ?? $book['sort_order']),
            'is_active'   => (int) ($data['is_active'] ?? $book['is_active']),
        ]);

        $this->notifyChanged($domainId, 'book_updated');
        return Result::success('매뉴얼이 수정되었습니다.');
    }

    /** 관리자 목록에서 프론트 노출 상태만 빠르게 변경한다. */
    public function setBookActive(int $domainId, int $bookId, bool $active): Result
    {
        $book = $this->repository->findBook($bookId, $domainId);
        if (!$book) {
            return Result::failure('매뉴얼을 찾을 수 없습니다.');
        }

        $this->repository->updateBook($bookId, $domainId, [
            'is_active' => $active ? 1 : 0,
        ]);

        $this->notifyChanged($domainId, 'book_visibility_changed');
        return Result::success(
            $active ? '프론트에 매뉴얼을 노출합니다.' : '프론트에서 매뉴얼을 숨겼습니다.',
            ['book_id' => $bookId, 'is_active' => $active ? 1 : 0]
        );
    }

    public function deleteBook(int $domainId, int $bookId): Result
    {
        $book = $this->repository->findBook($bookId, $domainId);
        if (!$book) {
            return Result::failure('매뉴얼을 찾을 수 없습니다.');
        }

        $pageIds = array_map(
            static fn (array $page): int => (int) $page['page_id'],
            $this->repository->findPages($bookId, false)
        );

        try {
            // 페이지는 FK ON DELETE CASCADE 로 함께 삭제된다.
            $this->repository->transaction(
                fn () => $this->repository->deleteBook($bookId, $domainId)
            );
        } catch (\Throwable) {
            return Result::failure('매뉴얼 삭제에 실패했습니다.');
        }

        foreach ($pageIds as $pageId) {
            $this->deletePageFiles($domainId, $pageId);
        }

        $this->notifyChanged($domainId, 'book_deleted');
        return Result::success('매뉴얼이 삭제되었습니다.');
    }

    /**
     * 책 슬러그 결정 — 입력값이 있으면 정규화 후 중복 검사, 없으면 랜덤 생성.
     */
    private function resolveBookSlug(int $domainId, string $raw, ?int $excludeId): string
    {
        $slug = $this->slugify($raw);

        if ($slug === '') {
            do {
                $slug = bin2hex(random_bytes(4));
            } while ($this->repository->existsBookSlug($domainId, $slug, $excludeId));
            return $slug;
        }

        // 중복 시 접미사 부여
        $base = $slug;
        $i = 2;
        while ($this->repository->existsBookSlug($domainId, $slug, $excludeId)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    // ─────────────────────────────────────────
    // 페이지 CRUD (Admin)
    // ─────────────────────────────────────────

    /**
     * 책의 페이지 트리 (관리자용, 비활성 포함)
     */
    public function getPageTreeAdmin(int $domainId, int $bookId): Result
    {
        $book = $this->repository->findBook($bookId, $domainId);
        if (!$book) {
            return Result::failure('매뉴얼을 찾을 수 없습니다.');
        }

        $pages = $this->repository->findPages($bookId, false);
        return Result::success('', [
            'book' => $book,
            'tree' => $this->buildTree($pages),
        ]);
    }

    /**
     * 페이지 단건 조회 (관리자용, 도메인 경계 보장)
     */
    public function getPage(int $domainId, int $pageId): Result
    {
        $page = $this->repository->findPage($pageId);
        if (!$page) {
            return Result::failure('페이지를 찾을 수 없습니다.');
        }

        $book = $this->repository->findBook((int) $page['book_id'], $domainId);
        if (!$book) {
            return Result::failure('페이지를 찾을 수 없습니다.');
        }

        return Result::success('', ['page' => $page, 'book' => $book]);
    }

    public function createPage(int $domainId, array $data): Result
    {
        $bookId = (int) ($data['book_id'] ?? 0);
        $book = $this->repository->findBook($bookId, $domainId);
        if (!$book) {
            return Result::failure('매뉴얼을 찾을 수 없습니다.');
        }

        $title = trim($data['title'] ?? '');
        if ($title === '') {
            return Result::failure('페이지 제목을 입력해 주세요.');
        }

        $parentResult = $this->validateRequestedParent($bookId, $data['parent_id'] ?? null);
        if (!$parentResult['valid']) {
            return Result::failure('상위 페이지가 올바르지 않습니다.');
        }
        $parentId = $parentResult['parent_id'];
        $depth = $this->depthOf($bookId, $parentId);
        if ($depth > 255) {
            return Result::failure('페이지 계층은 255단계를 초과할 수 없습니다.');
        }
        $slug = $this->resolvePageSlug($bookId, $data['slug'] ?? '', null);

        $moved = [];
        try {
            $pageId = $this->repository->transaction(function () use (
                $bookId,
                $parentId,
                $title,
                $slug,
                $data,
                $depth,
                &$moved
            ): int {
                $content = (string) ($data['content'] ?? '');
                $pageId = $this->repository->insertPage([
                    'book_id'    => $bookId,
                    'parent_id'  => $parentId,
                    'title'      => $title,
                    'slug'       => $slug,
                    'content'    => $content,
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                    'is_active'  => (int) ($data['is_active'] ?? 1),
                    'depth'      => $depth,
                ]);
                if (!$pageId) {
                    throw new \RuntimeException('페이지 생성 실패');
                }

                $processed = EditorHelper::processImages($content, 'manual/' . $pageId, moved: $moved);
                if ($processed !== $content) {
                    $this->repository->updatePage($pageId, $bookId, ['content' => $processed]);
                }

                return $pageId;
            });
        } catch (\Throwable) {
            EditorHelper::rollbackImages($moved);
            return Result::failure('페이지 생성에 실패했습니다.');
        }

        $this->notifyChanged($domainId, 'page_created');
        return Result::success('페이지가 등록되었습니다.', ['page_id' => $pageId, 'book_id' => $bookId]);
    }

    public function updatePage(int $domainId, int $pageId, array $data): Result
    {
        $page = $this->repository->findPage($pageId);
        if (!$page) {
            return Result::failure('페이지를 찾을 수 없습니다.');
        }

        $bookId = (int) $page['book_id'];
        $book = $this->repository->findBook($bookId, $domainId);
        if (!$book) {
            return Result::failure('페이지를 찾을 수 없습니다.');
        }

        $title = trim($data['title'] ?? '');
        if ($title === '') {
            return Result::failure('페이지 제목을 입력해 주세요.');
        }

        $pages = $this->repository->findPages($bookId, false);
        $parentMap = [];
        $currentDepths = [];
        foreach ($pages as $bookPage) {
            $id = (int) $bookPage['page_id'];
            $parentMap[$id] = $bookPage['parent_id'] !== null ? (int) $bookPage['parent_id'] : null;
            $currentDepths[$id] = (int) ($bookPage['depth'] ?? 0);
        }

        $requestedParent = array_key_exists('parent_id', $data)
            ? $data['parent_id']
            : ($page['parent_id'] ?? null);
        $parentResult = $this->validateRequestedParent($bookId, $requestedParent);
        if (!$parentResult['valid']) {
            return Result::failure('상위 페이지가 올바르지 않습니다.');
        }
        $parentMap[$pageId] = $parentResult['parent_id'];
        $depths = $this->calculateDepths($parentMap);
        if ($depths === null) {
            return Result::failure('페이지 계층에 순환이 있거나 허용 깊이를 초과합니다.');
        }

        $slug = $this->resolvePageSlug($bookId, $data['slug'] ?? '', $pageId);
        $content = (string) ($data['content'] ?? '');
        $moved = [];
        $processed = EditorHelper::processImages($content, 'manual/' . $pageId, moved: $moved);

        try {
            $this->repository->transaction(function () use (
                $pageId,
                $bookId,
                $title,
                $slug,
                $processed,
                $data,
                $page,
                $parentResult,
                $depths,
                $currentDepths
            ): void {
                $this->repository->updatePage($pageId, $bookId, [
                    'parent_id'  => $parentResult['parent_id'],
                    'depth'      => $depths[$pageId],
                    'title'      => $title,
                    'slug'       => $slug,
                    'content'    => $processed,
                    'sort_order' => (int) ($data['sort_order'] ?? $page['sort_order']),
                    'is_active'  => (int) ($data['is_active'] ?? $page['is_active']),
                ]);

                foreach ($depths as $id => $depth) {
                    if ($id !== $pageId && ($currentDepths[$id] ?? null) !== $depth) {
                        $this->repository->updatePageDepth($id, $bookId, $depth);
                    }
                }
            });
        } catch (\Throwable) {
            EditorHelper::rollbackImages($moved);
            return Result::failure('페이지 수정에 실패했습니다.');
        }

        $this->deleteUnreferencedPageFiles($domainId, $pageId, $processed);

        $this->notifyChanged($domainId, 'page_updated');
        return Result::success('페이지가 수정되었습니다.');
    }

    public function deletePage(int $domainId, int $pageId): Result
    {
        $page = $this->repository->findPage($pageId);
        if (!$page) {
            return Result::failure('페이지를 찾을 수 없습니다.');
        }

        $bookId = (int) $page['book_id'];
        $book = $this->repository->findBook($bookId, $domainId);
        if (!$book) {
            return Result::failure('페이지를 찾을 수 없습니다.');
        }

        $pageIds = $this->collectSubtreeIds($this->repository->findPages($bookId, false), $pageId);
        try {
            $this->repository->transaction(function () use ($pageIds, $bookId): void {
                foreach (array_reverse($pageIds) as $id) {
                    $this->repository->deletePage($id, $bookId);
                }
            });
        } catch (\Throwable) {
            return Result::failure('페이지 삭제에 실패했습니다.');
        }

        foreach ($pageIds as $id) {
            $this->deletePageFiles($domainId, $id);
        }

        $this->notifyChanged($domainId, 'page_deleted');
        return Result::success('페이지가 삭제되었습니다.');
    }

    /**
     * 트리 순서/계층 저장 (드래그 재구성)
     *
     * @param array $nodes [{page_id, parent_id|null, sort_order}, ...] (프론트에서 평탄화 전달)
     */
    public function saveTree(int $domainId, int $bookId, array $nodes): Result
    {
        $book = $this->repository->findBook($bookId, $domainId);
        if (!$book) {
            return Result::failure('매뉴얼을 찾을 수 없습니다.');
        }

        $pages = $this->repository->findPages($bookId, false);
        $owned = array_fill_keys(array_map(static fn (array $p): int => (int) $p['page_id'], $pages), true);
        $parentMap = [];
        $sortOrders = [];
        foreach ($nodes as $node) {
            $pid = (int) ($node['page_id'] ?? 0);
            if ($pid <= 0 || !isset($owned[$pid])) {
                return Result::failure('다른 매뉴얼이거나 존재하지 않는 페이지가 포함되어 있습니다.');
            }
            if (array_key_exists($pid, $parentMap)) {
                return Result::failure('중복된 페이지가 포함되어 있습니다.');
            }
            $parent = isset($node['parent_id']) && $node['parent_id'] !== null && $node['parent_id'] !== ''
                ? (int) $node['parent_id']
                : null;
            if ($parent !== null && !isset($owned[$parent])) {
                return Result::failure('상위 페이지가 같은 매뉴얼에 속하지 않습니다.');
            }
            $parentMap[$pid] = $parent;
            $sortOrders[$pid] = (int) ($node['sort_order'] ?? 0);
        }

        if (count($parentMap) !== count($owned)) {
            return Result::failure('모든 페이지의 트리 정보가 필요합니다.');
        }

        $depths = $this->calculateDepths($parentMap);
        if ($depths === null) {
            return Result::failure('페이지 계층에 순환이 있거나 허용 깊이를 초과합니다.');
        }

        try {
            $this->repository->transaction(function () use ($parentMap, $sortOrders, $depths, $bookId): void {
                foreach ($parentMap as $pid => $parent) {
                    $this->repository->updatePageTreeNode(
                        $pid,
                        $bookId,
                        $parent,
                        $sortOrders[$pid],
                        $depths[$pid]
                    );
                }
            });
        } catch (\Throwable) {
            return Result::failure('목차 순서 저장에 실패했습니다.');
        }

        $this->notifyChanged($domainId, 'page_tree_updated');
        return Result::success('목차 순서가 저장되었습니다.');
    }

    // ─────────────────────────────────────────
    // 내부 헬퍼
    // ─────────────────────────────────────────

    /**
     * 부모 페이지 id 검증 — 비어 있으면 루트, 값이 있으면 같은 책 소속이어야 한다.
     */
    private function validateRequestedParent(int $bookId, mixed $rawParentId): array
    {
        if ($rawParentId === null || $rawParentId === '' || (int) $rawParentId <= 0) {
            return ['valid' => true, 'parent_id' => null];
        }

        $parentId = (int) $rawParentId;
        $parent = $this->repository->findPage($parentId, $bookId);
        return ['valid' => $parent !== null, 'parent_id' => $parent !== null ? $parentId : null];
    }

    /**
     * 부모 기준 깊이 (루트=0)
     */
    private function depthOf(int $bookId, ?int $parentId): int
    {
        if ($parentId === null) {
            return 0;
        }
        $parent = $this->repository->findPage($parentId, $bookId);
        return $parent ? ((int) $parent['depth'] + 1) : 0;
    }

    /**
     * parentMap 기반 깊이 계산 (순환 및 최대 깊이 검증 포함)
     */
    private function calculateDepths(array $parentMap): ?array
    {
        $depths = [];
        $visiting = [];

        $resolve = function (int $pageId) use (&$resolve, &$depths, &$visiting, $parentMap): ?int {
            if (array_key_exists($pageId, $depths)) {
                return $depths[$pageId];
            }
            if (isset($visiting[$pageId])) {
                return null;
            }

            $visiting[$pageId] = true;
            $parentId = $parentMap[$pageId] ?? null;
            if ($parentId !== null && !array_key_exists($parentId, $parentMap)) {
                unset($visiting[$pageId]);
                return null;
            }

            $depth = 0;
            if ($parentId !== null) {
                $parentDepth = $resolve($parentId);
                if ($parentDepth === null) {
                    unset($visiting[$pageId]);
                    return null;
                }
                $depth = $parentDepth + 1;
            }
            unset($visiting[$pageId]);

            if ($depth > 255) {
                return null;
            }
            return $depths[$pageId] = $depth;
        };

        foreach (array_keys($parentMap) as $pageId) {
            if ($resolve((int) $pageId) === null) {
                return null;
            }
        }

        return $depths;
    }

    /**
     * 순환 데이터에도 안전하게 루트와 모든 자손 id를 수집한다.
     */
    private function collectSubtreeIds(array $pages, int $rootId): array
    {
        $children = [];
        foreach ($pages as $page) {
            $parentId = $page['parent_id'] !== null ? (int) $page['parent_id'] : 0;
            $children[$parentId][] = (int) $page['page_id'];
        }

        $result = [];
        $stack = [$rootId];
        $seen = [];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $result[] = $id;
            foreach ($children[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }
        return $result;
    }

    private function deleteUnreferencedPageFiles(int $domainId, int $pageId, string $html): void
    {
        $directory = $this->pageStorageDirectory($domainId, $pageId);
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \DirectoryIterator($directory) as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            if (!str_contains($html, rawurlencode($file->getFilename()))
                && !str_contains($html, $file->getFilename())) {
                @unlink($file->getPathname());
            }
        }
    }

    private function deletePageFiles(int $domainId, int $pageId): void
    {
        $directory = $this->pageStorageDirectory($domainId, $pageId);
        $base = realpath($this->storagePath . '/D' . $domainId . '/manual');
        $realDirectory = realpath($directory);
        if ($base === false || $realDirectory === false) {
            return;
        }

        $base = rtrim($base, '/\\');
        $boundaryBase = DIRECTORY_SEPARATOR === '\\' ? strtolower($base) : $base;
        $boundaryDirectory = DIRECTORY_SEPARATOR === '\\' ? strtolower($realDirectory) : $realDirectory;
        if ($boundaryDirectory !== $boundaryBase
            && !str_starts_with($boundaryDirectory, $boundaryBase . DIRECTORY_SEPARATOR)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($realDirectory);
    }

    private function pageStorageDirectory(int $domainId, int $pageId): string
    {
        return $this->storagePath . '/D' . $domainId . '/manual/' . $pageId;
    }

    private function resolvePageSlug(int $bookId, string $raw, ?int $excludeId): string
    {
        $slug = $this->slugify($raw);

        if ($slug === '') {
            do {
                $slug = bin2hex(random_bytes(4));
            } while ($this->repository->existsPageSlug($bookId, $slug, $excludeId));
            return $slug;
        }

        $base = $slug;
        $i = 2;
        while ($this->repository->existsPageSlug($bookId, $slug, $excludeId)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    /**
     * 슬러그 정규화 — 영문 소문자/숫자/하이픈만 허용. 한글 등은 제거되어 빈 문자열이면 랜덤 폴백.
     */
    private function slugify(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    private function notifyChanged(int $domainId, string $changeType): void
    {
        $this->eventDispatcher?->dispatch(new ManualContentChangedEvent($domainId, $changeType));
    }
}
