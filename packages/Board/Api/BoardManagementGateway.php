<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Api;

use Mublo\Contract\Board\BoardDescriptor;
use Mublo\Contract\Board\BoardManagementInterface;
use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Entity\BoardCategoryMapping;
use Mublo\Packages\Board\Repository\BoardCategoryMappingRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Service\BoardConfigService;

final class BoardManagementGateway implements BoardManagementInterface
{
    /**
     * AI·자동화 경로에서 부분 갱신할 수 있는 명시적 설정 목록. board_configs 컬럼과 매핑 테이블로 저장하는
     * category_ids만 둔다. 비회원 허용은 별도 컬럼이 없고 각 *_level = 0으로 표현한다.
     */
    private const MUTABLE_FIELDS = [
        'group_id', 'board_name', 'board_description',
        'list_level', 'read_level', 'write_level', 'comment_level', 'download_level',
        'daily_write_limit', 'daily_comment_limit',
        'board_skin', 'board_editor', 'notice_count', 'per_page',
        'use_secret', 'is_secret_board', 'use_category', 'use_comment', 'use_reaction',
        'use_link', 'use_file', 'reaction_config',
        'file_count_limit', 'file_size_limit', 'file_extension_allowed',
        'is_active', 'category_ids',
    ];

    public function __construct(
        private BoardConfigService $service,
        private BoardConfigRepository $boards,
        private BoardCategoryMappingRepository $categoryMappings
    ) {
    }

    public function listBoards(int $domainId): array
    {
        return array_map(
            fn (BoardConfig $board): BoardDescriptor => $this->describe($board),
            $this->boards->findByDomain($domainId)
        );
    }

    public function getBoard(int $domainId, int $boardId): ?BoardDescriptor
    {
        $board = $this->boards->find($boardId);
        return $board !== null && $board->getDomainId() === $domainId
            ? $this->describe($board)
            : null;
    }

    public function findBoardBySlug(int $domainId, string $slug): ?BoardDescriptor
    {
        $board = $this->boards->findBySlug($domainId, $slug);
        return $board !== null ? $this->describe($board) : null;
    }

    public function updateBoard(
        int $domainId,
        int $boardId,
        array $values,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        if ($this->getBoard($domainId, $boardId) === null) {
            return Result::failure('게시판을 찾을 수 없습니다.');
        }
        $allowed = array_intersect_key($values, array_flip(self::MUTABLE_FIELDS));
        if ($allowed === []) {
            return Result::failure('수정할 수 있는 게시판 설정이 없습니다.');
        }
        return $this->service->updateBoard($boardId, $allowed, $mutationContext);
    }

    public function removeBoard(
        int $domainId,
        int $boardId,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        if ($this->getBoard($domainId, $boardId) === null) {
            return Result::failure('게시판을 찾을 수 없습니다.');
        }
        return $this->service->deleteBoard($boardId, $mutationContext);
    }

    private function describe(BoardConfig $board): BoardDescriptor
    {
        $categoryIds = array_map(
            static fn (BoardCategoryMapping $mapping): int => $mapping->getCategoryId(),
            $this->categoryMappings->findByBoard($board->getBoardId())
        );
        return new BoardDescriptor(
            $board->getBoardId(),
            $board->getBoardSlug(),
            $board->getBoardName(),
            $board->getGroupId(),
            $board->isActive(),
            $this->boards->getArticleCount($board->getBoardId()),
            $board->toArray(),
            $categoryIds
        );
    }
}
